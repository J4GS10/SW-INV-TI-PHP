<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Config\Ajustes;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * Caché de autenticación en Redis con las MISMAS claves y el mismo JSON que
 * el backend anterior (`auth:jti:{jti}`, `auth:sid:{sid}`): durante la ventana de
 * reversión un logout o un cierre de sesión vale en los dos backends.
 * Sin REDIS_URL todo es no-op (equivale a "sin caché").
 */
final class CacheAuth
{
    private ?\Redis $redis = null;
    private bool $intentado = false;

    public function __construct(private readonly Ajustes $ajustes, private readonly LoggerInterface $logger)
    {
    }

    /** @return array<string, mixed>|null */
    public function obtener(string $clave): ?array
    {
        $r = $this->cliente();
        if (null === $r) {
            return null;
        }
        try {
            $crudo = $r->get($clave);
            $valor = \is_string($crudo) ? json_decode($crudo, true) : null;

            return \is_array($valor) ? $valor : null;
        } catch (\Throwable $e) {
            $this->logger->warning('cache.get_failed', ['key' => $clave, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** @param array<string, mixed> $valor */
    public function guardar(string $clave, array $valor, int $segundos): void
    {
        $r = $this->cliente();
        if (null === $r) {
            return;
        }
        try {
            $r->set($clave, json_encode($valor, \JSON_THROW_ON_ERROR), ['ex' => $segundos]);
        } catch (\Throwable $e) {
            $this->logger->warning('cache.set_failed', ['key' => $clave, 'error' => $e->getMessage()]);
        }
    }

    /** Valor de texto sin JSON (p. ej. `breaker:smtp`, que guarda un epoch). */
    public function obtenerTexto(string $clave): ?string
    {
        $r = $this->cliente();
        if (null === $r) {
            return null;
        }
        try {
            $valor = $r->get($clave);

            return \is_string($valor) ? $valor : null;
        } catch (\Throwable $e) {
            $this->logger->warning('cache.get_failed', ['key' => $clave, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function guardarTexto(string $clave, string $valor, int $segundos): void
    {
        $r = $this->cliente();
        if (null === $r) {
            return;
        }
        try {
            $r->set($clave, $valor, ['ex' => $segundos]);
        } catch (\Throwable $e) {
            $this->logger->warning('cache.set_failed', ['key' => $clave, 'error' => $e->getMessage()]);
        }
    }

    /**
     * SET clave valor NX EX segundos (atómico). null sin Redis configurado; a
     * diferencia del resto de métodos, un fallo de Redis se propaga: quien
     * pide un cerrojo no debe suponer que lo obtuvo.
     *
     * @throws \RedisException
     */
    public function fijarSiNoExiste(string $clave, string $valor, int $segundos): ?bool
    {
        $r = $this->cliente();
        if (null === $r) {
            return null;
        }

        return true === $r->set($clave, $valor, ['nx', 'ex' => $segundos]);
    }

    public function borrar(string ...$claves): void
    {
        $r = $this->cliente();
        if (null === $r || [] === $claves) {
            return;
        }
        try {
            $r->del($claves);
        } catch (\Throwable $e) {
            $this->logger->warning('cache.delete_failed', ['error' => $e->getMessage()]);
        }
    }

    public function disponible(): ?bool
    {
        $r = $this->cliente();
        if (null === $r) {
            return null;
        }
        try {
            return false !== $r->ping();
        } catch (\Throwable) {
            return false;
        }
    }

    private function cliente(): ?\Redis
    {
        if (null === $this->ajustes->redisUrl) {
            return null;
        }
        if (!$this->intentado) {
            $this->intentado = true;
            try {
                // read_timeout: un Redis que acepta la conexión pero no responde tampoco retiene la petición (F-10).
                $conexion = RedisAdapter::createConnection($this->ajustes->redisUrl, ['lazy' => true, 'timeout' => 2, 'read_timeout' => 2]);
                $this->redis = $conexion instanceof \Redis ? $conexion : null;
            } catch (\Throwable $e) {
                $this->logger->warning('cache.redis_unavailable', ['error' => $e->getMessage()]);
            }
        }

        return $this->redis;
    }
}
