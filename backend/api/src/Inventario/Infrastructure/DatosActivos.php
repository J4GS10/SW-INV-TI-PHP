<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure;

use Doctrine\DBAL\Connection;

/** What the asset rules read and write besides the asset row (places, suppliers, specifications): all that SQL lives here. */
final readonly class DatosActivos
{
    public function __construct(private Connection $conexion)
    {
    }

    /** @return mixed the site of an area; false when the area has no building chain or does not exist */
    public function sedeDeArea(int $area): mixed
    {
        return $this->conexion->fetchOne('SELECT e."SED_Sede" FROM "INV_AREA" a JOIN "INV_NIVEL" n ON n."NIV_Nivel" = a."NIV_Nivel" '
            .'JOIN "INV_EDIFICIO" e ON e."EDI_Edificio" = n."EDI_Edificio" WHERE a."ARE_Area" = :a', ['a' => $area]);
    }

    public function existeArea(int $area): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_AREA" WHERE "ARE_Area" = :a', ['a' => $area]);
    }

    public function existeProveedor(mixed $proveedor): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_PROVEEDOR" WHERE "PRV_Proveedor" = :p', ['p' => $proveedor]);
    }

    public function existeTipoEspecificacion(mixed $tipo): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_TIPO_ESPECIFICACION" WHERE "TES_Tipo_Especificacion" = :t', ['t' => $tipo]);
    }

    /** @return array<string, mixed>|false ESP_Especificacion and ESP_Valor of the specification of that type in that asset */
    public function especificacionDe(mixed $activo, mixed $tipo): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "ESP_Especificacion", "ESP_Valor" FROM "INV_ESPECIFICACION" WHERE "ACT_Activo" = :a AND '
            .'"TES_Tipo_Especificacion" = :t FETCH FIRST 1 ROWS ONLY', ['a' => $activo, 't' => $tipo]);
    }

    public function quitarEspecificacion(mixed $especificacion): void
    {
        $this->conexion->delete('"INV_ESPECIFICACION"', ['"ESP_Especificacion"' => $especificacion]);
    }

    public function cambiarEspecificacion(mixed $especificacion, ?string $valor): void
    {
        $this->conexion->executeStatement('UPDATE "INV_ESPECIFICACION" SET "ESP_Valor" = :v WHERE "ESP_Especificacion" = :id', ['v' => $valor, 'id' => $especificacion]);
    }

    public function agregarEspecificacion(mixed $activo, mixed $tipo, ?string $valor): void
    {
        $this->conexion->executeStatement('INSERT INTO "INV_ESPECIFICACION" ("ESP_Valor", "ACT_Activo", "TES_Tipo_Especificacion") VALUES (:v, :a, :t)',
            ['v' => $valor, 'a' => $activo, 't' => $tipo]);
    }

    /**
     * @param array<int|string, string> $activos
     *
     * @return array<string, string> asset id => internal code, only for the ones that exist
     */
    public function codigosDe(array $activos): array
    {
        if ([] === $activos) {
            return [];
        }
        $parametros = [];
        foreach (array_values($activos) as $i => $id) {
            $parametros["a{$i}"] = $id;
        }
        $codigos = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT "ACT_Activo", "ACT_Codigo_Interno" FROM "INV_ACTIVO" WHERE "ACT_Activo" IN (:'.implode(', :', array_keys($parametros)).')', $parametros) as $f) {
            $codigos[(string) $f['ACT_Activo']] = (string) $f['ACT_Codigo_Interno'];
        }

        return $codigos;
    }

    public function codigoDe(mixed $activo): mixed
    {
        return $this->conexion->fetchOne('SELECT "ACT_Codigo_Interno" FROM "INV_ACTIVO" WHERE "ACT_Activo" = :a', ['a' => $activo]);
    }
}
