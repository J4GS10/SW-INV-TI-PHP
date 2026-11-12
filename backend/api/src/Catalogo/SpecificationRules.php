<?php

declare(strict_types=1);

namespace App\Catalogo;

use App\GoodsReceipt\Domain\SpecificationValue;
use App\Dominio\ErrorDominio;
use App\Catalogo\Infrastructure\SpecificationStore;

/** Allowed values per asset type and specification, and the single place where a value is validated. */
final readonly class SpecificationRules
{
    public function __construct(private SpecificationStore $store)
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
        return $this->store->allowedValues($assetType);
    }

    /** @param list<string> $values */
    public function replaceOptions(int $assetType, int $specification, array $values): void
    {
        $this->store->clearAllowedValues($assetType, $specification);
        $seen = [];
        $atomic = array_merge(...array_map(SpecificationValue::splitList(...), $values));
        foreach ($atomic as $order => $value) {
            $key = mb_strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->store->addAllowedValue($assetType, $specification, mb_substr($value, 0, 255), ($order + 1) * 10);
        }
    }

    public function removeOptionsOfType(int $assetType): void
    {
        $this->store->clearAllowedValues($assetType);
    }

    /**
     * @return string the value as it must be stored
     *
     * @throws ErrorDominio 422 with the reason, the same codes the screens show while typing
     */
    public function normalize(int $assetType, int $specification, string $value): string
    {
        $type = $this->store->specificationType($specification) ?? throw ErrorDominio::noEncontrado('SPECIFICATION_TYPE_NOT_FOUND');
        try {
            return SpecificationValue::normalize($type['dataType'], $type['min'], $type['max'], $this->options($assetType, $specification), $value);
        } catch (\InvalidArgumentException $e) {
            throw ErrorDominio::noProcesable($e->getMessage());
        }
    }
}
