<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\Ajustes;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\RedisStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Límites por IP con la sintaxis del backend anterior ("5/minute"). Ventana fija,
 * contadores en Redis si está configurado (compartidos entre réplicas) o en
 * disco local.
 *
 * Cada consumo se hace bajo un cerrojo por contador (Redis o archivo): sin él, las
 * peticiones simultáneas leían y guardaban el mismo contador y una ráfaga pasaba
 * de largo el límite (fase 7, hallazgo F-09: 7 de 40 inicios de sesión con límite 5).
 */
final class Limitador
{
    /** Tiempos de conexión y lectura con Redis (s): un Redis caído no retiene la petición (F-10). */
    private const array OPCIONES_REDIS = ['lazy' => true, 'timeout' => 1, 'read_timeout' => 1, 'retry_interval' => 100];
    /** Tras una falla de Redis, segundos en que se usan directamente los contadores locales. */
    private const float PAUSA_REDIS = 5.0;

    /** Corte compartido por el proceso (el worker de FrankenPHP atiende muchas peticiones). */
    private static float $redisCaidoHasta = 0.0;

    /** @var array<string, RateLimiterFactory> */
    private array $fabricas = [];
    private ?CacheStorage $almacen = null;
    private ?LockFactory $cerrojos = null;
    private ?CacheStorage $almacenLocal = null;
    private ?LockFactory $cerrojosLocales = null;

    public function __construct(
        private readonly Ajustes $ajustes,
        private readonly string $directorioCache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Lanza 429 RATE_LIMIT_EXCEEDED si `$clave` agotó el límite `$regla`. Con Redis caído degrada a
     * contadores locales (siguen limitando, por réplica) en vez de responder 500 (fase 8, F-10).
     */
    public function consumir(string $nombre, string $regla, string $clave): void
    {
        if (null !== $this->ajustes->redisUrl && microtime(true) >= self::$redisCaidoHasta) {
            try {
                $this->aplicar($this->fabrica($nombre, $regla, false), $clave);

                return;
            } catch (ErrorApi $e) {
                throw $e;
            } catch (\Throwable $e) {
                self::$redisCaidoHasta = microtime(true) + self::PAUSA_REDIS;
                $this->logger?->warning('limiter.degradado', ['limite' => $nombre, 'error' => $e->getMessage()]);
            }
        }
        $this->aplicar($this->fabrica($nombre, $regla, null !== $this->ajustes->redisUrl), $clave);
    }

    private function fabrica(string $nombre, string $regla, bool $local): RateLimiterFactory
    {
        return $this->fabricas[($local ? 'local:' : '').$nombre] ??= new RateLimiterFactory(
            ['id' => $nombre, 'policy' => 'fixed_window', ...self::interpretar($regla)],
            $this->almacen($local),
            $this->cerrojos($local),
        );
    }

    private function aplicar(RateLimiterFactory $fabrica, string $clave): void
    {
        if (!$fabrica->create($clave)->consume()->isAccepted()) {
            throw new ErrorApi(429, 'RATE_LIMIT_EXCEEDED');
        }
    }

    /** @return array{limit: int, interval: string} */
    public static function interpretar(string $regla): array
    {
        if (1 !== preg_match('#^\s*(\d+)\s*(?:/|per)\s*(\d+)?\s*(second|minute|hour|day)s?\s*$#i', $regla, $m)) {
            throw new \InvalidArgumentException("Límite no reconocido: {$regla}");
        }
        $cantidad = '' === $m[2] ? 1 : (int) $m[2];

        return ['limit' => (int) $m[1], 'interval' => "{$cantidad} ".strtolower($m[3])];
    }

    /** Contadores en Redis (entre réplicas) o, si no hay Redis o `$local`, en disco del contenedor. */
    private function almacen(bool $local = false): CacheStorage
    {
        if ($local || null === $this->ajustes->redisUrl) {
            return $this->almacenLocal ??= new CacheStorage(new FilesystemAdapter('limite', 0, $this->directorioCache));
        }

        return $this->almacen ??= new CacheStorage(new RedisAdapter(RedisAdapter::createConnection($this->ajustes->redisUrl, self::OPCIONES_REDIS), 'limite'));
    }

    /**
     * Cerrojos donde viven los contadores: Redis (entre réplicas) o archivos locales. Vida de 2 s (un
     * consumo tarda milisegundos): con la de Symfony (300 s), un proceso que moría con el cerrojo tomado
     * dejaba ese contador —p. ej. el inicio de sesión de una IP— bloqueado hasta cinco minutos.
     */
    private function cerrojos(bool $local = false): LockFactory
    {
        if ($local || null === $this->ajustes->redisUrl) {
            $directorio = $this->directorioCache.'/cerrojos_limite';
            if (!is_dir($directorio)) {
                @mkdir($directorio, 0o775, true);
            }

            return $this->cerrojosLocales ??= self::fabricaDeVidaCorta(new FlockStore($directorio));
        }

        return $this->cerrojos ??= self::fabricaDeVidaCorta(new RedisStore(RedisAdapter::createConnection($this->ajustes->redisUrl, self::OPCIONES_REDIS)));
    }

    private static function fabricaDeVidaCorta(PersistingStoreInterface $almacen): LockFactory
    {
        return new class($almacen) extends LockFactory {
            public function createLock(string $resource, ?float $ttl = 2.0, bool $autoRelease = true): SharedLockInterface
            {
                return parent::createLock($resource, $ttl, $autoRelease);
            }
        };
    }
}
