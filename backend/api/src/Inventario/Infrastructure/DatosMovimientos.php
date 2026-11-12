<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure;

use App\Dominio\Reloj;
use Doctrine\DBAL\Connection;

/** Custody movements and what hangs from them (types, reasons, licence installations) as stored: all that SQL lives here. */
final readonly class DatosMovimientos
{
    public function __construct(private Connection $conexion)
    {
    }

    /** @return mixed the id of the movement type with that name; false when it is not in the catalogue */
    public function tipoDeMovimiento(string $nombre): mixed
    {
        return $this->conexion->fetchOne('SELECT "TMO_Tipo_Movimiento" FROM "INV_TIPO_MOVIMIENTO" WHERE "TMO_Nombre" = :n FETCH FIRST 1 ROWS ONLY', ['n' => $nombre]);
    }

    /** @return mixed the id of the movement reason with that code; false when it is not in the catalogue */
    public function motivoPorCodigo(string $codigo): mixed
    {
        return $this->conexion->fetchOne('SELECT "MMO_Motivo" FROM "INV_MOTIVO_MOVIMIENTO" WHERE "MMO_Codigo" = :c', ['c' => $codigo]);
    }

    /** @return mixed the person who holds the asset now; false when nobody does */
    public function custodioActual(mixed $activo): mixed
    {
        return $this->conexion->fetchOne('SELECT "PER_Persona" FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL', ['a' => $activo]);
    }

    /** @return array<string, mixed>|false MOV_Movimiento, ACT_Activo, MOV_Fecha_Asignacion, MOV_Fecha_Devolucion, MOV_Usuario_Registra */
    public function movimiento(mixed $movimiento): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "MOV_Movimiento", "ACT_Activo", "MOV_Fecha_Asignacion", "MOV_Fecha_Devolucion", "MOV_Usuario_Registra" '
            .'FROM "INV_MOVIMIENTO" WHERE "MOV_Movimiento" = :m', ['m' => $movimiento]);
    }

    /**
     * The custody of the asset that was closed at or after the given moment, other than the given movement: where it came from.
     *
     * @return array<string, mixed>|false PER_Persona, ARE_Area
     */
    public function custodiaAnterior(mixed $activo, mixed $movimiento, \DateTimeImmutable $desde): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "PER_Persona", "ARE_Area" FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a AND "MOV_Movimiento" <> :m '
            .'AND "MOV_Fecha_Devolucion" IS NOT NULL AND "MOV_Fecha_Devolucion" >= :desde ORDER BY "MOV_Fecha_Devolucion" DESC FETCH FIRST 1 ROWS ONLY',
            ['a' => $activo, 'm' => $movimiento, 'desde' => Reloj::aBd($desde)]);
    }

    /** @return array<string, mixed>|false LIC_Licencia, ACT_Activo, PER_Persona, INS_Estado */
    public function instalacion(mixed $instalacion): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "LIC_Licencia", "ACT_Activo", "PER_Persona", "INS_Estado" FROM "INV_INSTALACION" WHERE "INS_Instalacion" = :i', ['i' => $instalacion]);
    }
}
