<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Dominio\Ident;
use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;

/**
 * /accounting contra Oracle: categorías contables por país, valor de un equipo con sus componentes, de la
 * estación de trabajo de una persona y de los libros por categoría, con el alcance de cada rol.
 */
final class AccountingTest extends PruebaApi
{
    private int $sede;
    private int $laptopType;
    private int $ramType;
    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        I::estados();
        $this->sede = D::crearSede('Sede '.D::sufijo());
        $this->token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
        $this->category = $this->ok(201, 'POST', '/api/v1/accounting/categories', ['code' => 'QA_'.strtoupper(D::sufijo()), 'name' => 'Cómputo QA',
            'country' => 'GT', 'method' => 'STRAIGHT_LINE', 'annual_rate' => '33.33', 'account_code' => '1.2.05'])['id'];
        $this->laptopType = I::tipo('COMPUTO');
        $this->ramType = I::tipo('COMPONENTE');
        foreach ([$this->laptopType, $this->ramType] as $type) {
            self::assertSame($this->category, (int) $this->ok(200, 'PATCH', "/api/v1/cat/tipos-activo/{$type}", ['FAC_ASSET_CATEGORY' => $this->category])['FAC_ASSET_CATEGORY']);
        }
    }

    /** Custody as the system records it: an open assignment movement, not a column filled by hand. */
    private function assign(string $asset, string $person): void
    {
        $type = (int) D::conexion()->fetchOne("SELECT MIN(\"TMO_Tipo_Movimiento\") FROM \"INV_TIPO_MOVIMIENTO\" WHERE \"TMO_Nombre\" = 'Asignación'");
        $this->ok(201, 'POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $asset, 'PER_Persona' => Ident::aTexto($person),
            'ARE_Area' => I::area($this->sede), 'TMO_Tipo_Movimiento' => $type, 'MOV_Motivo' => 'Entrega']);
    }

    /** @return array<string, mixed> */
    private function asset(int $type, string $cost, string $currency = 'GTQ'): array
    {
        return $this->ok(201, 'POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'SN-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-01-15', 'ACT_Costo' => (float) $cost,
            'ACT_Moneda' => $currency, 'MOD_Modelo' => I::modelo($type), 'TAC_Tipo_Activo' => $type, 'EOP_Estado_Operativo' => I::estado('En Bodega')]);
    }

    public function testCategoriesComeWithGuatemalaAndEachCountryAddsItsOwn(): void
    {
        $all = $this->ok(200, 'GET', '/api/v1/accounting/categories');
        $computo = array_values(array_filter($all, static fn (array $c): bool => 'GT' === $c['country'] && 'GT_COMPUTO' === $c['code']))[0] ?? self::fail('Sin GT_COMPUTO.');
        self::assertSame(['33.33', 36, 'STRAIGHT_LINE', true], [$computo['annual_rate'], $computo['useful_life_months'], $computo['method'], $computo['starts_next_month']]);
        self::assertStringContainsString('Decreto 10-2012', (string) $computo['legal_basis']);

        $code = 'CR_'.strtoupper(D::sufijo());
        $cr = $this->ok(201, 'POST', '/api/v1/accounting/categories', ['code' => $code, 'name' => 'Equipo de cómputo', 'country' => 'CR', 'method' => 'STRAIGHT_LINE', 'annual_rate' => '20']);
        self::assertSame([60, 1], [$cr['useful_life_months'], $cr['row_version']]);
        self::assertSame([409, 'ACCOUNTING_CATEGORY_CODE_EXISTS'], $this->detalle('POST', '/api/v1/accounting/categories', ['code' => $code, 'name' => 'Otra', 'country' => 'CR', 'method' => 'NONE', 'annual_rate' => '0']));
        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('POST', '/api/v1/accounting/categories', ['code' => 'x y', 'name' => '', 'country' => 'CRI', 'method' => 'OTRO', 'annual_rate' => '150']));
        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('PATCH', "/api/v1/accounting/categories/{$cr['id']}", ['annual_rate' => '0']), 'Línea recta sin tasa.');

        [$status, $edited] = $this->pedir('PATCH', "/api/v1/accounting/categories/{$cr['id']}", ['name' => 'Cómputo', 'residual_percent' => '10'], cabeceras: ['If-Match' => '"1"']);
        self::assertSame([200, 'Cómputo', '10', 2], [$status, $edited['name'], $edited['residual_percent'], $edited['row_version']]);
        $stale = $this->pedir('PATCH', "/api/v1/accounting/categories/{$cr['id']}", ['name' => 'Tarde'], cabeceras: ['If-Match' => '"1"']);
        self::assertSame([412, 'RECORD_CHANGED_BY_ANOTHER_USER'], [$stale[0], $stale[1]['detail'] ?? null]);

        // Una categoría desactivada ya no se puede elegir para un tipo.
        $this->ok(200, 'PATCH', "/api/v1/accounting/categories/{$cr['id']}", ['active' => false]);
        self::assertSame('ACCOUNTING_CATEGORY_NOT_FOUND', $this->detalle('PATCH', "/api/v1/cat/tipos-activo/{$this->laptopType}", ['FAC_ASSET_CATEGORY' => $cr['id']])[1]);
        self::assertSame(404, $this->pedir('PATCH', '/api/v1/accounting/categories/999999999', ['name' => 'X'])[0]);

        // Contabilidad (Compras) define categorías; el auditor las lee; quien no ve costos, nada.
        $compras = $this->tokenDe(D::crearUsuario(['rol' => 'COMPRAS', 'sedes' => [$this->sede]]));
        $this->ok(201, 'POST', '/api/v1/accounting/categories', ['code' => 'PA_'.strtoupper(D::sufijo()), 'name' => 'Mobiliario', 'country' => 'PA', 'method' => 'STRAIGHT_LINE', 'annual_rate' => '10'], $compras);
        $auditor = $this->tokenDe(D::crearUsuario(['rol' => 'AUDITOR', 'sedes' => [$this->sede]]));
        $this->ok(200, 'GET', '/api/v1/accounting/categories', token: $auditor);
        self::assertSame(403, $this->pedir('POST', '/api/v1/accounting/categories', ['code' => 'X'], $auditor)[0]);
        $tecnico = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede]]));
        self::assertSame(403, $this->pedir('GET', '/api/v1/accounting/categories', token: $tecnico)[0]);
    }

    public function testAnEquipmentAndAWorkstationAddUpTheirComponents(): void
    {
        $laptop = $this->asset($this->laptopType, '12000');
        $ram = $this->asset($this->ramType, '1200');
        $monitor = $this->asset(I::tipo('PERIFERICO'), '300', 'USD');
        $this->ok(200, 'POST', "/api/v1/core/activos/{$laptop['ACT_Activo']}/componentes", ['ACT_Componente' => $ram['ACT_Activo'], 'motivo' => 'Ampliación']);

        $v = $this->ok(200, 'GET', "/api/v1/accounting/assets/{$laptop['ACT_Activo']}/valuation?as_of=2026-06-30");
        self::assertSame(['12000.00', '333.33', 5, '1666.65', '10333.35', 'DEPRECIATING', '2026-02-01', '2029-01-31'], [
            $v['asset']['acquisition_cost'], $v['asset']['monthly_depreciation'], $v['asset']['months_elapsed'], $v['asset']['accumulated_depreciation'],
            $v['asset']['book_value'], $v['asset']['status'], $v['asset']['depreciation_starts_on'], $v['asset']['fully_depreciated_on']]);
        self::assertSame('1.2.05', $v['asset']['category']['account_code']);
        $component = $v['asset']['components'][0] ?? self::fail('Sin la RAM instalada.');
        self::assertSame([$ram['ACT_Activo'], $laptop['ACT_Activo'], '1200.00', '166.65', '1033.35'],
            [$component['asset_id'], $component['parent_id'], $component['acquisition_cost'], $component['accumulated_depreciation'], $component['book_value']]);
        $equipment = [['currency' => 'GTQ', 'count' => 2, 'acquisition_cost' => '13200.00', 'accumulated_depreciation' => '1833.30', 'book_value' => '11366.70']];
        self::assertSame($equipment, $v['totals']);
        self::assertSame($equipment, $v['asset']['totals'], 'El equipo suma lo instalado en él.');
        self::assertSame([2026, 2027, 2028, 2029], array_column($v['schedule'], 'year'));

        // La estación de trabajo: lo que la persona tiene a su cargo, con los componentes de cada equipo.
        $person = D::crearPersona($this->sede, 'Ana');
        $this->assign($laptop['ACT_Activo'], $person);
        $this->assign($monitor['ACT_Activo'], $person);
        $w = $this->ok(200, 'GET', '/api/v1/accounting/persons/'.Ident::aTexto($person).'/workstation?as_of=2026-06-30');
        self::assertSame('Ana Prueba', $w['person']['name']);
        self::assertCount(2, $w['items'], 'La RAM va dentro de la laptop, no como otro equipo.');
        self::assertSame([
            ['currency' => 'GTQ', 'count' => 2, 'acquisition_cost' => '13200.00', 'accumulated_depreciation' => '1833.30', 'book_value' => '11366.70'],
            ['currency' => 'USD', 'count' => 1, 'acquisition_cost' => '300.00', 'accumulated_depreciation' => '0.00', 'book_value' => '300.00'],
        ], $w['totals'], 'Quetzales y dólares no se suman.');
        $sinCategoria = array_values(array_filter($w['items'], static fn (array $i): bool => 'USD' === $i['currency']))[0];
        self::assertSame(['NO_CATEGORY', '300.00'], [$sinCategoria['status'], $sinCategoria['book_value']]);
        self::assertSame(['without_cost' => 0, 'without_category' => 1], $w['pending']);

        // El detalle por equipo o por persona es de TI y del auditor; Compras ve el resumen; quien no ve costos, nada.
        $compras = $this->tokenDe(D::crearUsuario(['rol' => 'COMPRAS', 'sedes' => [$this->sede]]));
        self::assertSame(403, $this->pedir('GET', "/api/v1/accounting/assets/{$laptop['ACT_Activo']}/valuation", token: $compras)[0]);
        $tecnico = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede]]));
        self::assertSame(403, $this->pedir('GET', '/api/v1/accounting/persons/'.Ident::aTexto($person).'/workstation', token: $tecnico)[0]);
        $ajeno = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [D::crearSede('Otra '.D::sufijo())]]));
        self::assertSame([404, 'ASSET_NOT_FOUND'], $this->detalle('GET', "/api/v1/accounting/assets/{$laptop['ACT_Activo']}/valuation", token: $ajeno), 'Otra sede no lo ve (VPD).');
        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('GET', "/api/v1/accounting/assets/{$laptop['ACT_Activo']}/valuation?as_of=30-06-2026"));
    }

    public function testTheBooksSummaryByCategoryAndTheRegisterFile(): void
    {
        $laptop = $this->asset($this->laptopType, '12000');
        $ram = $this->asset($this->ramType, '1200');
        $sinCosto = $this->ok(201, 'POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'SN-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-01-15',
            'MOD_Modelo' => I::modelo($this->laptopType), 'TAC_Tipo_Activo' => $this->laptopType, 'EOP_Estado_Operativo' => I::estado('En Bodega')]);
        // La RAM va instalada en la laptop de Luis: cuenta para él aunque no esté a su nombre.
        $this->ok(200, 'POST', "/api/v1/core/activos/{$laptop['ACT_Activo']}/componentes", ['ACT_Componente' => $ram['ACT_Activo'], 'motivo' => 'Ampliación']);
        $person = D::crearPersona($this->sede, 'Luis');
        $this->assign($laptop['ACT_Activo'], $person);

        $s = $this->ok(200, 'GET', "/api/v1/accounting/summary?as_of=2026-06-30&site_id={$this->sede}");
        self::assertSame([['currency' => 'GTQ', 'count' => 2, 'acquisition_cost' => '13200.00', 'accumulated_depreciation' => '1833.30', 'book_value' => '11366.70']], $s['totals']);
        self::assertSame([$this->category], array_column(array_column($s['by_category'], 'category'), 'id'));
        self::assertEqualsCanonicalizing([$this->laptopType, $this->ramType], array_column($s['by_type'], 'type_id'));
        self::assertSame(['without_cost' => 1, 'without_category' => 0], $s['pending']);
        $gtq = ['currency' => 'GTQ', 'count' => 2, 'acquisition_cost' => '13200.00', 'accumulated_depreciation' => '1833.30', 'book_value' => '11366.70'];
        self::assertSame([['person_id' => Ident::aTexto($person), 'person' => 'Luis Prueba', 'totals' => [$gtq]]],
            array_map(static fn (array $g): array => ['person_id' => $g['person_id'], 'person' => $g['person'], 'totals' => $g['totals']], $s['by_person']));
        self::assertCount(1, $s['by_department']);
        self::assertSame([[$this->sede, [$gtq]]], array_map(static fn (array $g): array => [$g['site_id'], $g['totals']], $s['by_site']));
        self::assertSame([], $this->ok(200, 'GET', "/api/v1/accounting/summary?as_of=2026-01-10&site_id={$this->sede}")['totals'], 'Lo comprado después de la fecha no está en los libros.');

        // Compras ve categorías, tipos y sedes, pero no a las personas.
        $compras = $this->tokenDe(D::crearUsuario(['rol' => 'COMPRAS', 'sedes' => [$this->sede]]));
        $deCompras = $this->ok(200, 'GET', "/api/v1/accounting/summary?site_id={$this->sede}", token: $compras);
        self::assertArrayNotHasKey('by_person', $deCompras);
        self::assertArrayNotHasKey('by_department', $deCompras);
        self::assertNotEmpty($deCompras['by_site']);

        $file = $this->archivo('GET', "/api/v1/accounting/register.csv?as_of=2026-06-30&site_id={$this->sede}&lang=es");
        self::assertSame(200, $file->getStatusCode());
        $csv = (string) $file->getContent();
        self::assertStringContainsString('Valor en libros', $csv);
        self::assertStringContainsString((string) $laptop['ACT_Codigo_Interno'], $csv);
        self::assertStringContainsString('10333.35', $csv);
        self::assertStringContainsString((string) $sinCosto['ACT_Codigo_Interno'], $csv);
        self::assertSame(1, D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'REPORT_EXPORT' AND \"AUD_Snapshot_JSON\" LIKE '%fixed_asset_register%' AND \"AUD_Snapshot_JSON\" LIKE :s",
            ['s' => '%"sede":'.$this->sede.'%']));
    }
}
