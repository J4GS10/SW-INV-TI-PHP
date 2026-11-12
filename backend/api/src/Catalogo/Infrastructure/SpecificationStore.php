<?php

declare(strict_types=1);

namespace App\Catalogo\Infrastructure;

use Doctrine\DBAL\Connection;

/** Specification types, the ones each asset type uses and their allowed values, as stored: all their SQL lives here. */
final readonly class SpecificationStore
{
    public function __construct(private Connection $connection)
    {
    }

    // ---------------- Allowed values ----------------

    /** @return array<int, list<string>> allowed values of an asset type, by specification, in their order */
    public function allowedValues(int $assetType): array
    {
        $options = [];
        foreach ($this->connection->fetchAllAssociative('SELECT ASV_TES_SPECIFICATION_TYPE, ASV_VALUE FROM CAT_SPEC_ALLOWED_VALUE WHERE ASV_TAC_ASSET_TYPE = :t ORDER BY ASV_SORT_ORDER, ASV_ALLOWED_VALUE', ['t' => $assetType]) as $row) {
            $options[(int) $row['ASV_TES_SPECIFICATION_TYPE']][] = (string) $row['ASV_VALUE'];
        }

        return $options;
    }

    public function clearAllowedValues(int $assetType, ?int $specification = null): void
    {
        if (null === $specification) {
            $this->connection->executeStatement('DELETE FROM CAT_SPEC_ALLOWED_VALUE WHERE ASV_TAC_ASSET_TYPE = :t', ['t' => $assetType]);

            return;
        }
        $this->connection->executeStatement('DELETE FROM CAT_SPEC_ALLOWED_VALUE WHERE ASV_TAC_ASSET_TYPE = :t AND ASV_TES_SPECIFICATION_TYPE = :s', ['t' => $assetType, 's' => $specification]);
    }

    public function addAllowedValue(int $assetType, int $specification, string $value, int $sortOrder): void
    {
        $this->connection->executeStatement('INSERT INTO CAT_SPEC_ALLOWED_VALUE (ASV_TAC_ASSET_TYPE, ASV_TES_SPECIFICATION_TYPE, ASV_VALUE, ASV_SORT_ORDER) VALUES (:t, :s, :v, :o)',
            ['t' => $assetType, 's' => $specification, 'v' => $value, 'o' => $sortOrder]);
    }

    // ---------------- Specification types ----------------

    /** @return array{dataType: string, min: float|null, max: float|null}|null */
    public function specificationType(int $specification): ?array
    {
        $type = $this->connection->fetchAssociative('SELECT "TES_Data_Type", "TES_Min_Value", "TES_Max_Value" FROM "INV_TIPO_ESPECIFICACION" WHERE "TES_Tipo_Especificacion" = :t', ['t' => $specification]);
        if (false === $type) {
            return null;
        }

        return ['dataType' => (string) $type['TES_Data_Type'], 'min' => null === $type['TES_Min_Value'] ? null : (float) $type['TES_Min_Value'],
            'max' => null === $type['TES_Max_Value'] ? null : (float) $type['TES_Max_Value']];
    }

    /** The specification type with that name, without case or surrounding spaces. */
    public function specificationTypeByName(string $name): ?int
    {
        $id = $this->connection->fetchOne('SELECT "TES_Tipo_Especificacion" FROM "INV_TIPO_ESPECIFICACION" WHERE LOWER(TRIM("TES_Nombre")) = LOWER(:n)', ['n' => $name]);

        return false === $id ? null : (int) $id;
    }

    /** Creates a specification type; its unit of measure is taken from the unit with that code, if any. */
    public function createSpecificationType(string $name, ?string $unitCode): void
    {
        $unit = null === $unitCode ? false : $this->connection->fetchAssociative('SELECT "UME_Unidad", "UME_Simbolo" FROM "INV_UNIDAD_MEDIDA" WHERE "UME_Codigo" = :c', ['c' => $unitCode]);
        $this->connection->executeStatement('INSERT INTO "INV_TIPO_ESPECIFICACION" ("TES_Nombre", "TES_Unidad_Medida", "UME_Unidad") VALUES (:n, :s, :u)',
            ['n' => $name, 's' => false === $unit ? null : $unit['UME_Simbolo'], 'u' => false === $unit ? null : $unit['UME_Unidad']]);
    }

    // ---------------- Specifications of an asset type ----------------

    /** @return array{TAC_Nombre: string, TAC_Clase: string}|null */
    public function assetType(int $assetType): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT "TAC_Nombre", "TAC_Clase" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $assetType]);

        return false === $row ? null : ['TAC_Nombre' => (string) $row['TAC_Nombre'], 'TAC_Clase' => (string) $row['TAC_Clase']];
    }

    public function hasSpecifications(int $assetType): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM "INV_ESPECIFICACION_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t FETCH FIRST 1 ROWS ONLY', ['t' => $assetType]);
    }

    public function uses(int $assetType, int $specification): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM "INV_ESPECIFICACION_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t AND "TES_Tipo_Especificacion" = :e', ['t' => $assetType, 'e' => $specification]);
    }

    public function addToAssetType(int $assetType, int $specification, bool $required, int $sortOrder): void
    {
        $this->connection->executeStatement('INSERT INTO "INV_ESPECIFICACION_TIPO_ACTIVO" ("TAC_Tipo_Activo", "TES_Tipo_Especificacion", "ETA_Obligatoria", "ETA_Orden") VALUES (:t, :e, :o, :r)',
            ['t' => $assetType, 'e' => $specification, 'o' => $required ? 1 : 0, 'r' => $sortOrder]);
    }
}
