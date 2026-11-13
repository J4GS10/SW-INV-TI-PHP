<?php

declare(strict_types=1);

namespace App\Tareas\Infrastructure;

use App\Dominio\Reloj;
use App\Dominio\Roles;
use Doctrine\DBAL\Connection;

/** What the periodic security maintenance reads and writes: sessions, inactive accounts and the audit log retention. */
final readonly class MantenimientoStore
{
    public function __construct(private Connection $conexion)
    {
    }

    /** Closes the open sessions whose absolute life ended; returns how many. */
    public function cerrarSesionesVencidas(\DateTimeImmutable $ahora): int
    {
        return (int) $this->conexion->executeStatement(
            "UPDATE \"SYS_SESION\" SET \"SES_Cerrada_En\" = :ahora, \"SES_Motivo_Cierre\" = 'expirada' "
            .'WHERE "SES_Cerrada_En" IS NULL AND "SES_Expira" <= :ahora', ['ahora' => Reloj::aBd($ahora)]);
    }

    /** Removes the session history created before `$limite`; returns how many rows. */
    public function purgarSesionesAnterioresA(\DateTimeImmutable $limite): int
    {
        return (int) $this->conexion->executeStatement('DELETE FROM "SYS_SESION" WHERE "SES_Creada_En" < :limite', ['limite' => Reloj::aBd($limite)]);
    }

    /**
     * Active accounts that have not signed in (or, if never, were created) since before `$limite`.
     *
     * @return list<array<string, mixed>> USU_Usuario, USU_Username, USU_Rol, USU_Ultimo_Login, PER_Primer_Nombre, PER_Primer_Apellido, PER_Email_Corporativo
     */
    public function cuentasSinIngresoDesde(\DateTimeImmutable $limite): array
    {
        return $this->conexion->fetchAllAssociative(
            'SELECT u."USU_Usuario", u."USU_Username", u."USU_Rol", u."USU_Ultimo_Login", p."PER_Primer_Nombre", '
            .'p."PER_Primer_Apellido", p."PER_Email_Corporativo" FROM "INV_USUARIO" u '
            .'LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = u."PER_Persona" '
            .'WHERE u."USU_Estado" = 1 AND COALESCE(u."USU_Ultimo_Login", u."USU_Creado_En") < :limite',
            ['limite' => Reloj::aBd($limite)],
        );
    }

    public function desactivarCuenta(string $usuario): void
    {
        $this->conexion->executeStatement('UPDATE "INV_USUARIO" SET "USU_Estado" = 0 WHERE "USU_Usuario" = :u', ['u' => $usuario]);
    }

    /**
     * Removes the audit events older than `$corte`; returns how many. The append-only trigger only lets
     * them go in PURGA mode, which lasts the Oracle session: it is always cleared afterwards.
     */
    public function purgarBitacoraAnteriorA(\DateTimeImmutable $corte): int
    {
        $this->conexion->executeStatement("BEGIN INV_CTX.modo('PURGA'); END;");
        try {
            return (int) $this->conexion->executeStatement('DELETE FROM "INV_AUDITORIA_SISTEMA" WHERE "AUD_Fecha_Hora" < :corte', ['corte' => Reloj::aBd($corte)]);
        } finally {
            $this->conexion->executeStatement('BEGIN INV_CTX.limpiar; END;');
        }
    }
}
