<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Application;

use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\GoodsReceipt\Domain\GoodsReceiptStatus;
use App\GoodsReceipt\Infrastructure\GoodsReceiptRepository;
use App\GoodsReceipt\Infrastructure\ReceivedInventoryStore;
use App\Inventario\EstadosActivo;
use App\Inventario\Infrastructure\Kardex;
use App\Inventario\Infrastructure\Secuencias;
use App\Inventario\Valoracion;
use App\Repositorio\GobiernoRepositorio;

/**
 * What a confirmed receipt puts into inventory: pending assets in storage, consumable stock at the
 * weighted average cost and licenses, each one recorded in the kardex and the audit trail.
 */
final readonly class InventoryEntry
{
    public function __construct(
        private ReceivedInventoryStore $inventory,
        private \App\Inventario\Infrastructure\DatosActivos $assetData,
        private GoodsReceiptRepository $receipts,
        private ReceiptPresenter $presenter,
        private Kardex $kardex,
        private GobiernoRepositorio $audit,
        private Secuencias $sequences,
        private EstadosActivo $states,
        private \App\Inventario\Licencias $licenses,
    ) {
    }

    public function enter(int $id, string $user, ?string $ip, ?int $approval): void
    {
        $receipt = $this->presenter->required($id);
        $site = (int) $receipt['GRC_SED_SITE'];
        $document = ['INGRESO', "{$receipt['GRC_CODE']} · {$receipt['GRC_DOCUMENT_NUMBER']}"];
        $origin = "Ingreso {$receipt['GRC_CODE']}";
        $detail = null === $approval ? null : ['aprobacion' => $approval];
        $created = 0;
        foreach ($this->receipts->lines($id) as $line) {
            $received = (int) $line['GRL_QUANTITY_RECEIVED'];
            if (0 === $received) {
                continue;
            }
            match ($line['GRL_LINE_TYPE']) {
                'ASSET' => $created += $this->createPendingAssets($receipt, $line, $received, $document, $origin, $detail, $user, $ip),
                'CONSUMABLE' => $this->addStock($receipt, $line, $received, $document, $origin, $detail, $user, $ip),
                'LICENSE' => $this->createLicense($receipt, $line, $received, $document, $origin, $user, $ip),
                default => null,
            };
        }
        $this->receipts->updateHeader($id, ['GRC_STATUS' => GoodsReceiptStatus::Confirmed->value]);
        $this->receipts->addEvent($id, 'CONFIRMED', $user, ['assets_created' => $created, 'approval' => $approval]);
        $this->closeIfDone($id, $user);
        $this->audit->registrarAuditoria('CONFIRM', 'PUR_GOODS_RECEIPT', ['id' => $id, 'code' => $receipt['GRC_CODE'], 'assets_created' => $created, 'approval' => $approval], $user, $ip, sedeId: $site);
    }

    /** A confirmed receipt is completed once no unit and no license key is left to fill in. */
    public function closeIfDone(int $id, string $user): string
    {
        if ($this->receipts->hasPendingWork($id)) {
            return GoodsReceiptStatus::Confirmed->value;
        }
        $this->receipts->updateHeader($id, ['GRC_STATUS' => GoodsReceiptStatus::Completed->value]);
        $this->receipts->addEvent($id, 'COMPLETED', $user);

        return GoodsReceiptStatus::Completed->value;
    }

    /**
     * @param array<string, mixed>       $receipt
     * @param array<string, mixed>       $line
     * @param array{0: string, 1: string} $document
     * @param array<string, mixed>|null  $detail
     */
    private function createPendingAssets(array $receipt, array $line, int $received, array $document, string $origin, ?array $detail, string $user, ?string $ip): int
    {
        $prefix = $this->inventory->prefixOfAssetType((int) $line['GRL_TAC_ASSET_TYPE']);
        if (null === $prefix) {
            throw ErrorDominio::invalido('ASSET_TYPE_HAS_NO_PREFIX_CONFIGURED_FOR_AUTO_GENERATION');
        }
        $storage = $this->states->idDe(EstadosActivo::BODEGA) ?? throw ErrorDominio::interno('OPERATIONAL_STATE_NOT_FOUND');
        $site = (int) $receipt['GRC_SED_SITE'];
        $defaults = array_filter($this->receipts->specificationsFor((int) $line['GRL_TAC_ASSET_TYPE'], (int) $line['GRL_MOD_MODEL'], null), static fn (array $s): bool => null !== $s['DEFAULT_VALUE']);
        for ($i = 0; $i < $received; ++$i) {
            $id = Ident::nuevo();
            $code = $this->sequences->siguiente("ASSET_{$prefix}", $prefix);
            $months = null === $line['GRL_WARRANTY_MONTHS'] ? null : (int) $line['GRL_WARRANTY_MONTHS'];
            $this->inventory->insertPendingAsset(['id' => $id, 'code' => $code, 'bought' => $receipt['RECEIVED_DATE'], 'cost' => $line['GRL_UNIT_COST'], 'currency' => $receipt['GRC_CURRENCY'],
                'invoice' => mb_substr((string) $receipt['GRC_DOCUMENT_NUMBER'], 0, 60), 'supplier' => $receipt['GRC_PRV_SUPPLIER'], 'model' => $line['GRL_MOD_MODEL'],
                'type' => $line['GRL_TAC_ASSET_TYPE'], 'state' => $storage, 'site' => $site], $months);
            $this->receipts->linkUnit((int) $line['GRL_GOODS_RECEIPT_LINE'], $id);
            foreach ($defaults as $default) {
                $this->assetData->agregarEspecificacion($id, $default['TES_Tipo_Especificacion'], $default['DEFAULT_VALUE']);
            }
            $this->kardex->registrar('RECEPCION', activo: ['ACT_Activo' => $id, 'ACT_Codigo_Interno' => $code, 'ACT_Serie_Fabricante' => null, 'SED_Sede' => $site], destino: ['sede' => $site],
                costo: (string) $line['GRL_UNIT_COST'], moneda: (string) $receipt['GRC_CURRENCY'], documento: $document, usuario: $user, motivo: $origin, detalle: $detail);
            $this->audit->registrarAuditoria('CREATE', 'INV_ACTIVO', ['codigo' => $code, 'origen' => $origin, 'sede' => $site], $user, $ip, sedeId: $site);
        }

        return $received;
    }

    /**
     * @param array<string, mixed>       $receipt
     * @param array<string, mixed>       $line
     * @param array{0: string, 1: string} $document
     * @param array<string, mixed>|null  $detail
     */
    private function addStock(array $receipt, array $line, int $received, array $document, string $origin, ?array $detail, string $user, ?string $ip): void
    {
        $site = (int) $receipt['GRC_SED_SITE'];
        $consumable = $this->resolveConsumable($line, $site);
        $row = $this->inventory->consumableForUpdate($consumable) ?: throw ErrorDominio::noEncontrado('CONSUMABLE_NOT_FOUND');
        [$average, $currency, $cost] = Valoracion::entrada((int) $row['CON_Stock_Actual'], null === $row['CON_Costo_Unitario'] ? null : (string) $row['CON_Costo_Unitario'], $row['CON_Moneda'],
            $received, (string) $line['GRL_UNIT_COST'], (string) $receipt['GRC_CURRENCY']);
        $balance = (int) $row['CON_Stock_Actual'] + $received;
        $this->inventory->recordStockEntry($consumable, $received, $balance, $average, $currency, $origin, $user);
        $this->kardex->registrar('CONSUMIBLE_ENTRADA', consumible: $consumable, referencia: (string) $row['CON_Nombre'], cantidad: $received, saldo: $balance, costo: $cost, moneda: $currency,
            saldoValor: Valoracion::valorSaldo($balance, $average), destino: ['sede' => $site], documento: $document, usuario: $user, motivo: $origin, detalle: $detail);
        $this->receipts->updateLine((int) $line['GRL_GOODS_RECEIPT_LINE'], ['GRL_CON_CONSUMABLE' => $consumable]);
        $this->audit->registrarAuditoria('STOCK_IN', 'INV_CONSUMIBLE', ['id' => $consumable, 'cantidad' => $received, 'stock_resultante' => $balance, 'motivo' => $origin], $user, $ip, sedeId: $site);
    }

    /** @param array<string, mixed> $line */
    private function resolveConsumable(array $line, int $site): int
    {
        if (null !== $line['GRL_CON_CONSUMABLE']) {
            return (int) $line['GRL_CON_CONSUMABLE'];
        }
        return $this->inventory->consumableByName($site, $line['GRL_CONSUMABLE_NAME'])
            ?? $this->inventory->createConsumable($line['GRL_CONSUMABLE_NAME'], $line['GRL_CONSUMABLE_UNIT'] ?? 'unidad', $site);
    }

    /**
     * @param array<string, mixed>       $receipt
     * @param array<string, mixed>       $line
     * @param array{0: string, 1: string} $document
     */
    private function createLicense(array $receipt, array $line, int $received, array $document, string $origin, string $user, ?string $ip): void
    {
        $license = $this->licenses->crearLicenciaEnTransaccion(['SOF_Software' => $line['GRL_SOF_SOFTWARE'], 'TLI_Tipo_Licencia' => $line['GRL_TLI_LICENSE_TYPE'], 'LIC_Cantidad_Total' => $received,
            'LIC_Costo' => bcmul(\App\Dominio\Decimal::de($line['GRL_UNIT_COST']), (string) $received, 2), 'LIC_Moneda' => $receipt['GRC_CURRENCY'], 'LIC_Proveedor' => $receipt['GRC_PRV_SUPPLIER']],
            [], [], $user, $ip, $origin, $document, (int) $receipt['GRC_SED_SITE']);
        $this->receipts->updateLine((int) $line['GRL_GOODS_RECEIPT_LINE'], ['GRL_LIC_LICENSE' => $license]);
    }
}
