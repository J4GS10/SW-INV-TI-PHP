<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Application;

use App\Correo\Notificador;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Reloj;
use App\GoodsReceipt\Domain\GoodsReceiptStatus;
use App\GoodsReceipt\Domain\ReceiptInput;
use App\GoodsReceipt\Infrastructure\GoodsReceiptRepository;
use App\GoodsReceipt\Infrastructure\ReceivedInventoryStore;
use App\Inventario\Infrastructure\Secuencias;
use App\Inventario\Solicitudes;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;

/**
 * Goods receipt use cases. Purchasing registers what arrived; IT classifies and confirms. Nothing reaches
 * inventory before confirmation, a difference waits for another administrator and every step is recorded.
 * Entering inventory ({@see InventoryEntry}), completing units ({@see UnitCompletion}), models
 * ({@see ReceiptModels}) and reading ({@see ReceiptPresenter}) live in their own classes.
 */
final readonly class GoodsReceiptService
{
    public function __construct(
        private ReceivedInventoryStore $inventory,
        private GoodsReceiptRepository $receipts,
        private ReceiptPresenter $presenter,
        private LineClassifier $classifier,
        private InventoryEntry $entry,
        private Alcance $scope,
        private Transaccion $transaction,
        private GobiernoRepositorio $audit,
        private Secuencias $sequences,
        private Solicitudes $approvals,
        private Notificador $notifier,
        private \App\Config\Ajustes $settings,
    ) {
    }

    /** "Today" for people is the organization's date, not UTC: after 18:00 in Guatemala UTC is already tomorrow. */
    private function today(): string
    {
        return Reloj::ahora()->setTimezone(new \DateTimeZone($this->settings->zonaHoraria))->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function register(array $input, string $user, ?string $ip): array
    {
        $header = $this->validHeader($input);
        $lines = ReceiptInput::lines($input['lines'] ?? null);

        return $this->transaction->ejecutar(function () use ($header, $lines, $user, $ip): array {
            $site = (int) $this->scope->exigirSede($header['site_id']);
            $this->requireFreeDocument($header['supplier_id'], $header['document_number']);
            $code = $this->sequences->siguiente('GOODS_RECEIPT', 'ING-');
            $id = $this->receipts->insertHeader(['GRC_CODE' => $code, 'GRC_PRV_SUPPLIER' => $header['supplier_id'], 'GRC_DOCUMENT_NUMBER' => $header['document_number'],
                'GRC_SED_SITE' => $site, 'GRC_CURRENCY' => $header['currency'], 'GRC_NOTES' => $header['notes'], 'GRC_USU_CREATED_BY' => $user], $header['received_date']);
            $this->insertLines($id, $lines);
            $this->receipts->addEvent($id, 'REGISTERED', $user, ['document' => $header['document_number'], 'lines' => \count($lines)]);
            $this->audit->registrarAuditoria('CREATE', 'PUR_GOODS_RECEIPT', ['id' => $id, 'code' => $code, 'document' => $header['document_number']], $user, $ip, sedeId: $site);
            $this->transaction->alConfirmar(fn () => $this->notifier->enviar('ingreso_registrado', ['codigo' => $code, 'documento' => $header['document_number'], 'lineas' => \count($lines)]));

            return $this->presenter->present($id);
        });
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(int $id, array $input, string $user, ?string $ip): array
    {
        return $this->transaction->ejecutar(function () use ($id, $input, $user, $ip): array {
            $receipt = $this->editable($id);
            $changes = [];
            if (\array_key_exists('notes', $input)) {
                $changes['GRC_NOTES'] = ReceiptInput::optionalText($input['notes'], 500);
            }
            if (\array_key_exists('document_number', $input)) {
                $document = ReceiptInput::requiredText($input['document_number'], 60, 'GOODS_RECEIPT_REQUIRES_DOCUMENT');
                if (0 !== strcasecmp(trim((string) $receipt['GRC_DOCUMENT_NUMBER']), $document)) {
                    $this->requireFreeDocument((int) $receipt['GRC_PRV_SUPPLIER'], $document);
                }
                $changes['GRC_DOCUMENT_NUMBER'] = $document;
            }
            $changes['GRC_USU_UPDATED_BY'] = $user;
            $changes['GRC_UPDATED_AT'] = Reloj::aBd(Reloj::ahora());
            $this->receipts->updateHeader($id, $changes);
            if (\array_key_exists('lines', $input)) {
                $lines = ReceiptInput::lines($input['lines']);
                $this->receipts->deleteLines($id);
                $this->insertLines($id, $lines);
            }
            $this->receipts->addEvent($id, 'UPDATED', $user, ['fields' => array_keys($input)]);
            $this->audit->registrarAuditoria('UPDATE', 'PUR_GOODS_RECEIPT', ['id' => $id, 'fields' => array_keys($input)], $user, $ip, sedeId: (int) $receipt['GRC_SED_SITE']);

            return $this->presenter->present($id);
        });
    }

    /** @return array<string, mixed> */
    public function cancel(int $id, string $reason, string $user, ?string $ip): array
    {
        return $this->transaction->ejecutar(function () use ($id, $reason, $user, $ip): array {
            $receipt = $this->editable($id);
            $reason = ReceiptInput::requiredText($reason, 500, 'GOODS_RECEIPT_CANCEL_REQUIRES_REASON');
            $this->receipts->updateHeader($id, ['GRC_STATUS' => GoodsReceiptStatus::Cancelled->value, 'GRC_USU_UPDATED_BY' => $user, 'GRC_UPDATED_AT' => Reloj::aBd(Reloj::ahora())]);
            $this->receipts->addEvent($id, 'CANCELLED', $user, ['reason' => $reason]);
            $this->audit->registrarAuditoria('CANCEL', 'PUR_GOODS_RECEIPT', ['id' => $id, 'reason' => $reason], $user, $ip, sedeId: (int) $receipt['GRC_SED_SITE']);

            return $this->presenter->present($id);
        });
    }

    /**
     * IT counts and classifies. Without differences it enters inventory now; otherwise it waits for approval.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function confirm(int $id, array $input, string $user, ?string $ip): array
    {
        return $this->transaction->ejecutar(function () use ($id, $input, $user, $ip): array {
            $receipt = $this->presenter->required($id, true);
            if (GoodsReceiptStatus::Registered->value !== $receipt['GRC_STATUS']) {
                throw ErrorDominio::conflicto('GOODS_RECEIPT_NOT_CONFIRMABLE');
            }
            if ((string) $receipt['GRC_USU_CREATED_BY'] === $user) {
                throw ErrorDominio::prohibido('GOODS_RECEIPT_CANNOT_CONFIRM_OWN');
            }
            $differences = $this->classifier->classify($id, $input['lines'] ?? null, $user);
            $this->receipts->updateHeader($id, ['GRC_USU_CONFIRMED_BY' => $user, 'GRC_CONFIRMED_AT' => Reloj::aBd(Reloj::ahora())]);
            if ([] === $differences) {
                $this->entry->enter($id, $user, $ip, null);

                return $this->presenter->present($id);
            }
            $reason = ReceiptInput::optionalText($input['difference_reason'] ?? null, 500, 'difference_reason')
                ?? throw ErrorDominio::invalido('GOODS_RECEIPT_DIFFERENCE_REQUIRES_REASON');
            $approval = $this->approvals->crear('RECEPCION_DIFERENCIA', ['goods_receipt' => $id, 'differences' => $differences], $user,
                "Ingreso {$receipt['GRC_CODE']}: diferencia en lo recibido", $reason, sede: (int) $receipt['GRC_SED_SITE'], ip: $ip);
            $this->receipts->updateHeader($id, ['GRC_STATUS' => GoodsReceiptStatus::PendingApproval->value, 'GRC_DIFFERENCE_REASON' => $reason, 'GRC_APR_APPROVAL' => $approval]);
            $this->receipts->addEvent($id, 'DIFFERENCE_REPORTED', $user, ['reason' => $reason, 'differences' => $differences, 'approval' => $approval]);
            $this->transaction->alConfirmar(fn () => $this->notifier->enviar('recepcion_diferencias', ['numero' => $receipt['GRC_CODE'], 'motivo_aviso' => 'recepcion',
                'faltantes' => [], 'diferencias' => $differences, 'texto' => $reason]));

            return [...$this->presenter->present($id), 'approval_id' => $approval];
        });
    }

    /** Called when another administrator approves the difference. */
    public function approveDifference(int $id, string $confirmedBy, ?string $ip, int $approval): void
    {
        $receipt = $this->presenter->required($id, true);
        if (GoodsReceiptStatus::PendingApproval->value !== $receipt['GRC_STATUS']) {
            throw ErrorDominio::conflicto('GOODS_RECEIPT_NOT_CONFIRMABLE');
        }
        $this->entry->enter($id, $confirmedBy, $ip, $approval);
    }

    /** Called when the difference is rejected: back to the technician, with nothing applied. */
    public function rejectDifference(int $id, string $user, ?string $comment): void
    {
        $receipt = $this->presenter->required($id, true);
        if (GoodsReceiptStatus::PendingApproval->value !== $receipt['GRC_STATUS']) {
            return;
        }
        $this->receipts->updateHeader($id, ['GRC_STATUS' => GoodsReceiptStatus::Registered->value, 'GRC_USU_CONFIRMED_BY' => null, 'GRC_CONFIRMED_AT' => null, 'GRC_APR_APPROVAL' => null]);
        $this->receipts->addEvent($id, 'DIFFERENCE_REJECTED', $user, ['comment' => $comment]);
    }

    public function documentExists(int $supplier, string $document): bool
    {
        return $this->receipts->documentExists($supplier, $document);
    }

    /** @return array<string, mixed>|null */
    public function suggestionFor(string $text): ?array
    {
        $row = $this->receipts->lastClassification($text);
        $int = ReceiptInput::intOrNull(...);

        return null === $row ? null : ['line_type' => $row['GRL_LINE_TYPE'], 'asset_type_id' => $int($row['GRL_TAC_ASSET_TYPE']), 'model_id' => $int($row['GRL_MOD_MODEL']),
            'consumable_id' => $int($row['GRL_CON_CONSUMABLE']), 'consumable_name' => $row['GRL_CONSUMABLE_NAME'], 'unit' => $row['GRL_CONSUMABLE_UNIT'],
            'software_id' => $int($row['GRL_SOF_SOFTWARE']), 'license_type_id' => $int($row['GRL_TLI_LICENSE_TYPE'])];
    }

    /** @return array<string, mixed> what the form can preload */
    public function formContext(string $user): array
    {
        $last = $this->inventory->lastReceiptOf($user);

        return ['site_id' => $this->scope->sedePorDefecto(), 'received_date' => $this->today(),
            'supplier_id' => false === $last ? null : (int) $last['GRC_PRV_SUPPLIER'], 'currency' => false === $last ? 'GTQ' : $last['GRC_CURRENCY']];
    }

    /** @return array{currency: ?string, site_id: ?int} what the form takes from the last receipt of that supplier */
    public function supplierContext(int $supplier): array
    {
        $last = $this->inventory->lastReceiptFrom($supplier);

        return false === $last ? ['currency' => null, 'site_id' => null]
            : ['currency' => (string) $last['GRC_CURRENCY'], 'site_id' => ReceiptInput::intOrNull($last['GRC_SED_SITE'])];
    }

    /** @return array<string, mixed> */
    private function editable(int $id): array
    {
        $receipt = $this->presenter->required($id, true);
        if (!GoodsReceiptStatus::from((string) $receipt['GRC_STATUS'])->isEditable()) {
            throw ErrorDominio::conflicto('GOODS_RECEIPT_NOT_EDITABLE');
        }

        return $receipt;
    }

    private function requireFreeDocument(int $supplier, string $document): void
    {
        if ($this->receipts->documentExists($supplier, $document)) {
            throw ErrorDominio::conflicto('GOODS_RECEIPT_DOCUMENT_ALREADY_REGISTERED');
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{supplier_id: int, document_number: string, received_date: string, site_id: ?int, currency: string, notes: ?string}
     */
    private function validHeader(array $input): array
    {
        $supplier = ReceiptInput::integer($input['supplier_id'] ?? null) ?? 0;
        if (!$this->inventory->supplierIsActive($supplier)) {
            throw ErrorDominio::noEncontrado('SUPPLIER_NOT_FOUND');
        }
        $date = $input['received_date'] ?? $this->today();
        if (!\is_string($date)) {
            throw ErrorDominio::invalido('GOODS_RECEIPT_INVALID_DATE');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
            throw ErrorDominio::invalido('GOODS_RECEIPT_INVALID_DATE');
        }
        if ($date > $this->today()) {
            throw ErrorDominio::invalido('GOODS_RECEIPT_DATE_IN_THE_FUTURE');
        }
        $currency = $input['currency'] ?? 'GTQ';
        if (!\in_array($currency, ReceiptInput::CURRENCIES, true)) {
            throw ErrorDominio::invalido('GOODS_RECEIPT_INVALID_CURRENCY');
        }

        return ['supplier_id' => $supplier, 'document_number' => ReceiptInput::requiredText($input['document_number'] ?? null, 60, 'GOODS_RECEIPT_REQUIRES_DOCUMENT'), 'received_date' => $date,
            'site_id' => ReceiptInput::optionalId($input['site_id'] ?? null, 'site_id'), 'currency' => $currency, 'notes' => ReceiptInput::optionalText($input['notes'] ?? null, 500, 'notes')];
    }

    /** @param list<array{item: string, quantity: int, cost: string, warranty: ?int}> $lines */
    private function insertLines(int $id, array $lines): void
    {
        foreach ($lines as $number => $line) {
            $this->receipts->insertLine(['GRL_GRC_GOODS_RECEIPT' => $id, 'GRL_LINE_NUMBER' => $number + 1, 'GRL_ITEM_TEXT' => $line['item'], 'GRL_QUANTITY_DECLARED' => $line['quantity'],
                'GRL_UNIT_COST' => $line['cost'], 'GRL_WARRANTY_MONTHS' => $line['warranty']]);
        }
    }
}
