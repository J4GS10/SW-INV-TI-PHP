<?php

declare(strict_types=1);

namespace App\Db;

use App\Dominio\Reloj;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

/** The idempotency keys as stored (SYS_IDEMPOTENCY_KEY): who reserved each one and the answer it got. */
final readonly class ClavesIdempotencia
{
    public function __construct(private Connection $conexion)
    {
    }

    /** @return array<string, mixed>|false IDK_Endpoint, IDK_Usuario, IDK_Request_Hash, IDK_Estado, IDK_Response_Status, IDK_Response_Body, IDK_Creada_En */
    public function buscar(string $clave): array|false
    {
        return $this->conexion->fetchAssociative(
            'SELECT "IDK_Endpoint", "IDK_Usuario", "IDK_Request_Hash", "IDK_Estado", "IDK_Response_Status", '
            .'"IDK_Response_Body", "IDK_Creada_En" FROM "SYS_IDEMPOTENCY_KEY" WHERE "IDK_Key" = :k',
            ['k' => $clave],
        );
    }

    /** Reserves the key for a request in progress; false when another request reserved it first. */
    public function reservar(string $clave, string $ruta, string $usuario, string $hash): bool
    {
        try {
            $this->conexion->executeStatement(
                'INSERT INTO "SYS_IDEMPOTENCY_KEY" ("IDK_Key", "IDK_Endpoint", "IDK_Usuario", "IDK_Request_Hash", '
                ."\"IDK_Estado\", \"IDK_Response_Status\", \"IDK_Creada_En\") VALUES (:k, :ruta, :u, :h, 'PENDING', 202, :ahora)",
                ['k' => $clave, 'ruta' => $ruta, 'u' => $usuario, 'h' => $hash, 'ahora' => Reloj::aBd(Reloj::ahora())],
            );

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** Keeps the answer of the request; `$cuerpo` is its JSON, or null when it was not JSON (a file). */
    public function completar(string $clave, string $usuario, int $estado, ?string $cuerpo): void
    {
        $this->conexion->executeStatement(
            "UPDATE \"SYS_IDEMPOTENCY_KEY\" SET \"IDK_Estado\" = 'COMPLETED', \"IDK_Response_Status\" = :estado, "
            .'"IDK_Response_Body" = :cuerpo, "IDK_Creada_En" = :ahora WHERE "IDK_Key" = :k AND "IDK_Usuario" = :u',
            ['estado' => $estado, 'cuerpo' => $cuerpo, 'ahora' => Reloj::aBd(Reloj::ahora()), 'k' => $clave, 'u' => $usuario],
            ['cuerpo' => ParameterType::LARGE_OBJECT],
        );
    }

    /** Drops the reservation of a command that failed without confirming anything: it can be retried. */
    public function liberar(string $clave, string $usuario): void
    {
        $this->conexion->executeStatement(
            "DELETE FROM \"SYS_IDEMPOTENCY_KEY\" WHERE \"IDK_Key\" = :k AND \"IDK_Estado\" = 'PENDING' AND \"IDK_Usuario\" = :u",
            ['k' => $clave, 'u' => $usuario],
        );
    }

    /**
     * Takes over a pending reservation older than `$limite` with a conditional update: a single request wins it
     * (after the first update, the second one no longer meets the age condition).
     */
    public function retomar(string $clave, \DateTimeImmutable $limite): bool
    {
        return 1 === $this->conexion->executeStatement(
            "UPDATE \"SYS_IDEMPOTENCY_KEY\" SET \"IDK_Creada_En\" = :ahora WHERE \"IDK_Key\" = :k AND \"IDK_Estado\" = 'PENDING' AND \"IDK_Creada_En\" < :limite",
            ['k' => $clave, 'limite' => Reloj::aBd($limite), 'ahora' => Reloj::aBd(Reloj::ahora())],
        );
    }
}
