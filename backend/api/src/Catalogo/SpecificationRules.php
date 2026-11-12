<?php

declare(strict_types=1);

namespace App\Catalogo;

use App\GoodsReceipt\Domain\SpecificationValue;
use App\Dominio\ErrorDominio;
use Doctrine\DBAL\Connection;

/** Allowed values per asset type and specification, and the single place where a value is validated. */
final readonly class SpecificationRules
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public function options(int $assetType, int $specification): array
    {
        return $this->optionsByType($assetType)[$specification] ?? [];
    }

    /** @return array<int, list<string>> allowed values of an asset type, by specification, in their order */
    public function optionsByType(int $assetType): array
    {
        $options = [];
        foreach ($this->connection->fetchAllAssociative('SELECT ASV_TES_SPECIFICATION_TYPE, ASV_VALUE FROM CAT_SPEC_ALLOWED_VALUE WHERE ASV_TAC_ASSET_TYPE = :t ORDER BY ASV_SORT_ORDER, ASV_ALLOWED_VALUE', ['t' => $assetType]) as $row) {
            $options[(int) $row['ASV_TES_SPECIFICATION_TYPE']][] = (string) $row['ASV_VALUE'];
        }

        return $options;
    }

    /** @param list<string> $values */
    public function replaceOptions(int $assetType, int $specification, array $values): void
    {
        $this->connection->executeStatement('DELETE FROM CAT_SPEC_ALLOWED_VALUE WHERE ASV_TAC_ASSET_TYPE = :t AND ASV_TES_SPECIFICATION_TYPE = :s', ['t' => $assetType, 's' => $specification]);
        $seen = [];
        $atomic = array_merge(...array_map(SpecificationValue::splitList(...), $values));
        foreach ($atomic as $order => $value) {
            $key = mb_strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->connection->executeStatement('INSERT INTO CAT_SPEC_ALLOWED_VALUE (ASV_TAC_ASSET_TYPE, ASV_TES_SPECIFICATION_TYPE, ASV_VALUE, ASV_SORT_ORDER) VALUES (:t, :s, :v, :o)',
                ['t' => $assetType, 's' => $specification, 'v' => mb_substr($value, 0, 255), 'o' => ($order + 1) * 10]);
        }
    }

    public function removeOptionsOfType(int $assetType): void
    {
        $this->connection->executeStatement('DELETE FROM CAT_SPEC_ALLOWED_VALUE WHERE ASV_TAC_ASSET_TYPE = :t', ['t' => $assetType]);
    }

    /**
     * @return string the value as it must be stored
     *
     * @throws ErrorDominio 422 with the reason, the same codes the screens show while typing
     */
    public function normalize(int $assetType, int $specification, string $value): string
    {
        $type = $this->connection->fetchAssociative('SELECT "TES_Data_Type", "TES_Min_Value", "TES_Max_Value" FROM "INV_TIPO_ESPECIFICACION" WHERE "TES_Tipo_Especificacion" = :t', ['t' => $specification]);
        if (false === $type) {
            throw ErrorDominio::noEncontrado('SPECIFICATION_TYPE_NOT_FOUND');
        }
        try {
            return SpecificationValue::normalize((string) $type['TES_Data_Type'], null === $type['TES_Min_Value'] ? null : (float) $type['TES_Min_Value'],
                null === $type['TES_Max_Value'] ? null : (float) $type['TES_Max_Value'], $this->options($assetType, $specification), $value);
        } catch (\InvalidArgumentException $e) {
            throw ErrorDominio::noProcesable($e->getMessage());
        }
    }
}
