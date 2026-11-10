<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Application;

use App\Correo\Notificador;
use App\Db\InsercionOracle;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\GoodsReceipt\Domain\GoodsReceiptStatus;
use App\GoodsReceipt\Domain\SpecificationValue;
use App\GoodsReceipt\Infrastructure\GoodsReceiptRepository;
use App\Http\ErrorApi;
use App\Http\ErrorValidacion;
use App\Inventario\Activos;
use App\Inventario\EstadosActivo;
use App\Inventario\Kardex;
use App\Inventario\ReglasClase;
use App\Inventario\Secuencias;
use App\Inventario\Software;
use App\Inventario\Solicitudes;
use App\Inventario\Valoracion;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * Goods receipt use cases. Purchasing registers what arrived; IT classifies and confirms. Nothing reaches
 * inventory before confirmation, a difference waits for another administrator and every step is recorded.
 */
final readonly class GoodsReceiptService
{
    private const array LINE_TYPES = ['ASSET', 'CONSUMABLE', 'LICENSE', 'OTHER'];
    private const array CURRENCIES = ['GTQ', 'USD', 'CHF'];

    public function __construct(
        private Connection $connection,
        private GoodsReceiptRepository $receipts,
        private Alcance $scope,
        private Transaccion $transaction,
        private Kardex $kardex,
        private GobiernoRepositorio $audit,
        private Secuencias $sequences,
        private EstadosActivo $states,
        private ReglasClase $classRules,
        private Software $software,
        private Solicitudes $approvals,
        private Activos $assets,
        private Notificador $notifier,
        private \App\Config\Ajustes $settings,
        private \App\Catalogo\SpecificationRules $specificationRules,
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
        $lines = $this->validLines($input['lines'] ?? null);

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

            return $this->present($id);
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
                $changes['GRC_NOTES'] = self::optionalText($input['notes'], 500);
            }
            if (\array_key_exists('document_number', $input)) {
                $document = self::requiredText($input['document_number'], 60, 'GOODS_RECEIPT_REQUIRES_DOCUMENT');
                if (0 !== strcasecmp(trim((string) $receipt['GRC_DOCUMENT_NUMBER']), $document)) {
                    $this->requireFreeDocument((int) $receipt['GRC_PRV_SUPPLIER'], $document);
                }
                $changes['GRC_DOCUMENT_NUMBER'] = $document;
            }
            $changes['GRC_USU_UPDATED_BY'] = $user;
            $changes['GRC_UPDATED_AT'] = Reloj::aBd(Reloj::ahora());
            $this->receipts->updateHeader($id, $changes);
            if (\array_key_exists('lines', $input)) {
                $lines = $this->validLines($input['lines']);
                $this->receipts->deleteLines($id);
                $this->insertLines($id, $lines);
            }
            $this->receipts->addEvent($id, 'UPDATED', $user, ['fields' => array_keys($input)]);
            $this->audit->registrarAuditoria('UPDATE', 'PUR_GOODS_RECEIPT', ['id' => $id, 'fields' => array_keys($input)], $user, $ip, sedeId: (int) $receipt['GRC_SED_SITE']);

            return $this->present($id);
        });
    }

    /** @return array<string, mixed> */
    public function cancel(int $id, string $reason, string $user, ?string $ip): array
    {
        return $this->transaction->ejecutar(function () use ($id, $reason, $user, $ip): array {
            $receipt = $this->editable($id);
            $reason = self::requiredText($reason, 500, 'GOODS_RECEIPT_CANCEL_REQUIRES_REASON');
            $this->receipts->updateHeader($id, ['GRC_STATUS' => GoodsReceiptStatus::Cancelled->value, 'GRC_USU_UPDATED_BY' => $user, 'GRC_UPDATED_AT' => Reloj::aBd(Reloj::ahora())]);
            $this->receipts->addEvent($id, 'CANCELLED', $user, ['reason' => $reason]);
            $this->audit->registrarAuditoria('CANCEL', 'PUR_GOODS_RECEIPT', ['id' => $id, 'reason' => $reason], $user, $ip, sedeId: (int) $receipt['GRC_SED_SITE']);

            return $this->present($id);
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
            $receipt = $this->required($id, true);
            if (GoodsReceiptStatus::Registered->value !== $receipt['GRC_STATUS']) {
                throw new ErrorApi(409, 'GOODS_RECEIPT_NOT_CONFIRMABLE');
            }
            if ((string) $receipt['GRC_USU_CREATED_BY'] === $user) {
                throw new ErrorApi(403, 'GOODS_RECEIPT_CANNOT_CONFIRM_OWN');
            }
            $differences = $this->classifyLines($id, $input['lines'] ?? null, $user);
            $this->receipts->updateHeader($id, ['GRC_USU_CONFIRMED_BY' => $user, 'GRC_CONFIRMED_AT' => Reloj::aBd(Reloj::ahora())]);
            if ([] === $differences) {
                $this->enterInventory($id, $user, $ip, null);

                return $this->present($id);
            }
            $reason = self::optionalText($input['difference_reason'] ?? null, 500, 'difference_reason')
                ?? throw new ErrorApi(400, 'GOODS_RECEIPT_DIFFERENCE_REQUIRES_REASON');
            $approval = $this->approvals->crear('RECEPCION_DIFERENCIA', ['goods_receipt' => $id, 'differences' => $differences], $user,
                "Ingreso {$receipt['GRC_CODE']}: diferencia en lo recibido", $reason, sede: (int) $receipt['GRC_SED_SITE'], ip: $ip);
            $this->receipts->updateHeader($id, ['GRC_STATUS' => GoodsReceiptStatus::PendingApproval->value, 'GRC_DIFFERENCE_REASON' => $reason, 'GRC_APR_APPROVAL' => $approval]);
            $this->receipts->addEvent($id, 'DIFFERENCE_REPORTED', $user, ['reason' => $reason, 'differences' => $differences, 'approval' => $approval]);
            $this->transaction->alConfirmar(fn () => $this->notifier->enviar('recepcion_diferencias', ['numero' => $receipt['GRC_CODE'], 'motivo_aviso' => 'recepcion',
                'faltantes' => [], 'diferencias' => $differences, 'texto' => $reason]));

            return [...$this->present($id), 'approval_id' => $approval];
        });
    }

    /** Called when another administrator approves the difference. */
    public function approveDifference(int $id, string $confirmedBy, ?string $ip, int $approval): void
    {
        $receipt = $this->required($id, true);
        if (GoodsReceiptStatus::PendingApproval->value !== $receipt['GRC_STATUS']) {
            throw new ErrorApi(409, 'GOODS_RECEIPT_NOT_CONFIRMABLE');
        }
        $this->enterInventory($id, $confirmedBy, $ip, $approval);
    }

    /** Called when the difference is rejected: back to the technician, with nothing applied. */
    public function rejectDifference(int $id, string $user, ?string $comment): void
    {
        $receipt = $this->required($id, true);
        if (GoodsReceiptStatus::PendingApproval->value !== $receipt['GRC_STATUS']) {
            return;
        }
        $this->receipts->updateHeader($id, ['GRC_STATUS' => GoodsReceiptStatus::Registered->value, 'GRC_USU_CONFIRMED_BY' => null, 'GRC_CONFIRMED_AT' => null, 'GRC_APR_APPROVAL' => null]);
        $this->receipts->addEvent($id, 'DIFFERENCE_REJECTED', $user, ['comment' => $comment]);
    }

    /**
     * The technician fills only what is missing. The asset stops being pending once it has a serial and every required specification.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function completeUnit(string $asset, array $input, string $user, ?string $ip): array
    {
        return $this->transaction->ejecutar(function () use ($asset, $input, $user, $ip): array {
            $row = $this->assets->filaO404($asset);
            $this->connection->fetchOne('SELECT 1 FROM "INV_ACTIVO" WHERE "ACT_Activo" = :a FOR UPDATE', ['a' => $asset]);
            $receipt = $this->receipts->receiptOfAsset($asset);
            if (null === $receipt || 1 !== (int) $row['ACT_Pending_Completion']) {
                throw new ErrorApi(409, 'ASSET_NOT_PENDING_COMPLETION');
            }
            $serial = self::requiredText($input['serial'] ?? null, 100, 'SERIAL_NUMBER_REQUIRED');
            if ($this->serialExists($serial, $asset)) {
                throw new ErrorApi(409, 'SERIAL_NUMBER_ALREADY_EXISTS');
            }
            $hostname = self::optionalText($input['hostname'] ?? null, 100, 'hostname');
            if (null !== $hostname && $this->hostnameExists($hostname, $asset)) {
                throw new ErrorApi(409, 'HOSTNAME_ALREADY_EXISTS');
            }
            $missing = $this->applySpecifications($asset, $row, $input['specifications'] ?? [], $user, $ip);
            $pending = [] !== $missing ? 1 : 0;
            $this->connection->executeStatement('UPDATE "INV_ACTIVO" SET "ACT_Serie_Fabricante" = :s, "ACT_Hostname" = NVL(:h, "ACT_Hostname"), "ACT_Pending_Completion" = :p WHERE "ACT_Activo" = :a',
                ['s' => $serial, 'h' => $hostname, 'p' => $pending, 'a' => $asset]);
            $fresh = $this->assets->filaO404($asset);
            $this->kardex->registrar('CAMBIO_DATOS', activo: $fresh, usuario: $user, motivo: 'Datos completados tras el ingreso',
                detalle: ['serie' => ['antes' => null, 'despues' => $serial], 'hostname' => ['antes' => $row['ACT_Hostname'], 'despues' => $hostname ?? $row['ACT_Hostname']]]);
            $this->audit->registrarAuditoria('UPDATE', 'INV_ACTIVO', ['codigo' => $row['ACT_Codigo_Interno'], 'serie' => $serial, 'origen' => 'Completar ingreso'], $user, $ip,
                sedeId: null === $row['SED_Sede'] ? null : (int) $row['SED_Sede']);
            $status = $this->closeIfDone($receipt, $user);

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
            $row = $this->receipts->lineForUpdate($line) ?? throw new ErrorApi(404, 'GOODS_RECEIPT_LINE_NOT_FOUND');
            if ('LICENSE' !== $row['GRL_LINE_TYPE'] || null === $row['GRL_LIC_LICENSE']) {
                throw new ErrorApi(409, 'GOODS_RECEIPT_LINE_NOT_A_LICENSE');
            }
            $receipt = (int) $row['GRL_GRC_GOODS_RECEIPT'];
            $this->software->addKeys((int) $row['GRL_LIC_LICENSE'], $keys, $user, $ip);
            $this->receipts->updateLine($line, ['GRL_KEYS_COMPLETED_AT' => Reloj::aBd(Reloj::ahora())]);
            $this->receipts->addEvent($receipt, 'LICENSE_KEYS_COMPLETED', $user, ['line' => $line, 'keys' => \count($keys)]);
            $this->closeIfDone($receipt, $user);

            return $this->present($receipt);
        });
    }

    /** @return array<string, mixed> */
    public function createModel(int $assetType, string $brand, string $model, string $user, ?string $ip): array
    {
        $brand = self::requiredText($brand, 100, 'BRAND_NAME_REQUIRED');
        $model = self::requiredText($model, 150, 'MODEL_NAME_REQUIRED');

        return $this->transaction->ejecutar(function () use ($assetType, $brand, $model, $user, $ip): array {
            if (false === $this->connection->fetchOne('SELECT 1 FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $assetType])) {
                throw new ErrorApi(404, 'ASSET_TYPE_NOT_FOUND');
            }
            $brandId = $this->connection->fetchOne('SELECT "MAR_Marca" FROM "INV_MARCA" WHERE LOWER(TRIM("MAR_Nombre")) = LOWER(:n) FETCH FIRST 1 ROWS ONLY', ['n' => $brand]);
            if (false === $brandId) {
                $brandId = InsercionOracle::insertar($this->connection, 'INV_MARCA', ['MAR_Nombre' => $brand], 'MAR_Marca');
                $this->audit->registrarAuditoria('CREATE', 'INV_MARCA', ['nombre' => $brand, 'origen' => 'Confirmación de ingreso'], $user, $ip);
            }
            $existing = $this->connection->fetchOne('SELECT "MOD_Modelo" FROM "INV_MODELO" WHERE "MAR_Marca" = :b AND LOWER(TRIM("MOD_Nombre")) = LOWER(:n) FETCH FIRST 1 ROWS ONLY', ['b' => $brandId, 'n' => $model]);
            if (false !== $existing) {
                return ['model_id' => (int) $existing, 'brand_id' => (int) $brandId, 'created' => false];
            }
            $id = InsercionOracle::insertar($this->connection, 'INV_MODELO', ['MOD_Nombre' => $model, 'MAR_Marca' => $brandId, 'TAC_Tipo_Activo' => $assetType], 'MOD_Modelo');
            $this->audit->registrarAuditoria('CREATE', 'INV_MODELO', ['nombre' => $model, 'marca' => $brand, 'origen' => 'Confirmación de ingreso'], $user, $ip);

            return ['model_id' => (int) $id, 'brand_id' => (int) $brandId, 'created' => true];
        });
    }

    /**
     * @param array<int|string, string> $values specification type => factory value
     */
    public function setModelDefaults(int $model, array $values, string $user, ?string $ip): void
    {
        $assetType = $this->assetTypeOfModel($model);
        $this->transaction->ejecutar(function () use ($model, $assetType, $values, $user, $ip): void {
            $this->connection->executeStatement('DELETE FROM CAT_MODEL_SPECIFICATION WHERE MSP_MOD_MODEL = :m', ['m' => $model]);
            foreach ($values as $type => $value) {
                if ('' === trim($value)) {
                    continue;
                }
                $this->connection->executeStatement('INSERT INTO CAT_MODEL_SPECIFICATION (MSP_MOD_MODEL, MSP_TES_SPECIFICATION_TYPE, MSP_DEFAULT_VALUE) VALUES (:m, :t, :v)',
                    ['m' => $model, 't' => (int) $type, 'v' => $this->specificationRules->normalize($assetType, (int) $type, $value)]);
            }
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
        $type = $this->connection->fetchOne('SELECT "TAC_Tipo_Activo" FROM "INV_MODELO" WHERE "MOD_Modelo" = :m', ['m' => $model]);

        return false === $type || null === $type ? throw new ErrorApi(404, 'MODEL_NOT_FOUND') : (int) $type;
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

    /** @return list<array<string, mixed>> */
    public function list(?string $status): array
    {
        return array_map(self::headerToApi(...), $this->receipts->list($status, 200));
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

    public function documentExists(int $supplier, string $document): bool
    {
        return $this->receipts->documentExists($supplier, $document);
    }

    public function serialExists(string $serial, ?string $except = null): bool
    {
        return $this->exists('ACT_Serie_Fabricante', $serial, $except);
    }

    public function hostnameExists(string $hostname, ?string $except = null): bool
    {
        return $this->exists('ACT_Hostname', $hostname, $except, ' AND "ACT_Baja_Fecha" IS NULL');
    }

    /** @return array<string, mixed>|null */
    public function suggestionFor(string $text): ?array
    {
        $row = $this->receipts->lastClassification($text);

        return null === $row ? null : ['line_type' => $row['GRL_LINE_TYPE'], 'asset_type_id' => self::intOrNull($row['GRL_TAC_ASSET_TYPE']), 'model_id' => self::intOrNull($row['GRL_MOD_MODEL']),
            'consumable_id' => self::intOrNull($row['GRL_CON_CONSUMABLE']), 'consumable_name' => $row['GRL_CONSUMABLE_NAME'], 'unit' => $row['GRL_CONSUMABLE_UNIT'],
            'software_id' => self::intOrNull($row['GRL_SOF_SOFTWARE']), 'license_type_id' => self::intOrNull($row['GRL_TLI_LICENSE_TYPE'])];
    }

    /** @return array<string, mixed> what the form can preload */
    public function formContext(string $user): array
    {
        $last = $this->connection->fetchAssociative('SELECT GRC_PRV_SUPPLIER, GRC_CURRENCY FROM PUR_GOODS_RECEIPT WHERE GRC_USU_CREATED_BY = :u ORDER BY GRC_GOODS_RECEIPT DESC FETCH FIRST 1 ROWS ONLY', ['u' => $user]);

        return ['site_id' => $this->scope->sedePorDefecto(), 'received_date' => $this->today(),
            'supplier_id' => false === $last ? null : (int) $last['GRC_PRV_SUPPLIER'], 'currency' => false === $last ? 'GTQ' : $last['GRC_CURRENCY']];
    }

    /** @return array<string, mixed> locked and in scope */
    public function required(int $id, bool $lock = false): array
    {
        return $this->receipts->find($id, $lock) ?? throw new ErrorApi(404, 'GOODS_RECEIPT_NOT_FOUND');
    }

    /** @return array<string, mixed> */
    private function editable(int $id): array
    {
        $receipt = $this->required($id, true);
        if (!GoodsReceiptStatus::from((string) $receipt['GRC_STATUS'])->isEditable()) {
            throw new ErrorApi(409, 'GOODS_RECEIPT_NOT_EDITABLE');
        }

        return $receipt;
    }

    private function requireFreeDocument(int $supplier, string $document): void
    {
        if ($this->receipts->documentExists($supplier, $document)) {
            throw new ErrorApi(409, 'GOODS_RECEIPT_DOCUMENT_ALREADY_REGISTERED');
        }
    }

    private function exists(string $column, string $value, ?string $except, string $extra = ''): bool
    {
        // Checked without site scope: an asset elsewhere also counts. Only yes or no goes back.
        return $this->scope->elevado(fn (): bool => false !== $this->connection->fetchOne(
            "SELECT 1 FROM \"INV_ACTIVO\" WHERE LOWER(TRIM(\"{$column}\")) = LOWER(TRIM(:v)){$extra}".(null === $except ? '' : ' AND "ACT_Activo" <> :a').' FETCH FIRST 1 ROWS ONLY',
            ['v' => $value, ...(null === $except ? [] : ['a' => $except])],
        ));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{supplier_id: int, document_number: string, received_date: string, site_id: ?int, currency: string, notes: ?string}
     */
    private function validHeader(array $input): array
    {
        $supplier = self::integer($input['supplier_id'] ?? null) ?? 0;
        if (false === $this->connection->fetchOne('SELECT 1 FROM "INV_PROVEEDOR" WHERE "PRV_Proveedor" = :p AND "PRV_Activo" = 1', ['p' => $supplier])) {
            throw new ErrorApi(404, 'SUPPLIER_NOT_FOUND');
        }
        $date = $input['received_date'] ?? $this->today();
        if (!\is_string($date)) {
            throw new ErrorApi(400, 'GOODS_RECEIPT_INVALID_DATE');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new ErrorApi(400, 'GOODS_RECEIPT_INVALID_DATE');
        }
        if ($date > $this->today()) {
            throw new ErrorApi(400, 'GOODS_RECEIPT_DATE_IN_THE_FUTURE');
        }
        $currency = $input['currency'] ?? 'GTQ';
        if (!\in_array($currency, self::CURRENCIES, true)) {
            throw new ErrorApi(400, 'GOODS_RECEIPT_INVALID_CURRENCY');
        }

        return ['supplier_id' => $supplier, 'document_number' => self::requiredText($input['document_number'] ?? null, 60, 'GOODS_RECEIPT_REQUIRES_DOCUMENT'), 'received_date' => $date,
            'site_id' => self::optionalId($input['site_id'] ?? null, 'site_id'), 'currency' => $currency, 'notes' => self::optionalText($input['notes'] ?? null, 500, 'notes')];
    }

    /** @return list<array{item: string, quantity: int, cost: string, warranty: ?int}> */
    private function validLines(mixed $lines): array
    {
        if (!\is_array($lines) || [] === $lines) {
            throw new ErrorApi(400, 'GOODS_RECEIPT_REQUIRES_LINES');
        }
        $valid = [];
        foreach (array_values($lines) as $line) {
            if (!\is_array($line)) {
                throw new ErrorApi(400, 'GOODS_RECEIPT_REQUIRES_LINES');
            }
            $quantity = self::integer($line['quantity'] ?? null);
            $cost = $line['unit_cost'] ?? '';
            $cost = \is_int($cost) ? (string) $cost : $cost;
            $warranty = $line['warranty_months'] ?? null;
            if (null === $quantity || $quantity <= 0) {
                throw new ErrorApi(400, 'GOODS_RECEIPT_INVALID_QUANTITY');
            }
            if (!\is_string($cost) || 1 !== preg_match('/^\d{1,12}(\.\d{1,2})?$/', $cost)) {
                throw new ErrorApi(400, 'GOODS_RECEIPT_INVALID_COST');
            }
            if (null !== $warranty && '' !== $warranty && (null === ($warranty = self::integer($warranty)) || $warranty > 600)) {
                throw new ErrorApi(400, 'GOODS_RECEIPT_INVALID_WARRANTY');
            }
            $warranty = '' === $warranty ? null : $warranty;
            $valid[] = ['item' => self::requiredText($line['item_text'] ?? null, 255, 'GOODS_RECEIPT_LINE_REQUIRES_ITEM'), 'quantity' => $quantity, 'cost' => $cost, 'warranty' => null === $warranty ? null : (int) $warranty];
        }

        return $valid;
    }

    /** @param list<array{item: string, quantity: int, cost: string, warranty: ?int}> $lines */
    private function insertLines(int $id, array $lines): void
    {
        foreach ($lines as $number => $line) {
            $this->receipts->insertLine(['GRL_GRC_GOODS_RECEIPT' => $id, 'GRL_LINE_NUMBER' => $number + 1, 'GRL_ITEM_TEXT' => $line['item'], 'GRL_QUANTITY_DECLARED' => $line['quantity'],
                'GRL_UNIT_COST' => $line['cost'], 'GRL_WARRANTY_MONTHS' => $line['warranty']]);
        }
    }

    /** @return list<array{line: int, item: string, declared: int, received: int}> */
    private function classifyLines(int $id, mixed $input, string $user): array
    {
        $byId = [];
        foreach (\is_array($input) ? $input : [] as $line) {
            if (!\is_array($line) || null === ($lineId = self::integer($line['id'] ?? null))) {
                throw new ErrorApi(400, 'GOODS_RECEIPT_ALL_LINES_REQUIRED');
            }
            $byId[$lineId] = $line;
        }
        $differences = [];
        foreach ($this->receipts->lines($id) as $line) {
            $lineId = (int) $line['GRL_GOODS_RECEIPT_LINE'];
            $given = $byId[$lineId] ?? throw new ErrorApi(400, 'GOODS_RECEIPT_ALL_LINES_REQUIRED');
            $received = self::integer($given['received_quantity'] ?? null);
            if (null === $received) {
                throw new ErrorApi(400, 'GOODS_RECEIPT_INVALID_QUANTITY');
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
        if (!\is_string($type) || !\in_array($type, self::LINE_TYPES, true)) {
            throw new ErrorApi(400, 'GOODS_RECEIPT_LINE_REQUIRES_CLASSIFICATION');
        }
        $empty = ['GRL_LINE_TYPE' => $type, 'GRL_TAC_ASSET_TYPE' => null, 'GRL_MOD_MODEL' => null, 'GRL_CON_CONSUMABLE' => null, 'GRL_CONSUMABLE_NAME' => null, 'GRL_CONSUMABLE_UNIT' => null,
            'GRL_SOF_SOFTWARE' => null, 'GRL_TLI_LICENSE_TYPE' => null];

        return match ($type) {
            'ASSET' => [...$empty, ...$this->assetClassification($given)],
            'CONSUMABLE' => [...$empty, ...$this->consumableClassification($given)],
            'LICENSE' => [...$empty, 'GRL_SOF_SOFTWARE' => self::integer($given['software_id'] ?? null) ?: throw new ErrorApi(400, 'GOODS_RECEIPT_LINE_REQUIRES_SOFTWARE'),
                'GRL_TLI_LICENSE_TYPE' => self::optionalId($given['license_type_id'] ?? null, 'lines.license_type_id')],
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
        $assetType = self::integer($given['asset_type_id'] ?? null) ?? 0;
        $model = self::integer($given['model_id'] ?? null) ?? 0;
        if (0 === $assetType || 0 === $model) {
            throw new ErrorApi(400, 'GOODS_RECEIPT_LINE_REQUIRES_MODEL');
        }
        $this->classRules->exigirNoInsumo($assetType);

        return ['GRL_TAC_ASSET_TYPE' => $assetType, 'GRL_MOD_MODEL' => $model];
    }

    /**
     * @param array<string, mixed> $given
     *
     * @return array<string, scalar|null>
     */
    private function consumableClassification(array $given): array
    {
        $existing = self::optionalId($given['consumable_id'] ?? null, 'lines.consumable_id');
        $name = self::optionalText($given['consumable_name'] ?? null, 100, 'lines.consumable_name');
        if (null === $existing && null === $name) {
            throw new ErrorApi(400, 'GOODS_RECEIPT_LINE_REQUIRES_CONSUMABLE');
        }

        return ['GRL_CON_CONSUMABLE' => $existing, 'GRL_CONSUMABLE_NAME' => $name, 'GRL_CONSUMABLE_UNIT' => self::optionalText($given['unit'] ?? null, 20, 'lines.unit') ?? 'unidad'];
    }

    private function enterInventory(int $id, string $user, ?string $ip, ?int $approval): void
    {
        $receipt = $this->required($id);
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

    /**
     * @param array<string, mixed>       $receipt
     * @param array<string, mixed>       $line
     * @param array{0: string, 1: string} $document
     * @param array<string, mixed>|null  $detail
     */
    private function createPendingAssets(array $receipt, array $line, int $received, array $document, string $origin, ?array $detail, string $user, ?string $ip): int
    {
        $prefix = $this->connection->fetchOne('SELECT "TAC_Prefijo" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $line['GRL_TAC_ASSET_TYPE']]);
        if (false === $prefix || null === $prefix || '' === $prefix) {
            throw new ErrorApi(400, 'ASSET_TYPE_HAS_NO_PREFIX_CONFIGURED_FOR_AUTO_GENERATION');
        }
        $storage = $this->states->idDe(EstadosActivo::BODEGA) ?? throw new ErrorApi(500, 'OPERATIONAL_STATE_NOT_FOUND');
        $site = (int) $receipt['GRC_SED_SITE'];
        $defaults = array_filter($this->receipts->specificationsFor((int) $line['GRL_TAC_ASSET_TYPE'], (int) $line['GRL_MOD_MODEL'], null), static fn (array $s): bool => null !== $s['DEFAULT_VALUE']);
        for ($i = 0; $i < $received; ++$i) {
            $id = Ident::nuevo();
            $code = $this->sequences->siguiente("ASSET_{$prefix}", (string) $prefix);
            $months = null === $line['GRL_WARRANTY_MONTHS'] ? null : (int) $line['GRL_WARRANTY_MONTHS'];
            $this->connection->executeStatement('INSERT INTO "INV_ACTIVO" ("ACT_Activo", "ACT_Codigo_Interno", "ACT_Fecha_Compra", "ACT_Fin_Garantia", "ACT_Costo", "ACT_Moneda", "ACT_Factura", '
                .'"PRV_Proveedor", "MOD_Modelo", "TAC_Tipo_Activo", "EOP_Estado_Operativo", "SED_Sede", "ACT_Instalado", "ACT_Pending_Completion") VALUES '
                ."(:id, :code, TO_DATE(:bought, 'YYYY-MM-DD'), CASE WHEN :months IS NULL THEN NULL ELSE ADD_MONTHS(TO_DATE(:bought2, 'YYYY-MM-DD'), :months2) END, :cost, :currency, :invoice, "
                .':supplier, :model, :type, :state, :site, 0, 1)', ['id' => $id, 'code' => $code, 'bought' => $receipt['RECEIVED_DATE'], 'months' => $months, 'bought2' => $receipt['RECEIVED_DATE'], 'months2' => $months,
                    'cost' => $line['GRL_UNIT_COST'], 'currency' => $receipt['GRC_CURRENCY'], 'invoice' => mb_substr((string) $receipt['GRC_DOCUMENT_NUMBER'], 0, 60), 'supplier' => $receipt['GRC_PRV_SUPPLIER'],
                    'model' => $line['GRL_MOD_MODEL'], 'type' => $line['GRL_TAC_ASSET_TYPE'], 'state' => $storage, 'site' => $site]);
            $this->receipts->linkUnit((int) $line['GRL_GOODS_RECEIPT_LINE'], $id);
            foreach ($defaults as $default) {
                $this->connection->executeStatement('INSERT INTO "INV_ESPECIFICACION" ("ESP_Valor", "ACT_Activo", "TES_Tipo_Especificacion") VALUES (:v, :a, :t)',
                    ['v' => $default['DEFAULT_VALUE'], 'a' => $id, 't' => $default['TES_Tipo_Especificacion']]);
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
        $row = $this->connection->fetchAssociative('SELECT "CON_Consumible", "CON_Nombre", "CON_Stock_Actual", "CON_Costo_Unitario", "CON_Moneda" FROM "INV_CONSUMIBLE" WHERE "CON_Consumible" = :c FOR UPDATE', ['c' => $consumable])
            ?: throw new ErrorApi(404, 'CONSUMABLE_NOT_FOUND');
        [$average, $currency, $cost] = Valoracion::entrada((int) $row['CON_Stock_Actual'], null === $row['CON_Costo_Unitario'] ? null : (string) $row['CON_Costo_Unitario'], $row['CON_Moneda'],
            $received, (string) $line['GRL_UNIT_COST'], (string) $receipt['GRC_CURRENCY']);
        $balance = (int) $row['CON_Stock_Actual'] + $received;
        $this->connection->executeStatement('UPDATE "INV_CONSUMIBLE" SET "CON_Stock_Actual" = :b, "CON_Costo_Unitario" = :p, "CON_Moneda" = :m WHERE "CON_Consumible" = :c', ['b' => $balance, 'p' => $average, 'm' => $currency, 'c' => $consumable]);
        InsercionOracle::insertar($this->connection, 'INV_MOVIMIENTO_CONSUMIBLE', ['MOC_Tipo' => 'ENTRADA', 'MOC_Cantidad' => $received, 'MOC_Stock_Resultante' => $balance, 'MOC_Motivo' => $origin,
            'MOC_Fecha' => Reloj::aBd(Reloj::ahora()), 'CON_Consumible' => $consumable, 'USU_Usuario' => $user], 'MOC_Movimiento');
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
        $found = $this->connection->fetchOne('SELECT "CON_Consumible" FROM "INV_CONSUMIBLE" WHERE "SED_Sede" = :s AND LOWER(TRIM("CON_Nombre")) = LOWER(TRIM(:n)) FETCH FIRST 1 ROWS ONLY', ['s' => $site, 'n' => $line['GRL_CONSUMABLE_NAME']]);
        if (false !== $found) {
            return (int) $found;
        }

        return (int) InsercionOracle::insertar($this->connection, 'INV_CONSUMIBLE', ['CON_Nombre' => $line['GRL_CONSUMABLE_NAME'], 'CON_Unidad' => $line['GRL_CONSUMABLE_UNIT'] ?? 'unidad',
            'SED_Sede' => $site, 'CON_Stock_Actual' => 0, 'CON_Stock_Minimo' => 0, 'CON_Activo' => 1], 'CON_Consumible');
    }

    /**
     * @param array<string, mixed>       $receipt
     * @param array<string, mixed>       $line
     * @param array{0: string, 1: string} $document
     */
    private function createLicense(array $receipt, array $line, int $received, array $document, string $origin, string $user, ?string $ip): void
    {
        $license = $this->software->crearLicenciaEnTransaccion(['SOF_Software' => $line['GRL_SOF_SOFTWARE'], 'TLI_Tipo_Licencia' => $line['GRL_TLI_LICENSE_TYPE'], 'LIC_Cantidad_Total' => $received,
            'LIC_Costo' => bcmul((string) $line['GRL_UNIT_COST'], (string) $received, 2), 'LIC_Moneda' => $receipt['GRC_CURRENCY'], 'LIC_Proveedor' => $receipt['GRC_PRV_SUPPLIER']],
            [], [], $user, $ip, $origin, $document, (int) $receipt['GRC_SED_SITE']);
        $this->receipts->updateLine((int) $line['GRL_GOODS_RECEIPT_LINE'], ['GRL_LIC_LICENSE' => $license]);
    }

    private function closeIfDone(int $id, string $user): string
    {
        if ($this->receipts->hasPendingWork($id)) {
            return GoodsReceiptStatus::Confirmed->value;
        }
        $this->receipts->updateHeader($id, ['GRC_STATUS' => GoodsReceiptStatus::Completed->value]);
        $this->receipts->addEvent($id, 'COMPLETED', $user);

        return GoodsReceiptStatus::Completed->value;
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
            $type = \is_array($spec) ? self::integer($spec['type_id'] ?? null) : null;
            $value = \is_array($spec) ? ($spec['value'] ?? '') : null;
            if (null === $type || !(\is_string($value) || \is_int($value) || \is_float($value)) || mb_strlen((string) $value) > 255) {
                throw self::invalid('specifications', $spec);
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
                    throw new ErrorApi(400, "{$e->getMessage()}:{$spec['TES_Nombre']}");
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
            $this->assets->agregarEspecificacion($asset, $type, $value, 'Datos completados tras el ingreso', $user, $ip);

            return;
        }
        if ((string) $current === $value) {
            return;
        }
        $id = (int) $this->connection->fetchOne('SELECT "ESP_Especificacion" FROM "INV_ESPECIFICACION" WHERE "ACT_Activo" = :a AND "TES_Tipo_Especificacion" = :t', ['a' => $asset, 't' => $type]);
        $this->assets->cambiarEspecificacion($id, $value, 'Datos completados tras el ingreso', $user, $ip);
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
            'notes' => $r['GRC_NOTES'], 'difference_reason' => $r['GRC_DIFFERENCE_REASON'], 'approval_id' => self::intOrNull($r['GRC_APR_APPROVAL']), 'confirmed_at' => null === $when ? null : Reloj::iso($when),
            'created_by' => null === $r['GRC_USU_CREATED_BY'] ? null : Ident::aTexto((string) $r['GRC_USU_CREATED_BY'])];
    }

    /**
     * @param array<string, mixed> $l
     *
     * @return array<string, mixed>
     */
    private static function lineToApi(array $l): array
    {
        return ['id' => (int) $l['GRL_GOODS_RECEIPT_LINE'], 'number' => (int) $l['GRL_LINE_NUMBER'], 'item_text' => $l['GRL_ITEM_TEXT'], 'quantity' => (int) $l['GRL_QUANTITY_DECLARED'],
            'received_quantity' => self::intOrNull($l['GRL_QUANTITY_RECEIVED']), 'unit_cost' => bcadd((string) $l['GRL_UNIT_COST'], '0', 2), 'warranty_months' => self::intOrNull($l['GRL_WARRANTY_MONTHS']),
            'line_type' => $l['GRL_LINE_TYPE'], 'asset_type_id' => self::intOrNull($l['GRL_TAC_ASSET_TYPE']), 'asset_type' => $l['TYPE_NAME'], 'model_id' => self::intOrNull($l['GRL_MOD_MODEL']),
            'product' => null === $l['MODEL_NAME'] ? null : trim($l['BRAND_NAME'].' '.$l['MODEL_NAME']), 'consumable_id' => self::intOrNull($l['GRL_CON_CONSUMABLE']), 'consumable_name' => $l['GRL_CONSUMABLE_NAME'],
            'software_id' => self::intOrNull($l['GRL_SOF_SOFTWARE']), 'license_id' => self::intOrNull($l['GRL_LIC_LICENSE']), 'keys_completed' => null !== $l['GRL_KEYS_COMPLETED_AT'],
            'units_total' => (int) $l['UNITS_TOTAL'], 'units_done' => (int) $l['UNITS_DONE']];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return null === $value ? null : (int) $value;
    }

    private static function requiredText(mixed $value, int $max, string $code): string
    {
        $text = \is_string($value) ? trim($value) : '';
        if ('' === $text || mb_strlen($text) > $max) {
            throw new ErrorApi(400, $code);
        }

        return $text;
    }

    /** Texto opcional: null o vacío = sin valor; otro tipo o más largo que `$max` = 422 (nunca se recorta en silencio). */
    private static function optionalText(mixed $value, int $max, string $field = 'text'): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || mb_strlen(trim($value)) > $max) {
            throw self::invalid($field, $value);
        }
        $text = trim($value);

        return '' === $text ? null : $text;
    }

    /** Entero no negativo (número entero o texto de dígitos); cualquier otra cosa = null. */
    private static function integer(mixed $value): ?int
    {
        return match (true) {
            \is_int($value) => $value >= 0 ? $value : null,
            \is_string($value) && 1 === preg_match('/^\d{1,18}$/', trim($value)) => (int) trim($value),
            default => null,
        };
    }

    /** Identificador opcional: null = sin valor; si viene, entero positivo o 422. */
    private static function optionalId(mixed $value, string $field): ?int
    {
        if (null === $value) {
            return null;
        }
        $id = self::integer($value);

        return null === $id || 0 === $id ? throw self::invalid($field, $value) : $id;
    }

    private static function invalid(string $field, mixed $value): ErrorValidacion
    {
        return new ErrorValidacion([['type' => 'value_error', 'loc' => ['body', ...explode('.', $field)], 'msg' => 'Value error, INVALID_PARAMETER_VALUE',
            'input' => \is_string($value) && \strlen($value) > 100 ? mb_substr($value, 0, 100).'…' : $value, 'ctx' => ['error' => 'INVALID_PARAMETER_VALUE']]]);
    }
}
