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
use App\Db\Transaccion;
use App\Tareas\Infrastructure\MantenimientoStore;

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
        private MantenimientoStore $datos,
        private Transaccion $transaccion,
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
        [$expiradas, $purgadas] = $this->transaccion->ejecutar(fn (): array => [
            $this->datos->cerrarSesionesVencidas($ahora),
            $this->datos->purgarSesionesAnterioresA($ahora->modify("-{$this->ajustes->historialSesionesDias} days")),
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
        $desactivadas = $this->transaccion->ejecutar(function () use ($dias): array {
            $candidatas = $this->datos->cuentasSinIngresoDesde(Reloj::ahora()->modify("-{$dias} days"));
            if ([] === $candidatas) {
                return [];
            }
            $superAdmins = $this->datos->superAdministradoresActivos();
            $desactivadas = [];
            foreach ($candidatas as $u) {
                if (Roles::SUPER_ADMIN === $u['USU_Rol']) {
                    if ($superAdmins <= 1) {
                        continue; // nunca el último SUPER_ADMIN activo
                    }
                    --$superAdmins;
                }
                $id = (string) $u['USU_Usuario'];
                $this->datos->desactivarCuenta($id);
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

        return $this->transaccion->ejecutar(function () use ($corte, $dias): int {
            $borrados = $this->datos->purgarBitacoraAnteriorA($corte);
            if ($borrados > 0) {
                $this->gobierno->registrarAuditoria('AUDIT_RETENTION_PURGE', 'INV_AUDITORIA_SISTEMA', [
                    'registros_eliminados' => $borrados, 'anteriores_a' => Reloj::iso($corte), 'retencion_dias' => $dias,
                ]);
            }

            return $borrados;
        });
    }
}
