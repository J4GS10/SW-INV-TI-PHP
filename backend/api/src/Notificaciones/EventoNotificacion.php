<?php

declare(strict_types=1);

namespace App\Notificaciones;

/**
 * Eventos con reglas de destinatarios configurables. Los valores por defecto
 * reproducen a quién se enviaba cada correo antes de las reglas. Los correos de
 * seguridad (contraseñas, códigos 2FA) no son configurables.
 */
enum EventoNotificacion: string
{
    case ASIGNACION = 'asignacion';
    case DEVOLUCION = 'devolucion';
    case TRANSFERENCIA = 'transferencia';
    case BAJA = 'baja';
    case OFFBOARDING = 'offboarding';
    case MANTENIMIENTO_ABIERTO = 'mantenimiento_abierto';
    case MANTENIMIENTO_CERRADO = 'mantenimiento_cerrado';
    case STOCK_BAJO = 'stock_bajo';
    case GARANTIA_POR_VENCER = 'garantia_por_vencer';
    case RECEPCION_DIFERENCIAS = 'recepcion_diferencias';
    case INGRESO_REGISTRADO = 'ingreso_registrado';

    /** ¿La persona involucrada recibe el aviso por defecto? */
    public function notificaAfectadoPorDefecto(): bool
    {
        return match ($this) {
            self::ASIGNACION, self::DEVOLUCION, self::TRANSFERENCIA, self::MANTENIMIENTO_ABIERTO => true,
            default => false,
        };
    }

    public function reglaPorDefecto(): ReglaNotificacion
    {
        return new ReglaNotificacion($this, activa: true, notificarAfectado: $this->notificaAfectadoPorDefecto(),
            notificarJefe: false, copiarAdmins: true);
    }
}
