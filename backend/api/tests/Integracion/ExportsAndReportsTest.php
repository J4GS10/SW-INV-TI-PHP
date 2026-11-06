<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;

/** Every list can be taken away as a file, respecting the screen's filters and the role's limits. */
final class ExportsAndReportsTest extends PruebaApi
{
    private int $site;
    private string $admin;
    private string $technician;
    private string $purchasing;

    protected function setUp(): void
    {
        parent::setUp();
        I::estados();
        if (0 === D::contar("SELECT COUNT(*) FROM \"INV_TIPO_MOVIMIENTO\" WHERE \"TMO_Nombre\" = 'Asignación'")) {
            D::conexion()->executeStatement("INSERT INTO \"INV_TIPO_MOVIMIENTO\" (\"TMO_Nombre\") VALUES ('Asignación')");
        }
        $this->site = D::crearSede('Exporte '.D::sufijo());
        $this->admin = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->site]]));
        $this->technician = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->site]]));
        $this->purchasing = $this->tokenDe(D::crearUsuario(['rol' => 'COMPRAS', 'sedes' => [$this->site]]));
    }

    private function csv(string $route, string $token): string
    {
        $this->cliente->request('GET', $route, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame(200, $this->cliente->getResponse()->getStatusCode(), $route);

        return (string) $this->cliente->getResponse()->getContent();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function asset(array $extra = []): array
    {
        $type = I::tipo('COMPUTO');

        return $this->ok(201, 'POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'SN-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-10-01', 'ACT_Costo' => 100, 'MOD_Modelo' => I::modelo($type), 'TAC_Tipo_Activo' => $type,
            'EOP_Estado_Operativo' => I::estado('En Bodega'), 'SED_Sede' => $this->site, ...$extra], $this->admin);
    }

    /** @param array<string, mixed> $asset */
    private function assign(array $asset): void
    {
        $this->ok(201, 'POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $asset['ACT_Activo'], 'PER_Persona' => D::crearPersona($this->site),
            'ARE_Area' => I::area($this->site), 'TMO_Tipo_Movimiento' => (int) D::conexion()->fetchOne("SELECT MIN(\"TMO_Tipo_Movimiento\") FROM \"INV_TIPO_MOVIMIENTO\" WHERE \"TMO_Nombre\" = 'Asignación'"), 'MOV_Motivo' => 'x'], $this->admin);
    }

    public function testNewExportsWorkAndKeepTheirRoles(): void
    {
        $asset = $this->asset(['ACT_Fin_Garantia' => '2030-01-01']);

        self::assertStringContainsString($asset['ACT_Serie_Fabricante'], $this->csv('/api/v1/export/garantias.csv', $this->admin));
        self::assertStringContainsString('Falla', $this->csv('/api/v1/export/mantenimientos.csv', $this->admin));
        self::assertStringContainsString('Departamento', $this->csv('/api/v1/export/personas.csv', $this->admin));
        foreach (['garantias', 'mantenimientos', 'personas'] as $export) {
            self::assertSame(403, $this->pedir('GET', "/api/v1/export/{$export}.csv", token: $this->technician)[0], $export);
        }
    }

    public function testFiltersReachTheFile(): void
    {
        $asset = $this->asset();
        $serial = $asset['ACT_Serie_Fabricante'];
        $this->assign($asset);

        self::assertStringContainsString($serial, $this->csv("/api/v1/export/movimientos.csv?q={$serial}&estado=vigente", $this->admin));
        self::assertStringNotContainsString($serial, $this->csv("/api/v1/export/movimientos.csv?q={$serial}&estado=cerrado", $this->admin));
        self::assertStringNotContainsString($serial, $this->csv('/api/v1/export/movimientos.csv?q=no-existe-'.D::sufijo(), $this->admin));

        $this->csv('/api/v1/export/consumibles.csv?q=toner', $this->admin);
        $this->csv('/api/v1/export/consumibles.csv?solo_bajo=true', $this->admin);
    }

    public function testPurchasingExportsItsReportsAndLeavesARecord(): void
    {
        $before = D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'REPORT_EXPORT'");
        self::assertStringContainsString('Disponibles', $this->csv('/api/v1/reports/stock.csv', $this->purchasing));
        self::assertStringContainsString('Documento', $this->csv('/api/v1/reports/entries.csv?desde=2026-01-01', $this->purchasing));
        self::assertStringContainsString('Persona', $this->csv('/api/v1/reports/assignments.csv', $this->purchasing));
        self::assertSame(3, D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'REPORT_EXPORT'") - $before);
        self::assertSame(403, $this->pedir('GET', '/api/v1/reports/stock.csv', token: $this->technician)[0]);
    }

    public function testAssetSearchShowsWhoHoldsItOnlyToWhoCanSeePeople(): void
    {
        $asset = $this->asset();
        $this->assign($asset);
        $body = ['q' => $asset['ACT_Serie_Fabricante']];

        self::assertNotEmpty($this->ok(200, 'POST', '/api/v1/core/activos/search', $body, $this->technician)['items'][0]['custodio']);
        $reader = $this->tokenDe(D::crearUsuario(['rol' => 'CONSULTA', 'sedes' => [$this->site]]));
        self::assertArrayNotHasKey('custodio', $this->ok(200, 'POST', '/api/v1/core/activos/search', $body, $reader)['items'][0]);
    }

    public function testASpecificationDeclaresItsDataTypeFromTheCatalog(): void
    {
        $name = 'Velocidad '.D::sufijo();
        $created = $this->ok(201, 'POST', '/api/v1/catalogos/tipos-especificacion', ['nombre' => $name, 'extras' => ['TES_Data_Type' => 'NUMBER', 'TES_Min_Value' => 1, 'TES_Max_Value' => 10]], $this->admin);
        self::assertSame('NUMBER', $created['extras']['TES_Data_Type']);
        self::assertSame([422, 'CATALOG_FIELD_INVALID:TES_Data_Type'], $this->detalle('POST', '/api/v1/catalogos/tipos-especificacion', ['nombre' => $name.' b', 'extras' => ['TES_Data_Type' => 'OTRO']], $this->admin));
    }
}
