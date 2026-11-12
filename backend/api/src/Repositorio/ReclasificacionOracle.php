<?php

declare(strict_types=1);

namespace App\Repositorio;

use App\Db\InsercionOracle;
use App\Inventario\Activos;
use App\Inventario\Puertos\ReclasificacionDatos;
use Doctrine\DBAL\Connection;

/** Reclassification of supplies in Oracle: all its SQL lives here. */
final readonly class ReclasificacionOracle implements ReclasificacionDatos
{
    public function __construct(private Connection $conexion)
    {
    }

    public function tipo(int $tipo): ?array
    {
        $fila = $this->conexion->fetchAssociative('SELECT "TAC_Nombre", "TAC_Clase" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $tipo]);

        return false === $fila ? null : ['TAC_Nombre' => (string) $fila['TAC_Nombre'], 'TAC_Clase' => null === $fila['TAC_Clase'] ? null : (string) $fila['TAC_Clase']];
    }

    public function activosVigentes(int $tipo, ?int $sede, int $estadoBaja): array
    {
        $parametros = ['t' => $tipo, 'baja' => $estadoBaja];
        $sql = 'SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE a."TAC_Tipo_Activo" = :t AND a."EOP_Estado_Operativo" <> :baja';
        if (null !== $sede) {
            $sql .= ' AND a."SED_Sede" = :s';
            $parametros['s'] = $sede;
        }

        return $this->conexion->fetchAllAssociative($sql.' ORDER BY a."ACT_Codigo_Interno"', $parametros);
    }

    public function bloquear(array $activos): void
    {
        sort($activos, \SORT_STRING);
        foreach ($activos as $id) {
            $this->conexion->fetchOne('SELECT 1 FROM "INV_ACTIVO" WHERE "ACT_Activo" = :id FOR UPDATE', ['id' => $id]);
        }
    }

    public function tieneCustodia(string $activo): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL FETCH FIRST 1 ROWS ONLY', ['a' => $activo]);
    }

    public function tieneMantenimientoAbierto(string $activo): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_MANTENIMIENTO" WHERE "ACT_Activo" = :a AND "MAN_Fecha_Cierre" IS NULL FETCH FIRST 1 ROWS ONLY', ['a' => $activo]);
    }

    public function nombresDeSedes(): array
    {
        return array_map(strval(...), $this->conexion->fetchAllKeyValue('SELECT "SED_Sede", "SED_Nombre" FROM "INV_SEDE"'));
    }

    public function consumiblePorNombre(string $nombre): ?array
    {
        $fila = $this->conexion->fetchAssociative('SELECT "CON_Consumible", "SED_Sede", "CON_Moneda" FROM "INV_CONSUMIBLE" WHERE LOWER("CON_Nombre") = :n '
            .'FETCH FIRST 1 ROWS ONLY', ['n' => mb_strtolower($nombre)]);
        if (false === $fila) {
            return null;
        }

        return ['CON_Consumible' => (int) $fila['CON_Consumible'], 'SED_Sede' => null === $fila['SED_Sede'] ? null : (int) $fila['SED_Sede'],
            'CON_Moneda' => null === $fila['CON_Moneda'] || '' === $fila['CON_Moneda'] ? null : (string) $fila['CON_Moneda']];
    }

    public function crearConsumible(array $datos): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_CONSUMIBLE', $datos, 'CON_Consumible');
    }

    public function darDeBaja(string $activo, int $estadoBaja, \DateTimeImmutable $fecha, string $documento, string $observacion): void
    {
        $this->conexion->executeStatement("UPDATE \"INV_ACTIVO\" SET \"EOP_Estado_Operativo\" = :b, \"ACT_Baja_Motivo\" = 'RECLASIFICACION', "
            ."\"ACT_Baja_Destino\" = 'CONSUMIBLES', \"ACT_Baja_Fecha\" = TO_DATE(:f, 'YYYY-MM-DD'), \"ACT_Baja_Documento\" = :d, \"ACT_Baja_Observacion\" = :o "
            .'WHERE "ACT_Activo" = :id', ['b' => $estadoBaja, 'f' => $fecha->format('Y-m-d'), 'd' => $documento, 'o' => $observacion, 'id' => $activo]);
    }
}
