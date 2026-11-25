<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Application;

use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\GoodsReceipt\Domain\ReceiptInput;
use App\GoodsReceipt\Domain\SpecificationValue;
use App\GoodsReceipt\Infrastructure\GoodsReceiptRepository;
use App\GoodsReceipt\Infrastructure\ReceivedInventoryStore;
use App\Inventario\Activos;
use App\Inventario\Infrastructure\Kardex;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;

/**
 * After confirmation the technician fills only what is missing: serial, hostname and required
 * specifications of each unit, and the keys of each license. The receipt closes when nothing is left.
 */
final readonly class UnitCompletion
{
    public function __construct(
        private ReceivedInventoryStore $inventory,
        private \App\Inventario\Infrastructure\ActivosStore $assetStore,
        private GoodsReceiptRepository $receipts,
        private ReceiptPresenter $presenter,
        private InventoryEntry $entry,
        private Alcance $scope,
        private Transaccion $transaction,
        private Kardex $kardex,
        private GobiernoRepositorio $audit,
        private \App\Inventario\Licencias $licenses,
        private Activos $assets,
        private \App\Inventario\EspecificacionesDeActivo $specifications,
        private \App\Catalogo\SpecificationRules $specificationRules,
    ) {
    }

    /**
     * The asset stops being pending once it has a serial and every required specification.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function completeUnit(string $asset, array $input, string $user, ?string $ip): array
    {
        return $this->transaction->ejecutar(function () use ($asset, $input, $user, $ip): array {
            $row = $this->assets->filaO404($asset);
            $this->inventory->lockAsset($asset);
            $receipt = $this->receipts->receiptOfAsset($asset);
            if (null === $receipt || 1 !== (int) $row['ACT_Pending_Completion']) {
                throw ErrorDominio::conflicto('ASSET_NOT_PENDING_COMPLETION');
            }
            $serial = ReceiptInput::requiredText($input['serial'] ?? null, 100, 'SERIAL_NUMBER_REQUIRED');
            if ($this->serialExists($serial, $asset)) {
                throw ErrorDominio::conflicto('SERIAL_NUMBER_ALREADY_EXISTS');
            }
            $hostname = ReceiptInput::optionalText($input['hostname'] ?? null, 100, 'hostname');
            if (null !== $hostname && $this->hostnameExists($hostname, $asset)) {
                throw ErrorDominio::conflicto('HOSTNAME_ALREADY_EXISTS');
            }
            $missing = $this->applySpecifications($asset, $row, $input['specifications'] ?? [], $user, $ip);
            $pending = [] !== $missing ? 1 : 0;
            $this->inventory->completeAsset($asset, $serial, $hostname, 1 === $pending);
            $fresh = $this->assets->filaO404($asset);
            $this->kardex->registrar('CAMBIO_DATOS', activo: $fresh, usuario: $user, motivo: 'Datos completados tras el ingreso',
                detalle: ['serie' => ['antes' => null, 'despues' => $serial], 'hostname' => ['antes' => $row['ACT_Hostname'], 'despues' => $hostname ?? $row['ACT_Hostname']]]);
            $this->audit->registrarAuditoria('UPDATE', 'INV_ACTIVO', ['codigo' => $row['ACT_Codigo_Interno'], 'serie' => $serial, 'origen' => 'Completar ingreso'], $user, $ip,
                sedeId: null === $row['SED_Sede'] ? null : (int) $row['SED_Sede']);
            $status = $this->entry->closeIfDone($receipt, $user);

            return ['asset_id' => Ident::aTexto($asset), 'code' => $row['ACT_Codigo_Interno'], 'pending' => 1 === $pending, 'missing_specifications' => $missing, 'receipt_status' => $status];
        });
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function completeLicenseKeys(int $line, array $keys, string $user, ?string $ip): array
    {
        return $this->transaction->ejecutar(function () use ($line, $keys, $user, $ip): array {
            $row = $this->receipts->lineForUpdate($line) ?? throw ErrorDominio::noEncontrado('GOODS_RECEIPT_LINE_NOT_FOUND');
            if ('LICENSE' !== $row['GRL_LINE_TYPE'] || null === $row['GRL_LIC_LICENSE']) {
                throw ErrorDominio::conflicto('GOODS_RECEIPT_LINE_NOT_A_LICENSE');
            }
            $receipt = (int) $row['GRL_GRC_GOODS_RECEIPT'];
            $this->licenses->addKeys((int) $row['GRL_LIC_LICENSE'], $keys, $user, $ip);
            $this->receipts->updateLine($line, ['GRL_KEYS_COMPLETED_AT' => Reloj::aBd(Reloj::ahora())]);
            $this->receipts->addEvent($receipt, 'LICENSE_KEYS_COMPLETED', $user, ['line' => $line, 'keys' => \count($keys)]);
            $this->entry->closeIfDone($receipt, $user);

            return $this->presenter->present($receipt);
        });
    }

    /** @return list<array<string, mixed>> */
    public function pendingUnits(): array
    {
        $units = $this->receipts->pendingUnits(500);
        // Los valores permitidos dependen del tipo, no de la unidad: una consulta por tipo, no una por dato de cada unidad.
        $options = [];
        foreach (array_unique(array_map(static fn (array $u): int => (int) $u['TAC_Tipo_Activo'], $units)) as $type) {
            $options[$type] = $this->specificationRules->optionsByType($type);
        }

        return array_map(fn (array $u): array => [
            'asset_id' => Ident::aTexto((string) $u['ACT_Activo']), 'code' => $u['ACT_Codigo_Interno'], 'item_text' => $u['GRL_ITEM_TEXT'], 'receipt_id' => (int) $u['GRC_GOODS_RECEIPT'],
            'receipt_code' => $u['GRC_CODE'], 'document_number' => $u['GRC_DOCUMENT_NUMBER'], 'site' => $u['SED_Nombre'], 'type' => $u['TAC_Nombre'],
            'product' => trim($u['MAR_Nombre'].' '.$u['MOD_Nombre']),
            'specifications' => array_map(fn (array $s): array => [
                'type_id' => (int) $s['TES_Tipo_Especificacion'], 'name' => $s['TES_Nombre'], 'unit' => $s['TES_Unidad_Medida'], 'data_type' => $s['TES_Data_Type'],
                'min' => null === $s['TES_Min_Value'] ? null : (float) $s['TES_Min_Value'], 'max' => null === $s['TES_Max_Value'] ? null : (float) $s['TES_Max_Value'],
                'required' => 1 === (int) $s['ETA_Obligatoria'], 'options' => $options[(int) $u['TAC_Tipo_Activo']][(int) $s['TES_Tipo_Especificacion']] ?? [], 'value' => $s['CURRENT_VALUE'] ?? $s['DEFAULT_VALUE'],
            ], $this->receipts->specificationsFor((int) $u['TAC_Tipo_Activo'], (int) $u['MOD_Modelo'], (string) $u['ACT_Activo'])),
        ], $units);
    }

    public function serialExists(string $serial, ?string $except = null): bool
    {
        // Checked without site scope: an asset elsewhere also counts. Only yes or no goes back.
        return $this->scope->elevado(fn (): bool => $this->inventory->serialTaken($serial, $except));
    }

    public function hostnameExists(string $hostname, ?string $except = null): bool
    {
        return $this->scope->elevado(fn (): bool => $this->assetStore->hostnameEnUso($hostname, $except));
    }

    /**
     * @param array<string, mixed> $asset
     *
     * @return list<string> names of the required specifications still missing
     */
    private function applySpecifications(string $id, array $asset, mixed $given, string $user, ?string $ip): array
    {
        $values = [];
        foreach (\is_array($given) ? $given : [] as $spec) {
            $type = \is_array($spec) ? ReceiptInput::integer($spec['type_id'] ?? null) : null;
            $value = \is_array($spec) ? ($spec['value'] ?? '') : null;
            if (null === $type || !(\is_string($value) || \is_int($value) || \is_float($value)) || mb_strlen((string) $value) > 255) {
                throw ReceiptInput::invalid('specifications', $spec);
            }
            $values[$type] = (string) $value;
        }
        $missing = [];
        foreach ($this->receipts->specificationsFor((int) $asset['TAC_Tipo_Activo'], (int) $asset['MOD_Modelo'], $id) as $spec) {
            $type = (int) $spec['TES_Tipo_Especificacion'];
            $value = $values[$type] ?? null;
            if (null !== $value && '' !== trim($value)) {
                try {
                    $normal = SpecificationValue::normalize((string) $spec['TES_Data_Type'], null === $spec['TES_Min_Value'] ? null : (float) $spec['TES_Min_Value'],
                        null === $spec['TES_Max_Value'] ? null : (float) $spec['TES_Max_Value'], $this->specificationRules->options((int) $asset['TAC_Tipo_Activo'], $type), $value);
                } catch (\InvalidArgumentException $e) {
                    throw ErrorDominio::invalido("{$e->getMessage()}:{$spec['TES_Nombre']}");
                }
                $this->saveSpecification($id, $type, $spec['CURRENT_VALUE'], $normal, $user, $ip);
                continue;
            }
            if (1 === (int) $spec['ETA_Obligatoria'] && null === $spec['CURRENT_VALUE']) {
                $missing[] = (string) $spec['TES_Nombre'];
            }
        }

        return $missing;
    }

    private function saveSpecification(string $asset, int $type, mixed $current, string $value, string $user, ?string $ip): void
    {
        if (null === $current) {
            $this->specifications->agregarEspecificacion($asset, $type, $value, 'Datos completados tras el ingreso', $user, $ip);

            return;
        }
        if ((string) $current === $value) {
            return;
        }
        $id = $this->inventory->specificationId($asset, $type);
        $this->specifications->cambiarEspecificacion($id, $value, 'Datos completados tras el ingreso', $user, $ip);
    }
}
