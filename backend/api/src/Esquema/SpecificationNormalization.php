<?php

declare(strict_types=1);

namespace App\Esquema;

/**
 * Splits legacy text such as «16GB DDR4» or «1 TB NVMe» into a number and a separate kind. Only values
 * that match exactly are changed; anything else stays as it was. Each change leaves its previous value
 * in the audit log. Running it again changes nothing.
 */
final class SpecificationNormalization
{
    private const string NUMBER = '([0-9]+([.,][0-9]+)?)';
    private const string RAM = '^\s*'.self::NUMBER.'\s*(GB|G|TB|T)?\s*((LP)?DDR[0-9]X?)?\s*$';
    private const string DISK = '^\s*'.self::NUMBER.'\s*(GB|G|TB|T)?\s*(SSD|NVME|HDD)?\s*$';

    /** @return list<string> */
    public static function statements(): array
    {
        return [
            ...self::forSpecification('RAM', self::RAM, 'Tipo de RAM', "UPPER(REGEXP_SUBSTR(e.\"ESP_Valor\", '(LP)?DDR[0-9]X?', 1, 1, 'i'))"),
            ...self::forSpecification('Almacenamiento', self::DISK, 'Tipo de disco',
                "CASE UPPER(REGEXP_SUBSTR(e.\"ESP_Valor\", 'SSD|NVME|HDD', 1, 1, 'i')) WHEN 'NVME' THEN 'NVMe' ELSE UPPER(REGEXP_SUBSTR(e.\"ESP_Valor\", 'SSD|NVME|HDD', 1, 1, 'i')) END"),
        ];
    }

    /** @return list<string> */
    private static function forSpecification(string $name, string $pattern, string $kindName, string $kindValue): array
    {
        $specification = "(SELECT \"TES_Tipo_Especificacion\" FROM \"INV_TIPO_ESPECIFICACION\" WHERE \"TES_Nombre\" = '{$name}')";
        $kind = "(SELECT \"TES_Tipo_Especificacion\" FROM \"INV_TIPO_ESPECIFICACION\" WHERE \"TES_Nombre\" = '{$kindName}')";
        $rows = "e.\"TES_Tipo_Especificacion\" = {$specification} AND REGEXP_LIKE(e.\"ESP_Valor\", '{$pattern}', 'i')";
        $number = "TO_NUMBER(REPLACE(REGEXP_SUBSTR(e.\"ESP_Valor\", '[0-9]+([.,][0-9]+)?'), ',', '.'), '9999999999D9999', 'NLS_NUMERIC_CHARACTERS=''.,''')";
        $inGb = "TO_CHAR(CASE WHEN REGEXP_LIKE(e.\"ESP_Valor\", '[0-9 ](TB|T)( |\$|[A-Z])', 'i') THEN {$number} * 1024 ELSE {$number} END, 'TM9', 'NLS_NUMERIC_CHARACTERS=''.,''')";
        $changed = "{$rows} AND e.\"ESP_Valor\" <> {$inGb}";

        return [
            // The kind goes first, while the original text still has it.
            "INSERT INTO \"INV_ESPECIFICACION\" (\"ESP_Valor\", \"ACT_Activo\", \"TES_Tipo_Especificacion\") "
            ."SELECT {$kindValue}, e.\"ACT_Activo\", {$kind} FROM \"INV_ESPECIFICACION\" e WHERE {$rows} AND {$kindValue} IS NOT NULL "
            ."AND NOT EXISTS (SELECT 1 FROM \"INV_ESPECIFICACION\" k WHERE k.\"ACT_Activo\" = e.\"ACT_Activo\" AND k.\"TES_Tipo_Especificacion\" = {$kind})",
            "INSERT INTO \"INV_AUDITORIA_SISTEMA\" (\"AUD_Auditoria\", \"AUD_Fecha_Hora\", \"AUD_Accion\", \"AUD_Entidad_Afectada\", \"AUD_Snapshot_JSON\") "
            ."SELECT LOWER(RAWTOHEX(SYS_GUID())), SYS_EXTRACT_UTC(SYSTIMESTAMP), 'SPEC_NORMALIZED', 'INV_ESPECIFICACION', "
            ."JSON_OBJECT('id' VALUE e.\"ESP_Especificacion\", 'activo' VALUE e.\"ACT_Activo\", 'antes' VALUE e.\"ESP_Valor\", 'despues' VALUE {$inGb}) "
            ."FROM \"INV_ESPECIFICACION\" e WHERE {$changed}",
            "UPDATE \"INV_ESPECIFICACION\" e SET e.\"ESP_Valor\" = {$inGb} WHERE {$changed}",
        ];
    }
}
