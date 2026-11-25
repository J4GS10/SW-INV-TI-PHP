<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Application;

use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Listado;
use App\Dominio\Reloj;
use App\GoodsReceipt\Domain\ReceiptInput;
use App\GoodsReceipt\Infrastructure\GoodsReceiptRepository;
use App\Seguridad\Alcance;

/** Reads goods receipts within the user's scope and shapes them for the API. */
final readonly class ReceiptPresenter
{
    public function __construct(private GoodsReceiptRepository $receipts, private Alcance $scope)
    {
    }

    /** @return array<string, mixed> locked and in scope */
    public function required(int $id, bool $lock = false): array
    {
        return $this->receipts->find($id, $lock) ?? throw ErrorDominio::noEncontrado('GOODS_RECEIPT_NOT_FOUND');
    }

    /** @return array<string, mixed> */
    public function present(int $id): array
    {
        $receipt = $this->required($id);

        return [...self::headerToApi($receipt),
            'mine' => (string) $receipt['GRC_USU_CREATED_BY'] === $this->scope->actual()?->usuarioId,
            'lines' => array_map(self::lineToApi(...), $this->receipts->lines($id)),
            'events' => array_map(static fn (array $e): array => ['event' => $e['GRE_EVENT'], 'at' => Reloj::iso(Reloj::desdeBd((string) $e['GRE_CREATED_AT']) ?? Reloj::ahora()),
                'user' => $e['USERNAME'], 'detail' => null === $e['GRE_DETAIL'] ? null : json_decode((string) $e['GRE_DETAIL'], true)], $this->receipts->events($id)),
        ];
    }

    /** @return array{total: int, items: list<array<string, mixed>>} a page of the receipts within the user's scope and how many match */
    public function list(?string $status, ?string $text, Listado $page): array
    {
        [$total, $rows] = $this->receipts->search($status, $text, $page);

        return ['total' => $total, 'items' => array_map(self::headerToApi(...), $rows)];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private static function headerToApi(array $r): array
    {
        $when = null === $r['GRC_CONFIRMED_AT'] ? null : Reloj::desdeBd((string) $r['GRC_CONFIRMED_AT']);

        return ['id' => (int) $r['GRC_GOODS_RECEIPT'], 'code' => $r['GRC_CODE'], 'status' => $r['GRC_STATUS'], 'supplier_id' => (int) $r['GRC_PRV_SUPPLIER'], 'supplier' => $r['SUPPLIER_NAME'],
            'document_number' => $r['GRC_DOCUMENT_NUMBER'], 'received_date' => $r['RECEIVED_DATE'], 'site_id' => (int) $r['GRC_SED_SITE'], 'site' => $r['SITE_NAME'], 'currency' => $r['GRC_CURRENCY'],
            'notes' => $r['GRC_NOTES'], 'difference_reason' => $r['GRC_DIFFERENCE_REASON'], 'approval_id' => ReceiptInput::intOrNull($r['GRC_APR_APPROVAL']), 'confirmed_at' => null === $when ? null : Reloj::iso($when),
            'created_by' => null === $r['GRC_USU_CREATED_BY'] ? null : Ident::aTexto((string) $r['GRC_USU_CREATED_BY'])];
    }

    /**
     * @param array<string, mixed> $l
     *
     * @return array<string, mixed>
     */
    private static function lineToApi(array $l): array
    {
        $int = ReceiptInput::intOrNull(...);

        return ['id' => (int) $l['GRL_GOODS_RECEIPT_LINE'], 'number' => (int) $l['GRL_LINE_NUMBER'], 'item_text' => $l['GRL_ITEM_TEXT'], 'quantity' => (int) $l['GRL_QUANTITY_DECLARED'],
            'received_quantity' => $int($l['GRL_QUANTITY_RECEIVED']), 'unit_cost' => bcadd(\App\Dominio\Decimal::de($l['GRL_UNIT_COST']), '0', 2), 'warranty_months' => $int($l['GRL_WARRANTY_MONTHS']),
            'line_type' => $l['GRL_LINE_TYPE'], 'asset_type_id' => $int($l['GRL_TAC_ASSET_TYPE']), 'asset_type' => $l['TYPE_NAME'], 'model_id' => $int($l['GRL_MOD_MODEL']),
            'product' => null === $l['MODEL_NAME'] ? null : trim($l['BRAND_NAME'].' '.$l['MODEL_NAME']), 'consumable_id' => $int($l['GRL_CON_CONSUMABLE']), 'consumable_name' => $l['GRL_CONSUMABLE_NAME'],
            'software_id' => $int($l['GRL_SOF_SOFTWARE']), 'license_id' => $int($l['GRL_LIC_LICENSE']), 'keys_completed' => null !== $l['GRL_KEYS_COMPLETED_AT'],
            'units_total' => (int) $l['UNITS_TOTAL'], 'units_done' => (int) $l['UNITS_DONE']];
    }
}
