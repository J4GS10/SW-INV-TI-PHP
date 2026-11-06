<?php

declare(strict_types=1);

namespace App\Integracion;

use Psr\Log\LoggerInterface;

/**
 * Configuración EFECTIVA de correo y Active Directory, en orden de prioridad:
 *  1. SYS_INTEGRACION_CORREO (fila única editada desde la interfaz);
 *  2. variables de entorno SMTP_* / AD_*.
 *
 * Se cachea 30 s por proceso. Al guardar desde la API, el proceso que atiende
 * la petición actualiza su caché al instante (escribir()); los demás la ven en
 * ≤ 30 s. Nunca lanza: ante un error de la base usa el último valor conocido o
 * el entorno.
 */
final class FuenteIntegracion
{
    public const int CACHE_SEGUNDOS = 30;

    /** @var array{0: float, 1: array<string, mixed>|null}|null [vence, valores] */
    private ?array $cache = null;

    public function __construct(
        private readonly RepositorioIntegracion $repositorio,
        private readonly FabricaConfigIntegracion $fabrica,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function obtener(): ConfigIntegracion
    {
        $valores = $this->valores();

        return null === $valores ? $this->fabrica->desdeEntorno() : $this->fabrica->desdeValores($valores);
    }

    /**
     * Escritura directa tras guardar desde la API.
     *
     * @param array<string, mixed>|null $valores
     */
    public function escribir(?array $valores): void
    {
        $this->cache = [microtime(true) + self::CACHE_SEGUNDOS, $valores];
    }

    public function invalidar(): void
    {
        $this->cache = null;
    }

    /** @return array<string, mixed>|null */
    private function valores(): ?array
    {
        if (null !== $this->cache && $this->cache[0] > microtime(true)) {
            return $this->cache[1];
        }
        try {
            $valores = $this->repositorio->valores();
        } catch (\Throwable $e) {
            $this->logger->warning('integracion.carga_fallida', ['error' => mb_substr($e->getMessage(), 0, 150)]);

            return $this->cache[1] ?? null;
        }
        $this->escribir($valores);

        return $valores;
    }
}
