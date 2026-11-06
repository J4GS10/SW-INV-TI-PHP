<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Esquema\SpecificationNormalization;
use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;

/** Atomic specifications: typed values, allowed values in a table, factory values per model, split legacy text. */
final class SpecificationRulesTest extends PruebaApi
{
    private int $site;
    private int $assetType;
    private int $model;
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        I::estados();
        $this->site = D::crearSede('Espec '.D::sufijo());
        $this->assetType = I::tipo('COMPUTO');
        $this->model = I::modelo($this->assetType);
        $this->admin = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->site]]));
    }

    private function specType(string $dataType, ?float $min = null, ?float $max = null): int
    {
        $name = 'Espec '.D::sufijo();
        D::conexion()->executeStatement('INSERT INTO "INV_TIPO_ESPECIFICACION" ("TES_Nombre", "TES_Data_Type", "TES_Min_Value", "TES_Max_Value") VALUES (:n, :d, :a, :b)',
            ['n' => $name, 'd' => $dataType, 'a' => $min, 'b' => $max]);

        return (int) D::conexion()->fetchOne('SELECT "TES_Tipo_Especificacion" FROM "INV_TIPO_ESPECIFICACION" WHERE "TES_Nombre" = :n', ['n' => $name]);
    }

    /** @return array<string, mixed> */
    private function asset(): array
    {
        return $this->ok(201, 'POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'SN-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-10-01', 'ACT_Costo' => 100,
            'MOD_Modelo' => $this->model, 'TAC_Tipo_Activo' => $this->assetType, 'EOP_Estado_Operativo' => I::estado('En Bodega'), 'SED_Sede' => $this->site], $this->admin);
    }

    public function testAllowedValuesLiveInATableAndKeepTheirOrder(): void
    {
        $spec = $this->specType('TEXT');
        $this->ok(200, 'PUT', "/api/v1/catalogos/especificaciones-tipo/{$this->assetType}", ['items' => [
            ['TES_Tipo_Especificacion' => $spec, 'obligatoria' => true, 'valores' => ['SSD', 'NVMe', 'HDD']],
        ]], $this->admin);

        $read = $this->ok(200, 'GET', "/api/v1/catalogos/especificaciones-tipo/{$this->assetType}", token: $this->admin);
        self::assertSame(['SSD', 'NVMe', 'HDD'], $read[0]['valores']);
        self::assertSame(3, D::contar('SELECT COUNT(*) FROM CAT_SPEC_ALLOWED_VALUE WHERE ASV_TAC_ASSET_TYPE = :t AND ASV_TES_SPECIFICATION_TYPE = :s', ['t' => $this->assetType, 's' => $spec]));
        self::assertSame(0, D::contar("SELECT COUNT(*) FROM USER_TAB_COLUMNS WHERE TABLE_NAME = 'INV_ESPECIFICACION_TIPO_ACTIVO' AND COLUMN_NAME = 'ETA_Valores'"));

        $this->ok(200, 'PUT', "/api/v1/catalogos/especificaciones-tipo/{$this->assetType}", ['items' => [
            ['TES_Tipo_Especificacion' => $spec, 'obligatoria' => false, 'valores' => ['HDD']],
        ]], $this->admin);
        self::assertSame(['HDD'], $this->ok(200, 'GET', "/api/v1/catalogos/especificaciones-tipo/{$this->assetType}", token: $this->admin)[0]['valores']);
    }

    public function testAListWrittenInOneLineIsStoredAsSeparateValues(): void
    {
        $spec = $this->specType('TEXT');
        $this->ok(200, 'PUT', "/api/v1/catalogos/especificaciones-tipo/{$this->assetType}", ['items' => [
            ['TES_Tipo_Especificacion' => $spec, 'obligatoria' => false, 'valores' => ['SSD, NVMe; HDD', 'ssd', '2,5 pulgadas']],
        ]], $this->admin);

        $read = $this->ok(200, 'GET', "/api/v1/catalogos/especificaciones-tipo/{$this->assetType}", token: $this->admin);
        self::assertSame(['SSD', 'NVMe', 'HDD', '2,5 pulgadas'], $read[0]['valores']);
    }

    public function testAssetSpecificationsFollowTheirDataTypeEverywhere(): void
    {
        $ram = $this->specType('NUMBER', 1, 4096);
        $disk = $this->specType('TEXT');
        $this->ok(200, 'PUT', "/api/v1/catalogos/especificaciones-tipo/{$this->assetType}", ['items' => [
            ['TES_Tipo_Especificacion' => $ram, 'obligatoria' => false, 'valores' => []],
            ['TES_Tipo_Especificacion' => $disk, 'obligatoria' => false, 'valores' => ['SSD', 'HDD']],
        ]], $this->admin);
        $asset = $this->asset()['ACT_Activo'];
        $route = "/api/v1/core/activos/{$asset}/especificaciones";

        self::assertSame([422, 'SPECIFICATION_VALUE_NOT_A_NUMBER'], $this->detalle('POST', $route, ['TES_Tipo_Especificacion' => $ram, 'ESP_Valor' => '16GB DDR4'], $this->admin));
        self::assertSame([422, 'SPECIFICATION_VALUE_OUT_OF_RANGE'], $this->detalle('POST', $route, ['TES_Tipo_Especificacion' => $ram, 'ESP_Valor' => '9000'], $this->admin));
        self::assertSame([422, 'SPECIFICATION_VALUE_NOT_ALLOWED'], $this->detalle('POST', $route, ['TES_Tipo_Especificacion' => $disk, 'ESP_Valor' => 'Cinta'], $this->admin));

        $created = $this->ok(201, 'POST', $route, ['TES_Tipo_Especificacion' => $ram, 'ESP_Valor' => '16'], $this->admin);
        $this->ok(201, 'POST', $route, ['TES_Tipo_Especificacion' => $disk, 'ESP_Valor' => 'ssd'], $this->admin);
        $values = array_column($this->ok(200, 'GET', $route, token: $this->admin), 'ESP_Valor');
        self::assertContains('SSD', $values, 'A value from the list is stored as the list writes it.');

        self::assertSame([422, 'SPECIFICATION_VALUE_NOT_A_NUMBER'], $this->detalle('PATCH', "/api/v1/core/especificaciones/{$created['ESP_Especificacion']}", ['ESP_Valor' => 'mucha'], $this->admin));
        self::assertSame(204, $this->pedir('PATCH', "/api/v1/core/especificaciones/{$created['ESP_Especificacion']}", ['ESP_Valor' => '32'], $this->admin)[0]);
        self::assertTrue(I::cadenaKardexIntegra());
    }

    public function testFactoryValuesPerModelAreReadableAndValidated(): void
    {
        $ram = $this->specType('NUMBER', 1, 4096);
        $this->ok(200, 'PUT', "/api/v1/catalogos/especificaciones-tipo/{$this->assetType}", ['items' => [
            ['TES_Tipo_Especificacion' => $ram, 'obligatoria' => true, 'valores' => []],
        ]], $this->admin);
        $route = "/api/v1/goods-receipts/catalog/models/{$this->model}/spec-defaults";

        self::assertSame([422, 'SPECIFICATION_VALUE_NOT_A_NUMBER'], $this->detalle('PUT', $route, ['values' => [(string) $ram => '16GB']], $this->admin));
        $this->ok(200, 'PUT', $route, ['values' => [(string) $ram => '16']], $this->admin);

        $read = $this->ok(200, 'GET', $route, token: $this->admin);
        self::assertSame($ram, $read[0]['type_id']);
        self::assertSame('16', $read[0]['value']);
        self::assertSame('NUMBER', $read[0]['data_type']);

        $this->ok(200, 'PUT', $route, ['values' => [(string) $ram => '']], $this->admin);
        self::assertNull($this->ok(200, 'GET', $route, token: $this->admin)[0]['value']);
    }

    public function testLegacyTextIsSplitIntoAtomicValuesAndLeftTraceable(): void
    {
        $db = D::conexion();
        $ids = [];
        foreach (['RAM', 'Almacenamiento', 'Tipo de RAM', 'Tipo de disco'] as $name) {
            $id = $db->fetchOne('SELECT "TES_Tipo_Especificacion" FROM "INV_TIPO_ESPECIFICACION" WHERE "TES_Nombre" = :n', ['n' => $name]);
            if (false === $id) {
                $db->executeStatement('INSERT INTO "INV_TIPO_ESPECIFICACION" ("TES_Nombre") VALUES (:n)', ['n' => $name]);
                $id = $db->fetchOne('SELECT "TES_Tipo_Especificacion" FROM "INV_TIPO_ESPECIFICACION" WHERE "TES_Nombre" = :n', ['n' => $name]);
            }
            $ids[$name] = (int) $id;
        }
        $insert = static fn (string $asset, string $name, string $value) => $db->executeStatement('INSERT INTO "INV_ESPECIFICACION" ("ESP_Valor", "ACT_Activo", "TES_Tipo_Especificacion") VALUES (:v, :a, :t)',
            ['v' => $value, 'a' => $asset, 't' => $ids[$name]]);
        $asset = str_replace('-', '', (string) $this->asset()['ACT_Activo']);
        $other = str_replace('-', '', (string) $this->asset()['ACT_Activo']);
        $insert($asset, 'RAM', '16GB DDR4');
        $insert($asset, 'Almacenamiento', '1 TB NVMe');
        $insert($other, 'RAM', 'no sé cuánta');

        $audit = D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'SPEC_NORMALIZED'");
        foreach ([1, 2] as $run) {
            foreach (SpecificationNormalization::statements() as $sql) {
                $db->executeStatement($sql);
            }
        }

        $valueOf = static fn (string $a, string $name): mixed => $db->fetchOne('SELECT "ESP_Valor" FROM "INV_ESPECIFICACION" WHERE "ACT_Activo" = :a AND "TES_Tipo_Especificacion" = :t', ['a' => $a, 't' => $ids[$name]]);
        self::assertSame('16', $valueOf($asset, 'RAM'));
        self::assertSame('DDR4', $valueOf($asset, 'Tipo de RAM'));
        self::assertSame('1024', $valueOf($asset, 'Almacenamiento'));
        self::assertSame('NVMe', $valueOf($asset, 'Tipo de disco'));
        self::assertSame('no sé cuánta', $valueOf($other, 'RAM'), 'What cannot be read safely stays as it was.');
        self::assertSame(2, D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'SPEC_NORMALIZED'") - $audit, 'Each change leaves its previous value, and running it twice changes nothing.');
    }
}
