<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;

/** Purchasing registers what arrived; IT classifies and confirms; nothing reaches inventory before that. */
final class GoodsReceiptFlowTest extends PruebaApi
{
    private int $site;
    private int $supplier;
    private int $assetType;
    private int $model;
    private string $purchasing;
    private string $technician;
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        I::estados();
        $this->site = D::crearSede('Ingreso '.D::sufijo());
        $this->supplier = I::proveedor();
        $this->assetType = I::tipo('COMPUTO');
        $this->model = I::modelo($this->assetType);
        $this->purchasing = $this->tokenDe(D::crearUsuario(['rol' => 'COMPRAS', 'sedes' => [$this->site]]));
        $this->technician = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->site]]));
        $this->admin = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->site]]));
    }

    public function testRegisteringTouchesNoInventoryAndValidatesTheDocument(): void
    {
        $document = 'F-'.D::sufijo();
        $before = D::contar('SELECT COUNT(*) FROM "INV_ACTIVO" WHERE "SED_Sede" = :s', ['s' => $this->site]);
        $receipt = $this->register($document);

        self::assertSame('REGISTERED', $receipt['status']);
        self::assertSame($before, D::contar('SELECT COUNT(*) FROM "INV_ACTIVO" WHERE "SED_Sede" = :s', ['s' => $this->site]));
        self::assertSame('1000.00', $receipt['lines'][0]['unit_cost']);

        self::assertSame([409, 'GOODS_RECEIPT_DOCUMENT_ALREADY_REGISTERED'], $this->detalle('POST', '/api/v1/goods-receipts', $this->body($document), $this->purchasing));
        $check = $this->ok(200, 'GET', "/api/v1/goods-receipts/checks/document?supplier_id={$this->supplier}&document_number={$document}", token: $this->purchasing);
        self::assertTrue($check['exists']);

        $future = $this->body('F-'.D::sufijo());
        $future['received_date'] = '2999-01-01';
        self::assertSame([400, 'GOODS_RECEIPT_DATE_IN_THE_FUTURE'], $this->detalle('POST', '/api/v1/goods-receipts', $future, $this->purchasing));

        $empty = $this->body('F-'.D::sufijo());
        $empty['lines'] = [];
        self::assertSame([400, 'GOODS_RECEIPT_REQUIRES_LINES'], $this->detalle('POST', '/api/v1/goods-receipts', $empty, $this->purchasing));

        self::assertSame(403, $this->pedir('POST', '/api/v1/goods-receipts', $this->body('F-'.D::sufijo()), $this->technician)[0]);
    }

    public function testFormContextUsesTheOrganizationsDateNotUtc(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guatemala')))->format('Y-m-d');
        self::assertSame($today, $this->ok(200, 'GET', '/api/v1/goods-receipts/form-context', token: $this->purchasing)['received_date']);
    }

    public function testConfirmationCreatesPendingAssetsStockAndTheAct(): void
    {
        $receipt = $this->register('F-'.D::sufijo());
        $id = $receipt['id'];

        self::assertSame(403, $this->pedir('POST', "/api/v1/goods-receipts/{$id}/confirm", $this->classification($receipt), $this->purchasing)[0]);
        self::assertSame([409, 'GOODS_RECEIPT_NOT_CONFIRMED'], $this->detalle('GET', "/api/v1/goods-receipts/{$id}/act.pdf", token: $this->purchasing));

        $confirmed = $this->ok(200, 'POST', "/api/v1/goods-receipts/{$id}/confirm", $this->classification($receipt), $this->technician);
        self::assertSame('CONFIRMED', $confirmed['status']);

        $assets = D::conexion()->fetchAllAssociative('SELECT a."ACT_Activo", a."ACT_Serie_Fabricante", a."ACT_Costo", a."ACT_Pending_Completion", e."EOP_Nombre" FROM "INV_ACTIVO" a '
            .'JOIN "INV_ESTADO_OPERATIVO" e ON e."EOP_Estado_Operativo" = a."EOP_Estado_Operativo" WHERE a."SED_Sede" = :s', ['s' => $this->site]);
        self::assertCount(2, $assets);
        self::assertNull($assets[0]['ACT_Serie_Fabricante']);
        self::assertSame('En Bodega', $assets[0]['EOP_Nombre']);
        self::assertSame(1, (int) $assets[0]['ACT_Pending_Completion']);
        self::assertSame(1000.0, (float) $assets[0]['ACT_Costo']);
        self::assertSame(5, (int) D::conexion()->fetchOne('SELECT "CON_Stock_Actual" FROM "INV_CONSUMIBLE" WHERE "SED_Sede" = :s', ['s' => $this->site]));
        self::assertTrue(I::cadenaKardexIntegra());
        self::assertGreaterThan(0, D::contar("SELECT COUNT(*) FROM \"INV_KARDEX\" WHERE \"KDX_Tipo\" = 'RECEPCION' AND \"ACT_Activo\" = :a", ['a' => $assets[0]['ACT_Activo']]));

        $this->cliente->request('GET', "/api/v1/goods-receipts/{$id}/act.pdf", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->purchasing]);
        self::assertSame(200, $this->cliente->getResponse()->getStatusCode());

        self::assertSame([409, 'GOODS_RECEIPT_NOT_EDITABLE'], $this->detalle('PATCH', "/api/v1/goods-receipts/{$id}", ['notes' => 'x'], $this->purchasing));
        self::assertSame([409, 'GOODS_RECEIPT_NOT_EDITABLE'], $this->detalle('POST', "/api/v1/goods-receipts/{$id}/cancel", ['reason' => 'x'], $this->purchasing));
        self::assertArrayNotHasKey('unit_cost', $this->ok(200, 'GET', "/api/v1/goods-receipts/{$id}", token: $this->technician)['lines'][0]);
        self::assertArrayHasKey('unit_cost', $this->ok(200, 'GET', "/api/v1/goods-receipts/{$id}", token: $this->purchasing)['lines'][0]);

        // A pending asset cannot go to anyone until it is complete.
        $pending = $this->ok(200, 'GET', '/api/v1/goods-receipts/pending-units', token: $this->technician);
        self::assertCount(2, $pending);
        $asset = $pending[0]['asset_id'];
        self::assertSame([409, 'ASSET_PENDING_COMPLETION'], $this->detalle('POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $asset, 'PER_Persona' => D::crearPersona($this->site),
            'ARE_Area' => I::area($this->site), 'TMO_Tipo_Movimiento' => 1, 'MOV_Motivo' => 'x'], $this->admin));
    }

    public function testTheTechnicianCompletesEachUnitAndTheReceiptCloses(): void
    {
        $receipt = $this->register('F-'.D::sufijo());
        $this->ok(200, 'POST', "/api/v1/goods-receipts/{$receipt['id']}/confirm", $this->classification($receipt), $this->technician);
        $units = $this->ok(200, 'GET', '/api/v1/goods-receipts/pending-units', token: $this->technician);
        $serial = 'SN-'.D::sufijo();

        $first = $this->ok(200, 'PATCH', "/api/v1/goods-receipts/units/{$units[0]['asset_id']}", ['serial' => $serial, 'hostname' => 'PC-'.D::sufijo()], $this->technician);
        self::assertFalse($first['pending']);
        self::assertSame('CONFIRMED', $first['receipt_status']);
        self::assertSame([409, 'SERIAL_NUMBER_ALREADY_EXISTS'], $this->detalle('PATCH', "/api/v1/goods-receipts/units/{$units[1]['asset_id']}", ['serial' => strtolower($serial)], $this->technician));
        self::assertTrue($this->ok(200, 'GET', "/api/v1/goods-receipts/checks/serial?value={$serial}", token: $this->technician)['exists']);

        $this->ok(200, 'PATCH', "/api/v1/goods-receipts/units/{$units[1]['asset_id']}", ['serial' => 'SN-'.D::sufijo()], $this->technician);
        $license = $this->ok(200, 'GET', "/api/v1/goods-receipts/{$receipt['id']}", token: $this->technician)['lines'][2];
        $last = $this->ok(200, 'POST', "/api/v1/goods-receipts/lines/{$license['id']}/license-keys", ['keys' => []], $this->technician);
        self::assertSame('COMPLETED', $last['status']);
        self::assertSame([], $this->ok(200, 'GET', '/api/v1/goods-receipts/pending-units', token: $this->technician));
        self::assertTrue(I::cadenaKardexIntegra());
    }

    public function testADifferenceWaitsForAnotherAdministratorAndNothingEntersUntilThen(): void
    {
        $receipt = $this->register('F-'.D::sufijo());
        $id = $receipt['id'];
        $body = $this->classification($receipt);
        $body['lines'][0]['received_quantity'] = 1;

        self::assertSame([400, 'GOODS_RECEIPT_DIFFERENCE_REQUIRES_REASON'], $this->detalle('POST', "/api/v1/goods-receipts/{$id}/confirm", $body, $this->admin));
        $body['difference_reason'] = 'Llegó una menos';
        $waiting = $this->ok(202, 'POST', "/api/v1/goods-receipts/{$id}/confirm", $body, $this->admin);
        self::assertSame('PENDING_APPROVAL', $waiting['status']);
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "INV_ACTIVO" WHERE "SED_Sede" = :s', ['s' => $this->site]));

        // Who confirmed cannot approve; rejecting returns it to the technician.
        self::assertSame([403, 'CANNOT_APPROVE_OWN_REQUEST'], $this->detalle('POST', "/api/v1/aprobaciones/{$waiting['approval_id']}/aprobar", ['comentario' => 'x'], $this->admin));
        $other = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->site]]));
        $this->ok(200, 'POST', "/api/v1/aprobaciones/{$waiting['approval_id']}/rechazar", ['comentario' => 'Recontar'], $other);
        self::assertSame('REGISTERED', $this->ok(200, 'GET', "/api/v1/goods-receipts/{$id}", token: $this->admin)['status']);

        $again = $this->ok(202, 'POST', "/api/v1/goods-receipts/{$id}/confirm", $body, $this->admin);
        $this->ok(200, 'POST', "/api/v1/aprobaciones/{$again['approval_id']}/aprobar", ['comentario' => 'Ok'], $other);
        self::assertSame('CONFIRMED', $this->ok(200, 'GET', "/api/v1/goods-receipts/{$id}", token: $this->admin)['status']);
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_ACTIVO" WHERE "SED_Sede" = :s', ['s' => $this->site]));

        $events = array_column($this->ok(200, 'GET', "/api/v1/goods-receipts/{$id}", token: $this->admin)['events'], 'event');
        self::assertSame(['REGISTERED', 'DIFFERENCE_REPORTED', 'DIFFERENCE_REJECTED', 'DIFFERENCE_REPORTED', 'CONFIRMED'], $events);
    }

    public function testWhoRegistersCannotConfirmAndOtherSitesSeeNothing(): void
    {
        $document = 'F-'.D::sufijo();
        $receipt = $this->ok(201, 'POST', '/api/v1/goods-receipts', $this->body($document), $this->admin);
        self::assertSame([403, 'GOODS_RECEIPT_CANNOT_CONFIRM_OWN'], $this->detalle('POST', "/api/v1/goods-receipts/{$receipt['id']}/confirm", $this->classification($receipt), $this->admin));

        $outsider = $this->tokenDe(D::crearUsuario(['rol' => 'COMPRAS', 'sedes' => [D::crearSede('Otra '.D::sufijo())]]));
        self::assertSame([404, 'GOODS_RECEIPT_NOT_FOUND'], $this->detalle('GET', "/api/v1/goods-receipts/{$receipt['id']}", token: $outsider));
    }

    public function testATechnicianCreatesAMissingModelWithoutDuplicates(): void
    {
        $brand = 'Marca '.D::sufijo();
        $name = 'Modelo '.D::sufijo();
        $created = $this->ok(201, 'POST', '/api/v1/goods-receipts/catalog/models', ['asset_type_id' => $this->assetType, 'brand' => $brand, 'model' => $name], $this->technician);
        $again = $this->ok(200, 'POST', '/api/v1/goods-receipts/catalog/models', ['asset_type_id' => $this->assetType, 'brand' => strtoupper($brand), 'model' => strtolower($name)], $this->technician);
        self::assertSame($created['model_id'], $again['model_id']);
        self::assertSame(403, $this->pedir('POST', '/api/v1/goods-receipts/catalog/models', ['asset_type_id' => $this->assetType, 'brand' => $brand, 'model' => 'x'], $this->purchasing)[0]);
    }

    /** @return array<string, mixed> */
    private function register(string $document): array
    {
        return $this->ok(201, 'POST', '/api/v1/goods-receipts', $this->body($document), $this->purchasing);
    }

    /** @return array<string, mixed> */
    private function body(string $document): array
    {
        return ['supplier_id' => $this->supplier, 'document_number' => $document, 'received_date' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Guatemala')))->format('Y-m-d'), 'site_id' => $this->site, 'currency' => 'GTQ',
            'lines' => [
                ['item_text' => 'Laptop Dell', 'quantity' => 2, 'unit_cost' => '1000.00', 'warranty_months' => 12],
                ['item_text' => 'Tóner negro', 'quantity' => 5, 'unit_cost' => '10.00'],
                ['item_text' => 'Office', 'quantity' => 3, 'unit_cost' => '50.00'],
                ['item_text' => 'Flete', 'quantity' => 1, 'unit_cost' => '20.00'],
            ]];
    }

    /**
     * @param array<string, mixed> $receipt
     *
     * @return array<string, mixed>
     */
    private function classification(array $receipt): array
    {
        $lines = $receipt['lines'];

        return ['lines' => [
            ['id' => $lines[0]['id'], 'received_quantity' => 2, 'line_type' => 'ASSET', 'asset_type_id' => $this->assetType, 'model_id' => $this->model],
            ['id' => $lines[1]['id'], 'received_quantity' => 5, 'line_type' => 'CONSUMABLE', 'consumable_name' => 'Tóner '.D::sufijo(), 'unit' => 'unidad'],
            ['id' => $lines[2]['id'], 'received_quantity' => 3, 'line_type' => 'LICENSE', 'software_id' => I::software(), 'license_type_id' => I::tipoLicencia()],
            ['id' => $lines[3]['id'], 'received_quantity' => 1, 'line_type' => 'OTHER'],
        ]];
    }
}
