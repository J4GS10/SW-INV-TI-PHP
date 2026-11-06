<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;

/** /inventario, /stats y /productividad: kardex e integridad, custodia, tomas y faltantes, cierre mensual, atención y búsqueda. */
final class InventarioTest extends PruebaApi
{
    private int $sede;
    private int $area;
    private int $tipo;
    private int $modelo;

    protected function setUp(): void
    {
        parent::setUp();
        I::estados();
        I::motivosBaja();
        if (0 === D::contar("SELECT COUNT(*) FROM \"INV_TIPO_MOVIMIENTO\" WHERE \"TMO_Nombre\" = 'Asignación'")) {
            D::conexion()->executeStatement("INSERT INTO \"INV_TIPO_MOVIMIENTO\" (\"TMO_Nombre\") VALUES ('Asignación')");
        }
        $this->sede = D::crearSede('Sede I '.D::sufijo());
        $this->area = I::area($this->sede);
        $this->tipo = I::tipo('COMPUTO');
        $this->modelo = I::modelo($this->tipo);
        $this->token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function activo(array $extra = []): array
    {
        return $this->ok(201, 'POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'IN-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-10-02', 'ACT_Costo' => 1000,
            'MOD_Modelo' => $this->modelo, 'TAC_Tipo_Activo' => $this->tipo, 'EOP_Estado_Operativo' => I::estado('En Bodega'), 'SED_Sede' => $this->sede, ...$extra]);
    }

    public function testKardexLegibleConFiltrosYVerificacionDeIntegridad(): void
    {
        $a = $this->activo();
        $persona = D::crearPersona($this->sede, 'Kar');
        $uuid = strtolower(substr($persona, 0, 8).'-'.substr($persona, 8, 4).'-'.substr($persona, 12, 4).'-'.substr($persona, 16, 4).'-'.substr($persona, 20));
        $this->ok(201, 'POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $a['ACT_Activo'], 'PER_Persona' => $uuid, 'ARE_Area' => $this->area,
            'TMO_Tipo_Movimiento' => (int) D::conexion()->fetchOne("SELECT MIN(\"TMO_Tipo_Movimiento\") FROM \"INV_TIPO_MOVIMIENTO\" WHERE \"TMO_Nombre\" = 'Asignación'"),
            'MOV_Motivo' => 'Entrega']);
        $k = $this->ok(200, 'GET', "/api/v1/inventario/kardex?activo_id={$a['ACT_Activo']}&limite=10");
        self::assertSame([2, ['ASIGNACION', 'ALTA']], [$k['total'], array_column($k['items'], 'KDX_Tipo')]);
        self::assertSame(['Kar Prueba', null], [$k['items'][0]['destino_persona'], $k['items'][0]['origen_persona']]);
        $soloAltas = $this->ok(200, 'GET', "/api/v1/inventario/kardex?activo_id={$a['ACT_Activo']}&tipos=ALTA&tipos=BAJA");
        self::assertSame(['ALTA'], array_column($soloAltas['items'], 'KDX_Tipo'), 'Lista repetida en la query, como en FastAPI.');
        self::assertSame(1, $this->ok(200, 'GET', "/api/v1/inventario/kardex?activo_id={$a['ACT_Activo']}&categoria=REASIGNACIONES")['total']);
        self::assertSame(422, $this->pedir('GET', '/api/v1/inventario/kardex?limite=501')[0]);

        $jefe = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));
        $v = $this->ok(200, 'GET', '/api/v1/inventario/kardex/verificar', token: $jefe);
        self::assertTrue($v['integro'], json_encode($v['problemas']));
        self::assertSame(64, \strlen((string) $v['ultimo_hash']));
        self::assertSame(403, $this->pedir('GET', '/api/v1/inventario/kardex/verificar', token: $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede]])))[0], 'TECNICO no verifica el sellado.');
        // Solo inserción: Oracle rechaza alterar un evento (ORA-20002).
        try {
            D::conexion()->executeStatement('UPDATE "INV_KARDEX" SET "KDX_Motivo" = \'x\' WHERE "ACT_Activo" = :a', ['a' => str_replace('-', '', $a['ACT_Activo'])]);
            self::fail('El kardex debe ser de solo inserción.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('20002', $e->getMessage());
        }

        $custodia = $this->ok(200, 'GET', '/api/v1/inventario/custodia?sede_id='.$this->sede);
        self::assertSame([$a['ACT_Codigo_Interno'], 'Kar Prueba'], [$custodia[0]['activo_codigo'], $custodia[0]['persona']]);
        self::assertSame([], $this->ok(200, 'GET', '/api/v1/inventario/custodia?sede_id='.$this->sede.'&fecha=2020-01-01'), 'A una fecha anterior no tenía custodia.');
        $es = $this->ok(200, 'GET', '/api/v1/inventario/entradas-salidas?sede_id='.$this->sede);
        self::assertSame(['ALTA', 'ENTRADA', 1], [$es['por_tipo'][0]['tipo'], $es['por_tipo'][0]['clase'], $es['por_tipo'][0]['eventos']]);
        self::assertArrayHasKey('movimientos', $this->ok(200, 'GET', '/api/v1/inventario/resumen-motivos?sede_id='.$this->sede));
    }

    public function testTomaFisicaFaltanteYBajaPorPerdidaAprobada(): void
    {
        [$a1, $a2] = [$this->activo(), $this->activo()];
        $toma = $this->ok(201, 'POST', '/api/v1/inventario/tomas', ['SED_Sede' => $this->sede]);
        self::assertSame([409, "INVENTORY_COUNT_ALREADY_OPEN:{$toma['TOM_Toma']}"], $this->detalle('POST', '/api/v1/inventario/tomas', ['SED_Sede' => $this->sede]));
        $l = $this->ok(201, 'POST', "/api/v1/inventario/tomas/{$toma['TOM_Toma']}/lecturas", ['codigo' => ' '.strtolower($a1['ACT_Codigo_Interno']).' ']);
        self::assertSame(['ENCONTRADO', 2, 1], [$l['TLE_Resultado'], $l['esperados'], $l['leidos']]);
        self::assertSame('REPETIDO', $this->ok(201, 'POST', "/api/v1/inventario/tomas/{$toma['TOM_Toma']}/lecturas", ['codigo' => $a1['ACT_Serie_Fabricante']])['TLE_Resultado']);
        self::assertSame('NO_REGISTRADO', $this->ok(201, 'POST', "/api/v1/inventario/tomas/{$toma['TOM_Toma']}/lecturas", ['codigo' => 'NADA-'.D::sufijo()])['TLE_Resultado']);
        $cerrada = $this->ok(200, 'POST', "/api/v1/inventario/tomas/{$toma['TOM_Toma']}/cerrar");
        self::assertSame(['CERRADA', 1, 1, 50.0], [$cerrada['TOM_Estado'], \count($cerrada['resumen']['faltantes']), $cerrada['resumen']['faltantes_nuevos'],
            (float) $cerrada['resumen']['avance_pct']]);
        self::assertSame($a2['ACT_Codigo_Interno'], $cerrada['resumen']['faltantes'][0]['codigo']);

        $faltante = array_values(array_filter($this->ok(200, 'GET', '/api/v1/inventario/faltantes?abiertos=true'),
            static fn (array $f): bool => $f['ACT_Activo'] === $a2['ACT_Activo']))[0];
        $f = $this->ok(200, 'POST', "/api/v1/inventario/faltantes/{$faltante['FAL_Faltante']}/seguimiento", ['nota' => 'Se preguntó en contabilidad']);
        self::assertSame(['EN_INVESTIGACION', 'NOTA'], [$f['FAL_Estado'], $f['FAL_Seguimiento'][0]['accion']]);
        self::assertSame([422, 'MISSING_ITEM_DECOMMISSION_REASON'], $this->detalle('POST', "/api/v1/inventario/faltantes/{$faltante['FAL_Faltante']}/baja",
            ['motivo' => 'OBSOLESCENCIA', 'destino' => 'NO_RECUPERADO']));
        $b = $this->ok(202, 'POST', "/api/v1/inventario/faltantes/{$faltante['FAL_Faltante']}/baja", ['motivo' => 'PERDIDA', 'destino' => 'NO_RECUPERADO']);
        self::assertSame('BAJA_SOLICITADA', $b['FAL_Estado']);
        $jefe = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
        $this->ok(200, 'POST', "/api/v1/aprobaciones/{$b['APR_Aprobacion']}/aprobar", [], $jefe);
        $final = $this->ok(200, 'GET', '/api/v1/inventario/faltantes?estado=DADO_DE_BAJA');
        self::assertContains($faltante['FAL_Faltante'], array_column($final, 'FAL_Faltante'));
        self::assertSame('Baja', $this->ok(200, 'GET', "/api/v1/core/activos/{$a2['ACT_Activo']}")['estado_operativo']['EOP_Nombre']);
        self::assertSame([409, 'INVENTORY_COUNT_NOT_OPEN'], $this->detalle('POST', "/api/v1/inventario/tomas/{$toma['TOM_Toma']}/lecturas", ['codigo' => 'x']));
    }

    public function testCierreMensualAtencionTableroYProductividad(): void
    {
        $a = $this->activo(['ACT_Hostname' => 'BUSCAME-'.strtoupper(D::sufijo())]);
        $contador = $this->tokenDe(D::crearUsuario(['rol' => 'COMPRAS', 'sedes' => [$this->sede]]));
        $c = $this->ok(200, 'GET', "/api/v1/inventario/cierre-mensual?anio=2026&mes=10&sede_id={$this->sede}", token: $contador);
        $gtq = array_values(array_filter($c['activos'], static fn (array $f): bool => 'GTQ' === $f['moneda']))[0];
        self::assertSame([1, '1000.00', 0, 1], [$gtq['altas_cantidad'], $gtq['altas_valor'], $gtq['inicial_cantidad'], $gtq['final_cantidad']]);
        self::assertSame($a['ACT_Codigo_Interno'], $c['altas'][0]['codigo']);
        self::assertSame(422, $this->pedir('GET', '/api/v1/inventario/cierre-mensual?anio=1999&mes=1', token: $contador)[0]);
        self::assertSame(403, $this->pedir('GET', '/api/v1/inventario/cierre-mensual?anio=2026&mes=10',
            token: $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede]])))[0]);

        $atencion = $this->ok(200, 'GET', '/api/v1/inventario/atencion');
        self::assertContains('DATOS_INCOMPLETOS', array_column($atencion['items'], 'clave'));
        self::assertSame([], $this->ok(200, 'GET', '/api/v1/inventario/atencion', token: $this->tokenDe(D::crearUsuario(['rol' => 'CONSULTA', 'sedes' => [$this->sede]])))['items']);

        $t = $this->ok(200, 'GET', '/api/v1/stats/dashboard');
        self::assertGreaterThanOrEqual(1, $t['activos_totales']);
        self::assertArrayHasKey('licencias_total', $t);
        $tc = $this->ok(200, 'GET', '/api/v1/stats/dashboard', token: $this->tokenDe(D::crearUsuario(['rol' => 'CONSULTA', 'sedes' => [$this->sede]])));
        self::assertArrayNotHasKey('licencias_total', $tc, 'Sin licencias para quien no ve software.');
        self::assertArrayNotHasKey('costo_inventario_por_moneda', $tc, 'Sin costos para CONSULTA.');

        $r = $this->ok(200, 'GET', '/api/v1/productividad/buscar?q='.rawurlencode($a['ACT_Hostname']));
        self::assertSame(['activo', $a['ACT_Activo']], [$r['resultados'][0]['tipo'], $r['resultados'][0]['id']]);
        self::assertSame(422, $this->pedir('GET', '/api/v1/productividad/buscar?q=a')[0]);
        $persona = D::crearPersona($this->sede, 'Kit');
        $uuid = strtolower(substr($persona, 0, 8).'-'.substr($persona, 8, 4).'-'.substr($persona, 12, 4).'-'.substr($persona, 16, 4).'-'.substr($persona, 20));
        self::assertArrayHasKey('tipos', $this->ok(200, 'GET', "/api/v1/productividad/kit-sugerido/{$uuid}"));
        self::assertSame('Kit Prueba', $this->ok(200, 'GET', "/api/v1/productividad/personas/{$uuid}/ficha")['persona']['nombre']);
        $calidad = $this->ok(200, 'GET', '/api/v1/productividad/calidad-datos');
        self::assertContains('activos_sin_serie', array_column($calidad['verificaciones'], 'clave'));
    }
}
