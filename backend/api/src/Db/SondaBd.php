<?php

declare(strict_types=1);

namespace App\Db;

use App\Correo\Infrastructure\ColaCorreos;
use Doctrine\DBAL\Connection;

/**
 * What the health probes ask the database. In worker mode the connection outlives the request:
 * when a probe finds it broken it is discarded, so the next request opens a new one.
 */
final readonly class SondaBd
{
    public function __construct(private Connection $conexion, private ColaCorreos $cola)
    {
    }

    /** @return ?string null when the database answers; otherwise why it did not */
    public function fallo(): ?string
    {
        try {
            $this->conexion->fetchOne('SELECT 1 FROM DUAL');

            return null;
        } catch (\Throwable $e) {
            $this->conexion->close();

            return $e->getMessage();
        }
    }

    /**
     * @return array{activos: int, usuarios: int, revocados: int, cola: array<string, int>} assets, active accounts, revoked tokens awaiting purge and queued e-mails by state
     *
     * @throws \Throwable when the database does not answer (the connection is already discarded)
     */
    public function conteos(): array
    {
        try {
            return [
                'activos' => (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_ACTIVO"'),
                'usuarios' => (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_USUARIO" WHERE "USU_Estado" = 1'),
                'revocados' => (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "SYS_TOKEN_REVOCADO"'),
                'cola' => $this->cola->conteosPorEstado(),
            ];
        } catch (\Throwable $e) {
            $this->conexion->close();
            throw $e;
        }
    }
}
