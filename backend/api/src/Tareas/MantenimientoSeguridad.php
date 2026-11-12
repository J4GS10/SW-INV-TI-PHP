<?php

declare(strict_types=1);

namespace App\Tareas;

use App\Tareas\Infrastructure\PurgaSeguridad;
use App\Config\Ajustes;
use App\Correo\Notificador;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Organizacion\Identidades;
use App\Repositorio\GobiernoRepositorio;
use Doctrine\DBAL\Connection;

/**
 * Mantenimiento periódico de seguridad (tarea del sistema, sin alcance por sede):
 *
 * - Sesiones: cierra las que agotaron su vida absoluta y borra el historial
 *   más antiguo que SESSION_HISTORY_DAYS.
 * - Cuentas inactivas: desactiva las que no inician sesión en
 *   ACCOUNT_INACTIVITY_DISABLE_DAYS días (nunca el último SUPER_ADMIN activo),
 *   revoca su acceso, lo audita y avisa al titular.
 * - Retención de la bitácora: con AUDIT_RETENTION_DAYS > 0 borra los eventos
 *   más antiguos (mínimo 365 días) y deja constancia de la purga en la bitácora.
 */
final readonly class MantenimientoSeguridad implements TareaExclusiva
{
    public const int RETENCION_MINIMA_DIAS = 365;

    public function __construct(
        private Connection $conexion,
        private Identidades $identidades,
        private GobiernoRepositorio $gobierno,
        private Notificador $notificador,
        private Ajustes $ajustes,
    ) {
    }

    public function nombre(): string
    {
        return 'security_housekeeping';
    }

    public function cadaSegundos(): int
    {
        return PurgaSeguridad::CADA_SEGUNDOS;
    }

    /** @return array{sesiones_expiradas: int, sesiones_purgadas: int, cuentas_desactivadas: int, auditoria_purgada: int} */
    public function ejecutar(): array
    {
        $ahora = Reloj::ahora();
        [$expiradas, $purgadas] = $this->conexion->transactional(fn (): array => [
            $this->conexion->executeStatement(
                "UPDATE \"SYS_SESION\" SET \"SES_Cerrada_En\" = :ahora, \"SES_Motivo_Cierre\" = 'expirada' "
                .'WHERE "SES_Cerrada_En" IS NULL AND "SES_Expira" <= :ahora', ['ahora' => Reloj::aBd($ahora)]),
            $this->conexion->executeStatement('DELETE FROM "SYS_SESION" WHERE "SES_Creada_En" < :limite',
                ['limite' => Reloj::aBd($ahora->modify("-{$this->ajustes->historialSesionesDias} days"))]),
        ]);

        return [
            'sesiones_expiradas' => $expiradas,
            'sesiones_purgadas' => $purgadas,
            'cuentas_desactivadas' => \count($this->desactivarInactivas()),
            'auditoria_purgada' => $this->aplicarRetencion(),
        ];
    }

    /** @return list<string> usuarios desactivados */
    public function desactivarInactivas(): array
    {
        $dias = $this->ajustes->cuentasInactivasDias;
        if ($dias <= 0) {
            return [];
        }
        $desactivadas = $this->conexion->transactional(function () use ($dias): array {
            $candidatas = $this->conexion->fetchAllAssociative(
                'SELECT u."USU_Usuario", u."USU_Username", u."USU_Rol", u."USU_Ultimo_Login", p."PER_Primer_Nombre", '
                .'p."PER_Primer_Apellido", p."PER_Email_Corporativo" FROM "INV_USUARIO" u '
                .'LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = u."PER_Persona" '
                .'WHERE u."USU_Estado" = 1 AND COALESCE(u."USU_Ultimo_Login", u."USU_Creado_En") < :limite',
                ['limite' => Reloj::aBd(Reloj::ahora()->modify("-{$dias} days"))],
            );
            if ([] === $candidatas) {
                return [];
            }
            $superAdmins = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_USUARIO" WHERE "USU_Rol" = :r AND "USU_Estado" = 1',
                ['r' => Roles::SUPER_ADMIN]);
            $desactivadas = [];
            foreach ($candidatas as $u) {
                if (Roles::SUPER_ADMIN === $u['USU_Rol']) {
                    if ($superAdmins <= 1) {
                        continue; // nunca el último SUPER_ADMIN activo
                    }
                    --$superAdmins;
                }
                $id = (string) $u['USU_Usuario'];
                $this->conexion->executeStatement('UPDATE "INV_USUARIO" SET "USU_Estado" = 0 WHERE "USU_Usuario" = :u', ['u' => $id]);
                $this->identidades->revocarAcceso($id);
                $this->gobierno->registrarAuditoria('ACCOUNT_AUTO_DISABLED', 'INV_USUARIO', [
                    'target_id' => Ident::aTexto($id), 'target_username' => $u['USU_Username'], 'rol' => $u['USU_Rol'],
                    'dias_inactividad' => $dias, 'ultimo_login' => Reloj::iso(Reloj::desdeBd(null === $u['USU_Ultimo_Login'] ? null : (string) $u['USU_Ultimo_Login'])),
                ]);
                $desactivadas[] = $u;
            }

            return $desactivadas;
        });
        // Aviso al titular, después del commit y sin afectar el resultado.
        foreach ($desactivadas as $u) {
            $nombre = null === $u['PER_Primer_Nombre'] ? (string) $u['USU_Username'] : $u['PER_Primer_Nombre'].' '.$u['PER_Primer_Apellido'];
            $this->notificador->eventoSeguridad('cuenta_desactivada_inactividad', $nombre, (string) $u['USU_Username'],
                null === $u['PER_Email_Corporativo'] ? null : (string) $u['PER_Email_Corporativo'], ['dias' => $dias]);
        }

        return array_map(static fn (array $u): string => (string) $u['USU_Username'], $desactivadas);
    }

    /** Borra la bitácora más antigua que la retención; devuelve cuántos eventos borró. */
    public function aplicarRetencion(): int
    {
        $configurada = $this->ajustes->auditoriaRetencionDias;
        if ($configurada <= 0) {
            return 0;
        }
        $dias = max($configurada, self::RETENCION_MINIMA_DIAS);
        $corte = Reloj::ahora()->modify("-{$dias} days");

        return $this->conexion->transactional(function () use ($corte, $dias): int {
            // Modo PURGA del contexto: el disparador append-only solo deja borrar eventos
            // de más de un año. El contexto dura la sesión de Oracle: se limpia siempre.
            $this->conexion->executeStatement("BEGIN INV_CTX.modo('PURGA'); END;");
            try {
                $borrados = $this->conexion->executeStatement('DELETE FROM "INV_AUDITORIA_SISTEMA" WHERE "AUD_Fecha_Hora" < :corte',
                    ['corte' => Reloj::aBd($corte)]);
            } finally {
                $this->conexion->executeStatement('BEGIN INV_CTX.limpiar; END;');
            }
            if ($borrados > 0) {
                $this->gobierno->registrarAuditoria('AUDIT_RETENTION_PURGE', 'INV_AUDITORIA_SISTEMA', [
                    'registros_eliminados' => $borrados, 'anteriores_a' => Reloj::iso($corte), 'retencion_dias' => $dias,
                ]);
            }

            return $borrados;
        });
    }
}
