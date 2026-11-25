<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Application;

use App\Dominio\ErrorDominio;
use App\GoodsReceipt\Domain\ReceiptInput;
use App\GoodsReceipt\Infrastructure\GoodsReceiptRepository;
use App\Inventario\ReglasClase;

/** IT's count and classification of each line: what it is (asset, consumable, license) and how many truly arrived. */
final readonly class LineClassifier
{
    public function __construct(private GoodsReceiptRepository $receipts, private ReglasClase $classRules)
    {
    }

    /** @return list<array{line: int, item: string, declared: int, received: int}> the lines where the count differs */
    public function classify(int $id, mixed $input, string $user): array
    {
        $byId = [];
        foreach (\is_array($input) ? $input : [] as $line) {
            if (!\is_array($line) || null === ($lineId = ReceiptInput::integer($line['id'] ?? null))) {
                throw ErrorDominio::invalido('GOODS_RECEIPT_ALL_LINES_REQUIRED');
            }
            $byId[$lineId] = $line;
        }
        $differences = [];
        foreach ($this->receipts->lines($id) as $line) {
            $lineId = (int) $line['GRL_GOODS_RECEIPT_LINE'];
            $given = $byId[$lineId] ?? throw ErrorDominio::invalido('GOODS_RECEIPT_ALL_LINES_REQUIRED');
            $received = ReceiptInput::integer($given['received_quantity'] ?? null);
            if (null === $received) {
                throw ErrorDominio::invalido('GOODS_RECEIPT_INVALID_QUANTITY');
            }
            $this->receipts->updateLine($lineId, [...$this->classification($given), 'GRL_QUANTITY_RECEIVED' => $received, 'GRL_USU_CLASSIFIED_BY' => $user]);
            if ($received !== (int) $line['GRL_QUANTITY_DECLARED']) {
                $differences[] = ['line' => $lineId, 'item' => (string) $line['GRL_ITEM_TEXT'], 'declared' => (int) $line['GRL_QUANTITY_DECLARED'], 'received' => $received];
            }
        }

        return $differences;
    }

    /**
     * @param array<string, mixed> $given
     *
     * @return array<string, scalar|null>
     */
    private function classification(array $given): array
    {
        $type = $given['line_type'] ?? '';
        if (!\is_string($type) || !\in_array($type, ReceiptInput::LINE_TYPES, true)) {
            throw ErrorDominio::invalido('GOODS_RECEIPT_LINE_REQUIRES_CLASSIFICATION');
        }
        $empty = ['GRL_LINE_TYPE' => $type, 'GRL_TAC_ASSET_TYPE' => null, 'GRL_MOD_MODEL' => null, 'GRL_CON_CONSUMABLE' => null, 'GRL_CONSUMABLE_NAME' => null, 'GRL_CONSUMABLE_UNIT' => null,
            'GRL_SOF_SOFTWARE' => null, 'GRL_TLI_LICENSE_TYPE' => null];

        return match ($type) {
            'ASSET' => [...$empty, ...$this->assetClassification($given)],
            'CONSUMABLE' => [...$empty, ...self::consumableClassification($given)],
            'LICENSE' => [...$empty, 'GRL_SOF_SOFTWARE' => ReceiptInput::integer($given['software_id'] ?? null) ?: throw ErrorDominio::invalido('GOODS_RECEIPT_LINE_REQUIRES_SOFTWARE'),
                'GRL_TLI_LICENSE_TYPE' => ReceiptInput::optionalId($given['license_type_id'] ?? null, 'lines.license_type_id')],
            default => $empty,
        };
    }

    /**
     * @param array<string, mixed> $given
     *
     * @return array<string, int>
     */
    private function assetClassification(array $given): array
    {
        $assetType = ReceiptInput::integer($given['asset_type_id'] ?? null) ?? 0;
        $model = ReceiptInput::integer($given['model_id'] ?? null) ?? 0;
        if (0 === $assetType || 0 === $model) {
            throw ErrorDominio::invalido('GOODS_RECEIPT_LINE_REQUIRES_MODEL');
        }
        $this->classRules->exigirNoInsumo($assetType);

        return ['GRL_TAC_ASSET_TYPE' => $assetType, 'GRL_MOD_MODEL' => $model];
    }

    /**
     * @param array<string, mixed> $given
     *
     * @return array<string, scalar|null>
     */
    private static function consumableClassification(array $given): array
    {
        $existing = ReceiptInput::optionalId($given['consumable_id'] ?? null, 'lines.consumable_id');
        $name = ReceiptInput::optionalText($given['consumable_name'] ?? null, 100, 'lines.consumable_name');
        if (null === $existing && null === $name) {
            throw ErrorDominio::invalido('GOODS_RECEIPT_LINE_REQUIRES_CONSUMABLE');
        }

        return ['GRL_CON_CONSUMABLE' => $existing, 'GRL_CONSUMABLE_NAME' => $name, 'GRL_CONSUMABLE_UNIT' => ReceiptInput::optionalText($given['unit'] ?? null, 20, 'lines.unit') ?? 'unidad'];
    }
}
