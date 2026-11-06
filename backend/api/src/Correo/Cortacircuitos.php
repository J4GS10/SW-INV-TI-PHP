<?php

declare(strict_types=1);

namespace App\Correo;

use App\Seguridad\CacheAuth;
use Psr\Log\LoggerInterface;

/**
 * Cortacircuitos de autenticación de integraciones ('smtp' | 'ad'): tras un
 * fallo de credenciales se pausan los intentos 15 minutos. Sin esto, los
 * reintentos repetirían la clave mala hasta bloquear la cuenta de servicio en
 * el directorio. Estado en Redis, compartido por la API y el
 * worker (`breaker:{tipo}` = epoch de fin, como el backend anterior) y, sin Redis, en memoria del proceso.
 */
final class Cortacircuitos
{
    public const int MINUTOS = 15;

    /** @var array<string, float> */
    private array $local = [];

    public function __construct(private readonly CacheAuth $redis, private readonly LoggerInterface $logger)
    {
    }

    public function disparar(string $tipo, string $motivo): void
    {
        $hasta = microtime(true) + self::MINUTOS * 60;
        $this->local[$tipo] = $hasta;
        $this->logger->error('integracion.cortacircuitos_disparado', [
            'tipo' => $tipo, 'minutos' => self::MINUTOS, 'motivo' => mb_substr($motivo, 0, 150),
        ]);
        $this->redis->guardarTexto("breaker:{$tipo}", (string) $hasta, self::MINUTOS * 60);
    }

    /** Fin de la pausa (epoch) o null si no hay pausa vigente. */
    public function pausadoHasta(string $tipo): ?float
    {
        $hasta = max($this->local[$tipo] ?? 0.0, (float) ($this->redis->obtenerTexto("breaker:{$tipo}") ?? 0));

        return $hasta > microtime(true) ? $hasta : null;
    }

    public function reiniciar(string ...$tipos): void
    {
        $tipos = [] === $tipos ? ['smtp', 'ad'] : $tipos;
        foreach ($tipos as $tipo) {
            unset($this->local[$tipo]);
        }
        $this->redis->borrar(...array_map(static fn (string $t): string => "breaker:{$t}", $tipos));
    }
}
