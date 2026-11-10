<?php

declare(strict_types=1);

namespace App\Tests\Resistencia;

use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Seguridad\Tokens;
use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Mailpit;
use App\Tests\Soporte\PeticionesSimultaneas;
use App\Tests\Soporte\SambaAd as AD;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fase 5 contra la imagen de PRODUCCIÓN: sincronizaciones con el AD a la vez,
 * carga sobre /directorio, /health/full y /metrics, y la cola de correos
 * drenada por varios contenedores worker (uno se reinicia a mitad de la prueba:
 * herramientas/resistencia.sh).
 */
#[Group('resistencia')]
final class IntegracionesResistenciaTest extends KernelTestCase
{
    use PeticionesSimultaneas;

    private string $url;
    private string $token = '';

    protected function setUp(): void
    {
        $this->url = (string) getenv('RESISTENCIA_URL');
        if ('' === $this->url) {
            self::markTestSkipped('RESISTENCIA_URL no definida (usar herramientas/resistencia.sh).');
        }
        self::bootKernel();
    }

    public function testSincronizacionesSimultaneasNoDuplicanPersonas(): void
    {
        AD::retirarPersonas();
        AD::configurar();
        // La fila se escribió directo en la base: cada proceso de la API la ve al vencer su caché (30 s).
        sleep(\App\Integracion\FuenteIntegracion::CACHE_SEGUNDOS + 1);
        $r = $this->rafaga(array_map(fn (): array => ['POST', '/api/v1/directorio/sincronizar', null, $this->nuevoToken('SUPER_ADMIN')], range(1, 6)));
        $estados = self::porEstado($r);
        self::assertSame([], array_diff(array_keys($estados), [200, 409]), json_encode(array_column($r, 'cuerpo'), \JSON_THROW_ON_ERROR));
        self::assertGreaterThanOrEqual(1, $estados[200] ?? 0);
        foreach ([AD::JEFE, AD::ANA] as $correo) {
            self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_PERSONA" WHERE LOWER("PER_Email_Corporativo") = :c', ['c' => $correo]), $correo);
        }
        self::assertSame(1, D::contar("SELECT COUNT(*) FROM \"INV_DEPARTAMENTO\" WHERE LOWER(\"DEP_Nombre\") = 'tecnología ad'"));
    }

    public function testCargaSobreDirectorioSaludYMetricas(): void
    {
        AD::configurar(['INT_AD_Grupo_Admin' => 'Inventario-Admins']);
        sleep(\App\Integracion\FuenteIntegracion::CACHE_SEGUNDOS + 1);
        $super = $this->nuevoToken('SUPER_ADMIN');
        $rutas = ['/api/v1/directorio/estado', '/api/v1/directorio/notificaciones/reglas', '/api/v1/directorio/integracion',
            '/api/v1/directorio/grupos?q=a', '/api/v1/directorio/notificaciones/vista-previa/stock_bajo', '/health/full', '/metrics'];
        $peticiones = [];
        for ($i = 0; $i < 210; ++$i) {
            $peticiones[] = ['GET', $rutas[$i % \count($rutas)], null, $super];
        }
        $inicio = microtime(true);
        $resultados = [];
        foreach (array_chunk($peticiones, 35) as $lote) {
            array_push($resultados, ...$this->rafaga($lote));
        }
        $segundos = microtime(true) - $inicio;
        self::assertSame([200 => 210], self::porEstado($resultados), json_encode(array_values(array_map(
            static fn (array $r): array => ['estado' => $r['estado'], 'ruta' => $r['ruta'], 'error' => $r['error'], 'ms' => (int) $r['ms']],
            array_filter($resultados, static fn (array $r): bool => 200 !== $r['estado']))), \JSON_THROW_ON_ERROR));
        $tiempos = array_column($resultados, 'ms');
        sort($tiempos);
        fwrite(\STDERR, \sprintf("\n  carga fase 5: 210 peticiones en %.1f s (%.0f req/s), p95 %.0f ms\n", $segundos, 210 / $segundos, $tiempos[(int) (0.95 * \count($tiempos))]));
        D::conexion()->executeStatement('DELETE FROM "SYS_INTEGRACION_CORREO"');
    }

    public function testVariosWorkersDrenanLaColaSinPerderNiDuplicarCorreos(): void
    {
        $marca = D::sufijo();
        $destinos = [];
        $ahora = Reloj::aBd(Reloj::ahora());
        for ($i = 0; $i < 150; ++$i) {
            $destinos[] = "cola{$i}-{$marca}@pruebas.local";
            D::conexion()->executeStatement(
                'INSERT INTO "SYS_EMAIL_OUTBOX" ("EOB_Id", "EOB_Plantilla", "EOB_Asunto", "EOB_Html", "EOB_Para", "EOB_Cc_Admins", "EOB_Resolver", '
                ."\"EOB_Estado\", \"EOB_Intentos\", \"EOB_Proximo_Intento\", \"EOB_Creado_En\") VALUES (:id, 'resistencia', :asunto, '<p>Carga</p>', :para, 0, 0, 'PENDIENTE', 0, :a, :a)",
                ['id' => Ident::nuevo(), 'asunto' => "Resistencia {$marca}", 'para' => json_encode([end($destinos)]), 'a' => $ahora],
            );
        }
        // Los workers (contenedores aparte) lo toman en su siguiente sondeo; uno se reinicia en medio.
        $limite = microtime(true) + 150;
        do {
            sleep(3);
            $pendientes = D::contar("SELECT COUNT(*) FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Asunto\" = :a AND \"EOB_Estado\" <> 'ENVIADO'", ['a' => "Resistencia {$marca}"]);
        } while ($pendientes > 0 && microtime(true) < $limite);
        self::assertSame(0, $pendientes, 'Todos entregados.');
        $duplicados = [];
        foreach ($destinos as $destino) {
            $n = \count(Mailpit::para($destino));
            self::assertGreaterThanOrEqual(1, $n, $destino);
            if ($n > 1) {
                $duplicados[] = $destino;
            }
        }
        self::assertSame([], $duplicados, 'Ningún correo se envió dos veces.');
    }

    private function nuevoToken(string $rol): string
    {
        return static::getContainer()->get(Tokens::class)->crearAcceso(D::crearUsuario(['rol' => $rol, 'global' => true])['username'], 'x');
    }
}
