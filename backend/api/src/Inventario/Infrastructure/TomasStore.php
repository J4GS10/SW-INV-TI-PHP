<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure;

use App\Db\InsercionOracle;
use App\Dominio\Reloj;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Inventory counts and their readings as stored, and what a count reads about the assets: all that SQL lives here. */
final readonly class TomasStore
{
    private const string COLUMNAS = 't."TOM_Toma", t."SED_Sede", t."ARE_Area", t."TOM_Estado", t."TOM_Fecha_Inicio", t."TOM_Fecha_Cierre", t."TOM_Observacion", '
        .'t."USU_Usuario", t."TOM_Resumen"';
    private const string LECTURA = '"TLE_Lectura", "TLE_Codigo_Leido", "ACT_Activo", "TLE_Resultado", "ARE_Area", "TLE_Fecha", "TLE_Observacion"';

    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /** @return array<string, mixed>|false a count the current user may see, locked when asked */
    public function porId(int $id, bool $bloquear = false): array|false
    {
        [$alcance, $parametros] = $this->alcance->lectura('INV_TOMA_INVENTARIO', 't');

        return $this->conexion->fetchAssociative('SELECT '.self::COLUMNAS.' FROM "INV_TOMA_INVENTARIO" t WHERE t."TOM_Toma" = :id AND '.$alcance.($bloquear ? ' FOR UPDATE' : ''),
            [...$parametros, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> the last 200 counts the current user may see, newest first */
    public function visibles(): array
    {
        [$alcance, $parametros] = $this->alcance->lectura('INV_TOMA_INVENTARIO', 't');

        return $this->conexion->fetchAllAssociative('SELECT '.self::COLUMNAS.' FROM "INV_TOMA_INVENTARIO" t WHERE '.$alcance
            .' ORDER BY t."TOM_Fecha_Inicio" DESC, t."TOM_Toma" DESC FETCH FIRST 200 ROWS ONLY', $parametros);
    }

    /** @return mixed the open count of that site (and area, or of the whole site when null); false when there is none */
    public function abiertaEn(int $sede, ?int $area): mixed
    {
        return $this->conexion->fetchOne("SELECT \"TOM_Toma\" FROM \"INV_TOMA_INVENTARIO\" WHERE \"SED_Sede\" = :s AND \"TOM_Estado\" = 'ABIERTA' AND "
            .(null === $area ? '"ARE_Area" IS NULL' : '"ARE_Area" = :a').' FETCH FIRST 1 ROWS ONLY', null === $area ? ['s' => $sede] : ['s' => $sede, 'a' => $area]);
    }

    /**
     * @param array<string, mixed> $datos
     *
     * @return mixed the id generated for the new row
     */
    public function insertar(string $tabla, array $datos, string $clave): mixed
    {
        return InsercionOracle::insertar($this->conexion, $tabla, $datos, $clave);
    }

    /**
     * Assets the system places in the site (or the area) of a count, in every site scope: complete ones, not decommissioned.
     *
     * @return list<array<string, mixed>> ACT_Activo, ACT_Codigo_Interno, ARE_Area_Ubicacion
     */
    public function esperados(int $sede, ?int $area, ?int $estadoBaja): array
    {
        $parametros = ['s' => $sede];
        $sql = 'SELECT "ACT_Activo", "ACT_Codigo_Interno", "ARE_Area_Ubicacion" FROM "INV_ACTIVO" WHERE "ACT_Pending_Completion" = 0 AND "SED_Sede" = :s';
        if (null !== $estadoBaja) {
            $sql .= ' AND "EOP_Estado_Operativo" <> :b';
            $parametros['b'] = $estadoBaja;
        }
        if (null !== $area) {
            $sql .= ' AND "ARE_Area_Ubicacion" = :a';
            $parametros['a'] = $area;
        }

        return $this->alcance->elevado(fn (): array => $this->conexion->fetchAllAssociative($sql.' ORDER BY "ACT_Codigo_Interno"', $parametros));
    }

    /** @return list<array<string, mixed>> the readings of a count, in the order they were taken */
    public function lecturasDe(int $toma): array
    {
        return $this->conexion->fetchAllAssociative('SELECT '.self::LECTURA.' FROM "INV_TOMA_LECTURA" WHERE "TOM_Toma" = :t ORDER BY "TLE_Lectura"', ['t' => $toma]);
    }

    /** @return array<string, mixed>|false */
    public function lectura(int $lectura): array|false
    {
        return $this->conexion->fetchAssociative('SELECT '.self::LECTURA.' FROM "INV_TOMA_LECTURA" WHERE "TLE_Lectura" = :l', ['l' => $lectura]);
    }

    /** @return mixed the asset with that internal code or serial, whatever its site (a reading may find one from elsewhere); false when unknown */
    public function activoPorCodigo(string $codigo): mixed
    {
        return $this->alcance->elevado(fn (): mixed => $this->conexion->fetchAssociative('SELECT "ACT_Activo", "ACT_Codigo_Interno", "ACT_Hostname", '
            .'"EOP_Estado_Operativo", "SED_Sede", "ARE_Area_Ubicacion" FROM "INV_ACTIVO" WHERE LOWER("ACT_Codigo_Interno") = :c OR LOWER("ACT_Serie_Fabricante") = :c '
            .'ORDER BY "ACT_Codigo_Interno" FETCH FIRST 1 ROWS ONLY', ['c' => mb_strtolower($codigo)]));
    }

    /** @param array<string, mixed> $pendiente what the background closing needs to finish */
    public function marcarCerrando(int $toma, array $pendiente): void
    {
        $this->conexion->executeStatement("UPDATE \"INV_TOMA_INVENTARIO\" SET \"TOM_Estado\" = 'CERRANDO', \"TOM_Resumen\" = :r WHERE \"TOM_Toma\" = :id",
            ['r' => json_encode($pendiente, \JSON_THROW_ON_ERROR), 'id' => $toma], ['r' => ParameterType::LARGE_OBJECT]);
    }

    /** @return list<mixed> the counts whose closing started and has not finished, oldest first */
    public function enCierre(): array
    {
        return $this->conexion->fetchFirstColumn("SELECT \"TOM_Toma\" FROM \"INV_TOMA_INVENTARIO\" WHERE \"TOM_Estado\" = 'CERRANDO' ORDER BY \"TOM_Toma\"");
    }

    public function ubicarActivo(string $activo, mixed $area): void
    {
        $this->conexion->executeStatement('UPDATE "INV_ACTIVO" SET "ARE_Area_Ubicacion" = :a WHERE "ACT_Activo" = :id', ['a' => $area, 'id' => $activo]);
    }

    /** @param array<string, mixed> $resumen */
    public function cerrar(int $toma, array $resumen, \DateTimeImmutable $ahora): void
    {
        $this->conexion->executeStatement("UPDATE \"INV_TOMA_INVENTARIO\" SET \"TOM_Estado\" = 'CERRADA', \"TOM_Fecha_Cierre\" = :f, \"TOM_Resumen\" = :r WHERE \"TOM_Toma\" = :id",
            ['f' => Reloj::aBd($ahora), 'r' => json_encode($resumen, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), 'id' => $toma],
            ['r' => ParameterType::LARGE_OBJECT]);
    }

    public function anular(int $toma, \DateTimeImmutable $ahora): void
    {
        $this->conexion->executeStatement("UPDATE \"INV_TOMA_INVENTARIO\" SET \"TOM_Estado\" = 'ANULADA', \"TOM_Fecha_Cierre\" = :f WHERE \"TOM_Toma\" = :id",
            ['f' => Reloj::aBd($ahora), 'id' => $toma]);
    }
}
