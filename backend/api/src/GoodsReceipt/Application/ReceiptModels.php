<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Application;

use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\GoodsReceipt\Domain\ReceiptInput;
use App\GoodsReceipt\Infrastructure\GoodsReceiptRepository;
use App\GoodsReceipt\Infrastructure\ReceivedInventoryStore;
use App\Repositorio\GobiernoRepositorio;

/** Brands, models and factory specification values that IT creates while classifying a receipt. */
final readonly class ReceiptModels
{
    public function __construct(
        private ReceivedInventoryStore $inventory,
        private GoodsReceiptRepository $receipts,
        private Transaccion $transaction,
        private GobiernoRepositorio $audit,
        private \App\Catalogo\SpecificationRules $specificationRules,
    ) {
    }

    /** @return array<string, mixed> */
    public function createModel(int $assetType, string $brand, string $model, string $user, ?string $ip): array
    {
        $brand = ReceiptInput::requiredText($brand, 100, 'BRAND_NAME_REQUIRED');
        $model = ReceiptInput::requiredText($model, 150, 'MODEL_NAME_REQUIRED');

        return $this->transaction->ejecutar(function () use ($assetType, $brand, $model, $user, $ip): array {
            if (!$this->inventory->assetTypeExists($assetType)) {
                throw ErrorDominio::noEncontrado('ASSET_TYPE_NOT_FOUND');
            }
            $brandId = $this->inventory->brandByName($brand);
            if (null === $brandId) {
                $brandId = $this->inventory->createBrand($brand);
                $this->audit->registrarAuditoria('CREATE', 'INV_MARCA', ['nombre' => $brand, 'origen' => 'Confirmación de ingreso'], $user, $ip);
            }
            $existing = $this->inventory->modelByName($brandId, $model);
            if (null !== $existing) {
                return ['model_id' => $existing, 'brand_id' => $brandId, 'created' => false];
            }
            $id = $this->inventory->createModel($model, $brandId, $assetType);
            $this->audit->registrarAuditoria('CREATE', 'INV_MODELO', ['nombre' => $model, 'marca' => $brand, 'origen' => 'Confirmación de ingreso'], $user, $ip);

            return ['model_id' => $id, 'brand_id' => $brandId, 'created' => true];
        });
    }

    /**
     * @param array<int|string, string> $values specification type => factory value
     */
    public function setModelDefaults(int $model, array $values, string $user, ?string $ip): void
    {
        $assetType = $this->assetTypeOfModel($model);
        $this->transaction->ejecutar(function () use ($model, $assetType, $values, $user, $ip): void {
            $defaults = [];
            foreach ($values as $type => $value) {
                if ('' !== trim($value)) {
                    $defaults[(int) $type] = $this->specificationRules->normalize($assetType, (int) $type, $value);
                }
            }
            $this->inventory->replaceModelDefaults($model, $defaults);
            $this->audit->registrarAuditoria('UPDATE', 'CAT_MODEL_SPECIFICATION', ['modelo' => $model, 'valores' => $values], $user, $ip);
        });
    }

    /** @return list<array<string, mixed>> the specifications a model's type asks for, with the factory value of each */
    public function modelDefaults(int $model): array
    {
        $assetType = $this->assetTypeOfModel($model);
        $options = $this->specificationRules->optionsByType($assetType);

        return array_map(static fn (array $s): array => [
            'type_id' => (int) $s['TES_Tipo_Especificacion'], 'name' => $s['TES_Nombre'], 'unit' => $s['TES_Unidad_Medida'], 'data_type' => $s['TES_Data_Type'],
            'min' => null === $s['TES_Min_Value'] ? null : (float) $s['TES_Min_Value'], 'max' => null === $s['TES_Max_Value'] ? null : (float) $s['TES_Max_Value'],
            'required' => 1 === (int) $s['ETA_Obligatoria'], 'options' => $options[(int) $s['TES_Tipo_Especificacion']] ?? [], 'value' => $s['DEFAULT_VALUE'],
        ], $this->receipts->specificationsFor($assetType, $model, null));
    }

    private function assetTypeOfModel(int $model): int
    {
        return $this->inventory->assetTypeOfModel($model) ?? throw ErrorDominio::noEncontrado('MODEL_NOT_FOUND');
    }
}
