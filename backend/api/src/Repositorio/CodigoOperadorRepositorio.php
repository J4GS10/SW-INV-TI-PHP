<?php

declare(strict_types=1);

namespace App\Repositorio;

use App\Dominio\Reloj;
use Doctrine\DBAL\Connection;

/** Operator codes (INV_USUARIO.USU_Codigo_*): only the hash is stored; attempts are counted atomically. */
final readonly class CodigoOperadorRepositorio
{
    public function __construct(private Connection $conexion)
    {
    }

    /** @return array{hash: ?string, intentos: int, bloqueado_hasta: ?\DateTimeImmutable, definido_en: ?\DateTimeImmutable}|null */
    public function estado(string $usuario): ?array
    {
        $f = $this->conexion->fetchAssociative('SELECT "USU_Codigo_Operador_Hash", "USU_Codigo_Intentos", "USU_Codigo_Bloqueado_Hasta", '
            .'"USU_Codigo_Operador_En" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $usuario]);
        if (false === $f) {
            return null;
        }

        return [
            'hash' => null === $f['USU_Codigo_Operador_Hash'] ? null : (string) $f['USU_Codigo_Operador_Hash'],
            'intentos' => (int) $f['USU_Codigo_Intentos'],
            'bloqueado_hasta' => Reloj::desdeBd(null === $f['USU_Codigo_Bloqueado_Hasta'] ? null : (string) $f['USU_Codigo_Bloqueado_Hasta']),
            'definido_en' => Reloj::desdeBd(null === $f['USU_Codigo_Operador_En'] ? null : (string) $f['USU_Codigo_Operador_En']),
        ];
    }

    public function guardar(string $usuario, ?string $hash): void
    {
        $this->conexion->executeStatement('UPDATE "INV_USUARIO" SET "USU_Codigo_Operador_Hash" = :h, "USU_Codigo_Operador_En" = :en, '
            .'"USU_Codigo_Intentos" = 0, "USU_Codigo_Bloqueado_Hasta" = NULL WHERE "USU_Usuario" = :u',
            ['h' => $hash, 'en' => null === $hash ? null : Reloj::aBd(Reloj::ahora()), 'u' => $usuario]);
    }

    /** A failed attempt; at `maximo` the code is locked until `hasta`. Returns the attempts so far. */
    public function fallo(string $usuario, int $maximo, \DateTimeImmutable $hasta): int
    {
        // Dos sentencias: dentro de un CASE, Oracle no convierte el texto de la fecha a TIMESTAMP (ORA-00932).
        $this->conexion->executeStatement('UPDATE "INV_USUARIO" SET "USU_Codigo_Intentos" = "USU_Codigo_Intentos" + 1 WHERE "USU_Usuario" = :u',
            ['u' => $usuario]);
        $this->conexion->executeStatement('UPDATE "INV_USUARIO" SET "USU_Codigo_Bloqueado_Hasta" = :hasta WHERE "USU_Usuario" = :u '
            .'AND "USU_Codigo_Intentos" >= :max', ['max' => $maximo, 'hasta' => Reloj::aBd($hasta), 'u' => $usuario]);

        return (int) $this->conexion->fetchOne('SELECT "USU_Codigo_Intentos" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $usuario]);
    }

    public function exito(string $usuario): void
    {
        $this->conexion->executeStatement('UPDATE "INV_USUARIO" SET "USU_Codigo_Intentos" = 0, "USU_Codigo_Bloqueado_Hasta" = NULL '
            .'WHERE "USU_Usuario" = :u AND ("USU_Codigo_Intentos" > 0 OR "USU_Codigo_Bloqueado_Hasta" IS NOT NULL)', ['u' => $usuario]);
    }
}
