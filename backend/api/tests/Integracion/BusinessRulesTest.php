<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Db\ContextoRls;
use App\Dominio\Ident;
use App\EventListener\OcultarCostosListener;
use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;
use Doctrine\DBAL\Connection;

/** Pending business rules of phase 9: full traceability, disposal without a fake return, unique hostnames. */
final class BusinessRulesTest extends PruebaApi
{
    private int $site;
    private int $type;
    private int $model;
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        I::estados();
        I::motivosBaja();
        foreach (['Asignación', 'Devolución'] as $t) {
            if (0 === D::contar('SELECT COUNT(*) FROM "INV_TIPO_MOVIMIENTO" WHERE "TMO_Nombre" = :n', ['n' => $t])) {
                D::conexion()->executeStatement('INSERT INTO "INV_TIPO_MOVIMIENTO" ("TMO_Nombre") VALUES (:n)', ['n' => $t]);
            }
        }
        $this->site = D::crearSede('Reglas '.D::sufijo());
        $this->type = I::tipo('COMPUTO');
        $this->model = I::modelo($this->type);
        $this->admin = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->site]]));
    }

    public function testCostSerialAndHostnameChangesReachTheKardex(): void
    {
        $asset = $this->asset(['ACT_Costo' => '100.00', 'ACT_Moneda' => 'GTQ']);
        $hex = str_replace('-', '', $asset);
        $serial = 'NEW-'.D::sufijo();
        $host = 'HOST-'.D::sufijo();

        $this->ok(200, 'PATCH', "/api/v1/core/activos/{$asset}", ['ACT_Costo' => '250.50', 'ACT_Serie_Fabricante' => $serial, 'ACT_Hostname' => $host], $this->admin);

        $cost = D::conexion()->fetchAssociative("SELECT \"KDX_Costo\", DBMS_LOB.SUBSTR(\"KDX_Detalle\", 500, 1) AS DETALLE FROM \"INV_KARDEX\" WHERE \"KDX_Tipo\" = 'CAMBIO_COSTO' AND \"ACT_Activo\" = :a", ['a' => $hex]);
        self::assertNotFalse($cost);
        self::assertStringContainsString('100', (string) $cost['DETALLE']);
        self::assertStringContainsString('250.5', (string) $cost['DETALLE']);
        $data = (string) D::conexion()->fetchOne("SELECT DBMS_LOB.SUBSTR(\"KDX_Detalle\", 800, 1) FROM \"INV_KARDEX\" WHERE \"KDX_Tipo\" = 'CAMBIO_DATOS' AND \"ACT_Activo\" = :a", ['a' => $hex]);
        self::assertStringContainsString($serial, $data);
        self::assertStringContainsString($host, $data);
        self::assertTrue(I::cadenaKardexIntegra());

        // Saving the same values leaves no new event.
        $before = D::contar('SELECT COUNT(*) FROM "INV_KARDEX" WHERE "ACT_Activo" = :a', ['a' => $hex]);
        $this->ok(200, 'PATCH', "/api/v1/core/activos/{$asset}", ['ACT_Costo' => '250.50', 'ACT_Serie_Fabricante' => $serial, 'ACT_Hostname' => $host], $this->admin);
        self::assertSame($before, D::contar('SELECT COUNT(*) FROM "INV_KARDEX" WHERE "ACT_Activo" = :a', ['a' => $hex]));
    }

    public function testATheftWithOpenCustodyIsDisposedOfWithoutAFakeReturn(): void
    {
        $asset = $this->asset();
        $hex = str_replace('-', '', $asset);
        $person = D::crearPersona($this->site, 'Ana');
        $this->assign($asset, $person);

        // Obsolescence still needs the device back.
        self::assertSame([400, 'CANNOT_DELETE_ASSIGNED_ASSET_RETURN_IT_FIRST'], $this->detalle('POST', "/api/v1/core/activos/{$asset}/baja", ['motivo' => 'OBSOLESCENCIA', 'destino' => 'RECICLAJE'], $this->admin));

        $request = $this->ok(202, 'POST', "/api/v1/core/activos/{$asset}/baja", ['motivo' => 'PERDIDA', 'destino' => 'NO_RECUPERADO', 'observacion' => 'Denuncia 123'], $this->admin);
        $approver = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->site]]));
        $this->ok(200, 'POST', "/api/v1/aprobaciones/{$request['APR_Aprobacion']}/aprobar", [], $approver);

        self::assertSame('Baja', $this->ok(200, 'GET', "/api/v1/core/activos/{$asset}", token: $this->admin)['estado_operativo']['EOP_Nombre']);
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL', ['a' => $hex]));
        self::assertSame(0, D::contar("SELECT COUNT(*) FROM \"INV_KARDEX\" WHERE \"KDX_Tipo\" = 'DEVOLUCION' AND \"ACT_Activo\" = :a", ['a' => $hex]));
        $detail = (string) D::conexion()->fetchOne("SELECT DBMS_LOB.SUBSTR(\"KDX_Detalle\", 800, 1) FROM \"INV_KARDEX\" WHERE \"KDX_Tipo\" = 'BAJA' AND \"ACT_Activo\" = :a", ['a' => $hex]);
        self::assertStringContainsString('custodia_sin_devolucion', $detail);
        self::assertTrue(I::cadenaKardexIntegra());
    }

    public function testAHostnameIsUniqueAmongLiveAssets(): void
    {
        $host = 'PC-'.D::sufijo();
        $first = $this->asset(['ACT_Hostname' => $host]);
        self::assertSame([409, 'HOSTNAME_ALREADY_EXISTS'], $this->detalle('POST', '/api/v1/core/activos', $this->assetBody(['ACT_Hostname' => strtolower($host)]), $this->admin));
        $second = $this->asset();
        self::assertSame([409, 'HOSTNAME_ALREADY_EXISTS'], $this->detalle('PATCH', "/api/v1/core/activos/{$second}", ['ACT_Hostname' => $host], $this->admin));

        // A disposed asset frees its name.
        $request = $this->ok(202, 'POST', "/api/v1/core/activos/{$first}/baja", ['motivo' => 'OBSOLESCENCIA', 'destino' => 'RECICLAJE'], $this->admin);
        $approver = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->site]]));
        $this->ok(200, 'POST', "/api/v1/aprobaciones/{$request['APR_Aprobacion']}/aprobar", [], $approver);
        $this->ok(200, 'PATCH', "/api/v1/core/activos/{$second}", ['ACT_Hostname' => $host], $this->admin);
    }

    public function testLicenseAttachmentsAreHiddenInTheDatabaseFromNonAdministrators(): void
    {
        $license = (int) D::conexion()->fetchOne('SELECT MAX("LIC_Licencia") FROM "INV_LICENCIA"') ?: $this->newLicense();
        $id = Ident::nuevo();
        D::conexion()->executeStatement('INSERT INTO "INV_ADJUNTO" ("ADJ_Adjunto", "ADJ_Nombre_Original", "ADJ_Nombre_Almacenado", "ADJ_Categoria", "ADJ_Tamano_Bytes", "ADJ_Fecha_Subida", "LIC_Licencia") '
            ."VALUES (:id, 'a.pdf', :n, 'otro', 1, SYSTIMESTAMP, :l)", ['id' => $id, 'n' => "{$id}.pdf", 'l' => $license]);
        $connection = static::getContainer()->get(Connection::class);
        $rls = static::getContainer()->get(ContextoRls::class);
        $visible = static function (array $user) use ($connection, $rls, $id): int {
            $rls->fijarUsuario($user['id']);
            try {
                return (int) $connection->fetchOne('SELECT COUNT(*) FROM "INV_ADJUNTO" WHERE "ADJ_Adjunto" = :id', ['id' => $id]);
            } finally {
                $rls->reset();
            }
        };

        self::assertSame(0, $visible(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->site]])));
        self::assertSame(0, $visible(D::crearUsuario(['rol' => 'AUDITOR', 'global' => true])), 'Global scope is not enough.');
        self::assertSame(1, $visible(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->site]])));
        self::assertSame(1, $visible(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true])));
    }

    public function testCostsAreHiddenByDefaultEvenForKeysNobodyListed(): void
    {
        $data = json_decode('{"nuevo_costo":"1","unit_cost":"2","ESP_Valor":"16","total_price":"3","nombre":"x","items":[{"monto":"5","valores":[]}]}');
        self::assertSame('{"ESP_Valor":"16","nombre":"x","items":[{"valores":[]}]}', json_encode(OcultarCostosListener::quitarDinero($data)));
    }

    public function testTheAssetExportHonoursFiltersAndRefusesToTruncate(): void
    {
        $wanted = $this->asset(['ACT_Hostname' => 'EXP-'.D::sufijo()]);
        $code = $this->ok(200, 'GET', "/api/v1/core/activos/{$wanted}", token: $this->admin)['ACT_Codigo_Interno'];
        $other = $this->asset();
        $otherCode = $this->ok(200, 'GET', "/api/v1/core/activos/{$other}", token: $this->admin)['ACT_Codigo_Interno'];

        $this->cliente->request('GET', '/api/v1/export/activos.csv?q='.rawurlencode($code), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->admin]);
        self::assertSame(200, $this->cliente->getResponse()->getStatusCode());
        $csv = (string) $this->cliente->getResponse()->getContent();
        self::assertStringContainsString($code, $csv);
        self::assertStringNotContainsString($otherCode, $csv);
    }

    public function testAClosedMonthIsFrozenAndOnlyITClosesIt(): void
    {
        $purchasing = $this->tokenDe(D::crearUsuario(['rol' => 'COMPRAS', 'sedes' => [$this->site]]));
        $url = "/api/v1/inventario/cierre-mensual?anio=2026&mes=2&sede_id={$this->site}";

        self::assertSame(403, $this->pedir('POST', "/api/v1/inventario/cierre-mensual/cerrar?anio=2026&mes=2&sede_id={$this->site}", [], $purchasing)[0]);
        self::assertSame([409, 'MONTH_NOT_FINISHED'], $this->detalle('POST', "/api/v1/inventario/cierre-mensual/cerrar?anio=2099&mes=1&sede_id={$this->site}", [], $this->admin));
        self::assertFalse($this->ok(200, 'GET', $url, token: $purchasing)['frozen'] ?? false);

        $this->asset(['ACT_Fecha_Compra' => '2026-02-10', 'ACT_Costo' => '100.00', 'ACT_Moneda' => 'GTQ']);
        $closed = $this->ok(201, 'POST', "/api/v1/inventario/cierre-mensual/cerrar?anio=2026&mes=2&sede_id={$this->site}", [], $this->admin);
        self::assertTrue($closed['frozen']);
        self::assertSame([409, 'MONTH_ALREADY_CLOSED'], $this->detalle('POST', "/api/v1/inventario/cierre-mensual/cerrar?anio=2026&mes=2&sede_id={$this->site}", [], $this->admin));

        // Later movements do not change what was closed.
        $this->asset(['ACT_Fecha_Compra' => '2026-02-11', 'ACT_Costo' => '999.00', 'ACT_Moneda' => 'GTQ']);
        $frozen = $this->ok(200, 'GET', $url, token: $purchasing);
        self::assertTrue($frozen['frozen']);
        self::assertSame($closed['altas'], $frozen['altas']);
    }

    public function testALargeInventoryCountClosesInTheBackgroundExactlyOnce(): void
    {
        $count = $this->ok(201, 'POST', '/api/v1/inventario/tomas', ['SED_Sede' => $this->site], $this->admin);
        $id = $count['TOM_Toma'];
        D::conexion()->executeStatement('INSERT INTO "INV_ACTIVO" ("ACT_Activo", "ACT_Codigo_Interno", "ACT_Serie_Fabricante", "ACT_Fecha_Compra", "MOD_Modelo", "TAC_Tipo_Activo", "EOP_Estado_Operativo", "SED_Sede", "ACT_Instalado") '
            ."SELECT LOWER(RAWTOHEX(SYS_GUID())), 'V'||:s||'-'||LEVEL, 'V'||:s2||'-'||LEVEL, DATE '2026-01-01', :m, :t, :e, :sede, 0 FROM DUAL CONNECT BY LEVEL <= 501",
            ['s' => D::sufijo(), 's2' => D::sufijo(), 'm' => $this->model, 't' => $this->type, 'e' => I::estado('En Bodega'), 'sede' => $this->site]);

        $closing = $this->ok(202, 'POST', "/api/v1/inventario/tomas/{$id}/cerrar", token: $this->admin);
        self::assertSame('CERRANDO', $closing['TOM_Estado']);
        self::assertSame(0, D::contar("SELECT COUNT(*) FROM \"INV_KARDEX\" WHERE \"KDX_Tipo\" = 'INVENTARIO_FISICO' AND \"KDX_Documento_Ref\" = :d", ['d' => (string) $id]));
        self::assertSame([409, 'INVENTORY_COUNT_NOT_OPEN'], $this->detalle('POST', "/api/v1/inventario/tomas/{$id}/cerrar", token: $this->admin));

        $worker = static::getContainer()->get(\App\Tareas\CierreTomasPendientes::class);
        $worker->ejecutar();
        self::assertSame('CERRADA', $this->ok(200, 'GET', "/api/v1/inventario/tomas/{$id}", token: $this->admin)['TOM_Estado']);
        self::assertSame(501, D::contar("SELECT COUNT(*) FROM \"INV_KARDEX\" WHERE \"KDX_Tipo\" = 'INVENTARIO_FISICO' AND \"KDX_Documento_Ref\" = :d", ['d' => (string) $id]));

        $worker->ejecutar();
        self::assertEquals(501, D::contar("SELECT COUNT(*) FROM \"INV_KARDEX\" WHERE \"KDX_Tipo\" = 'INVENTARIO_FISICO' AND \"KDX_Documento_Ref\" = :d", ['d' => (string) $id]));
        self::assertTrue(I::cadenaKardexIntegra());
    }

    /** @param array<string, mixed> $extra */
    private function asset(array $extra = []): string
    {
        return $this->ok(201, 'POST', '/api/v1/core/activos', $this->assetBody($extra), $this->admin)['ACT_Activo'];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function assetBody(array $extra = []): array
    {
        return ['ACT_Serie_Fabricante' => 'BR-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-01-01', 'MOD_Modelo' => $this->model, 'TAC_Tipo_Activo' => $this->type,
            'EOP_Estado_Operativo' => I::estado('En Bodega'), 'SED_Sede' => $this->site, ...$extra];
    }

    private function assign(string $asset, string $person): void
    {
        $type = (int) D::conexion()->fetchOne("SELECT MIN(\"TMO_Tipo_Movimiento\") FROM \"INV_TIPO_MOVIMIENTO\" WHERE \"TMO_Nombre\" = 'Asignación'");
        $this->ok(201, 'POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $asset, 'PER_Persona' => Ident::aTexto($person), 'ARE_Area' => I::area($this->site), 'TMO_Tipo_Movimiento' => $type, 'MOV_Motivo' => 'Ingreso'], $this->admin);
    }

    private function newLicense(): int
    {
        D::conexion()->executeStatement('INSERT INTO "INV_LICENCIA" ("SOF_Software", "LIC_Cantidad_Total", "LIC_Cantidad_Usada") VALUES (:s, 1, 0)', ['s' => I::software()]);

        return (int) D::conexion()->fetchOne('SELECT MAX("LIC_Licencia") FROM "INV_LICENCIA"');
    }
}
