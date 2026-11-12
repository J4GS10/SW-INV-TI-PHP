<?php

declare(strict_types=1);

namespace App\Notificaciones\Infrastructure;

use App\Notificaciones\ReglaNotificacion;
use App\Notificaciones\EventoNotificacion;
use App\Dominio\Reloj;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use Doctrine\DBAL\Connection;

/**
 * Reglas guardadas en INV_REGLA_NOTIFICACION. Sin fila, el evento usa sus
 * valores por defecto; restablecer una regla es borrar su fila.
 */
final readonly class ReglasNotificacion
{
    public function __construct(private Connection $conexion, private GobiernoRepositorio $gobierno)
    {
    }

    /** Evento configurable o 404 NOTIFICATION_EVENT_NOT_FOUND. */
    public static function evento(string $nombre): EventoNotificacion
    {
        return EventoNotificacion::tryFrom($nombre) ?? throw ErrorDominio::noEncontrado('NOTIFICATION_EVENT_NOT_FOUND');
    }

    public function cargar(EventoNotificacion $evento): ReglaNotificacion
    {
        $fila = $this->conexion->fetchAssociative('SELECT * FROM "INV_REGLA_NOTIFICACION" WHERE "RNO_Evento" = :e', ['e' => $evento->value]);

        return false === $fila ? $evento->reglaPorDefecto() : ReglaNotificacion::desdeFila($evento, $fila);
    }

    /** @return list<ReglaNotificacion> todos los eventos, en su orden */
    public function listar(): array
    {
        $filas = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT * FROM "INV_REGLA_NOTIFICACION"') as $f) {
            $filas[(string) $f['RNO_Evento']] = $f;
        }

        return array_map(
            static fn (EventoNotificacion $e): ReglaNotificacion => isset($filas[$e->value]) ? ReglaNotificacion::desdeFila($e, $filas[$e->value]) : $e->reglaPorDefecto(),
            EventoNotificacion::cases(),
        );
    }

    /**
     * Crea o reemplaza la regla y lo audita.
     *
     * @param array{activa: bool, notificar_afectado: bool, notificar_jefe: bool, copiar_admins: bool, grupos_ad: list<string>, correos_extra: list<string>} $datos
     */
    public function guardar(EventoNotificacion $evento, array $datos, ?string $usuarioHex, ?string $ip): ReglaNotificacion
    {
        return $this->conexion->transactional(function () use ($evento, $datos, $usuarioHex, $ip): ReglaNotificacion {
            $this->conexion->executeStatement(
                'MERGE INTO "INV_REGLA_NOTIFICACION" r USING (SELECT :evento AS evento FROM DUAL) s ON (r."RNO_Evento" = s.evento) '
                .'WHEN MATCHED THEN UPDATE SET r."RNO_Activa" = :activa, r."RNO_Notificar_Afectado" = :afectado, '
                .'r."RNO_Notificar_Jefe" = :jefe, r."RNO_Copiar_Admins" = :admins, r."RNO_Grupos_AD" = :grupos, '
                .'r."RNO_Correos_Extra" = :extra, r."UPDATED_AT" = :ahora '
                .'WHEN NOT MATCHED THEN INSERT ("RNO_Evento", "RNO_Activa", "RNO_Notificar_Afectado", "RNO_Notificar_Jefe", '
                .'"RNO_Copiar_Admins", "RNO_Grupos_AD", "RNO_Correos_Extra", "UPDATED_AT") '
                .'VALUES (:evento, :activa, :afectado, :jefe, :admins, :grupos, :extra, :ahora)',
                [
                    'evento' => $evento->value,
                    'activa' => (int) $datos['activa'],
                    'afectado' => (int) $datos['notificar_afectado'],
                    'jefe' => (int) $datos['notificar_jefe'],
                    'admins' => (int) $datos['copiar_admins'],
                    'grupos' => ReglaNotificacion::unir($datos['grupos_ad']),
                    'extra' => ReglaNotificacion::unir(array_map('strtolower', $datos['correos_extra'])),
                    'ahora' => Reloj::aBd(Reloj::ahora()),
                ],
            );
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_REGLA_NOTIFICACION', ['evento' => $evento->value, ...$datos], $usuarioHex, $ip);

            return $this->cargar($evento);
        });
    }

    /** Vuelve a los valores por defecto (borra la fila, si había). */
    public function restablecer(EventoNotificacion $evento, ?string $usuarioHex, ?string $ip): void
    {
        $this->conexion->transactional(function () use ($evento, $usuarioHex, $ip): void {
            $borradas = $this->conexion->executeStatement('DELETE FROM "INV_REGLA_NOTIFICACION" WHERE "RNO_Evento" = :e', ['e' => $evento->value]);
            if ($borradas > 0) {
                $this->gobierno->registrarAuditoria('DELETE', 'INV_REGLA_NOTIFICACION', ['evento' => $evento->value], $usuarioHex, $ip);
            }
        });
    }
}
