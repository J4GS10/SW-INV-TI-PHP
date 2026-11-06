<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Config\Ajustes;
use App\Http\Limitador;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

/**
 * Fase 7 (regresión de la corrección de F-09): el cerrojo de cada contador de límite se crea con vida
 * corta. Con la vida por defecto de Symfony (300 s), un proceso que moría con el cerrojo tomado
 * (reinicio, tiempo máximo) dejaba bloqueado ese contador —p. ej. el inicio de sesión de una IP—
 * hasta cinco minutos. Usa el Redis de pruebas real (herramientas/dobles_pruebas.sh).
 */
final class LimitadorCerrojoTest extends TestCase
{
    protected function tearDown(): void
    {
        // El corte de Redis es estático (vale para todo el proceso): no se arrastra a otras pruebas.
        (new \ReflectionProperty(Limitador::class, 'redisCaidoHasta'))->setValue(null, 0.0);
    }

    private function redis(): string
    {
        $url = (string) ($_SERVER['REDIS_PRUEBAS_URL'] ?? $_ENV['REDIS_PRUEBAS_URL'] ?? '');
        if ('' === $url) {
            self::markTestSkipped('REDIS_PRUEBAS_URL no definida.');
        }

        return $url;
    }

    public function testUnCerrojoHuerfanoNoBloqueaElContadorMasDeDosSegundos(): void
    {
        $url = $this->redis();
        $limitador = new Limitador(Ajustes::desdeEntorno(['SECRET_KEY' => str_repeat('k', 40), 'REDIS_URL' => $url]), sys_get_temp_dir());
        $clave = 'huerfano-'.bin2hex(random_bytes(4));
        $limitador->consumir('prueba_cerrojo', '100/minute', $clave);

        // Otro proceso tomó el cerrojo del mismo contador y murió sin soltarlo (se simula sin liberar).
        $ajeno = new LockFactory(new RedisStore(RedisAdapter::createConnection($url)));
        $huerfano = $ajeno->createLock('prueba_cerrojo-'.$clave, 2.0, false);
        self::assertTrue($huerfano->acquire());

        $inicio = microtime(true);
        $limitador->consumir('prueba_cerrojo', '100/minute', $clave);
        self::assertLessThan(4.0, microtime(true) - $inicio, 'El contador no queda bloqueado por el cerrojo de un proceso muerto.');
    }

    /**
     * Fase 8 (F-10): con Redis caído el límite no tumba la API (antes: 500 tras ~30 s por petición).
     * Degrada a contadores locales, que siguen limitando, y no reintenta Redis en cada petición.
     */
    public function testConRedisCaidoDegradaRapidoALimitesLocalesQueSiguenLimitando(): void
    {
        $limitador = new Limitador(Ajustes::desdeEntorno(['SECRET_KEY' => str_repeat('k', 40), 'REDIS_URL' => 'redis://10.255.255.1:6379']),
            sys_get_temp_dir().'/limites-'.bin2hex(random_bytes(4)));
        $clave = 'caido-'.bin2hex(random_bytes(4));
        $inicio = microtime(true);
        $limitador->consumir('prueba_caido', '2/minute', $clave);
        $limitador->consumir('prueba_caido', '2/minute', $clave);
        try {
            $limitador->consumir('prueba_caido', '2/minute', $clave);
            self::fail('El tercer intento debía superar el límite aun sin Redis.');
        } catch (\App\Http\ErrorApi $e) {
            self::assertSame(429, $e->estado);
        }
        self::assertLessThan(4.0, microtime(true) - $inicio, 'Un Redis inalcanzable cuesta como mucho un tiempo de conexión corto, una vez.');
    }

    public function testLosCerrojosDelLimitadorVivenPocosSegundos(): void
    {
        $limitador = new Limitador(Ajustes::desdeEntorno(['SECRET_KEY' => str_repeat('k', 40), 'REDIS_URL' => $this->redis()]), sys_get_temp_dir());
        $fabrica = (new \ReflectionMethod($limitador, 'cerrojos'))->invoke($limitador);
        self::assertInstanceOf(LockFactory::class, $fabrica);
        $cerrojo = $fabrica->createLock('prueba_vida-'.bin2hex(random_bytes(4)));
        self::assertTrue($cerrojo->acquire());
        self::assertLessThanOrEqual(2.0, (float) $cerrojo->getRemainingLifetime());
        $cerrojo->release();
    }
}
