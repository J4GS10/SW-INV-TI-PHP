<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;

/** /consumibles, /compras y su lazo con aprobaciones: valoración, stock atómico, recepciones y diferencias. */
final class ConsumiblesComprasTest extends PruebaApi
{
    private int $sede;
    private string $jefe;

    protected function setUp(): void
    {
        parent::setUp();
        I::estados();
        $this->sede = D::crearSede('Bodega '.D::sufijo());
        $this->token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
        $this->jefe = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function consumible(array $extra = []): array
    {
        return $this->ok(201, 'POST', '/api/v1/consumibles', ['CON_Nombre' => 'Tóner '.D::sufijo(), 'CON_Stock_Minimo' => 5, ...$extra]);
    }

    public function testValoracionPorCostoPromedioYStockAtomico(): void
    {
        $c = $this->consumible(['CON_Stock_Actual' => 10, 'CON_Costo_Unitario' => 10]);
        self::assertSame([10, '10.0000', 'GTQ', $this->sede, false], [$c['CON_Stock_Actual'], $c['CON_Costo_Unitario'], $c['CON_Moneda'], $c['SED_Sede'], $c['bajo_stock']]);
        $id = $c['CON_Consumible'];
        // (10 × 10 + 5 × 13) / 15 = 11.0000
        $m = $this->ok(201, 'POST', "/api/v1/consumibles/{$id}/entrada", ['MOC_Cantidad' => 5, 'precio_unitario' => 13]);
        self::assertSame(['ENTRADA', 15], [$m['MOC_Tipo'], $m['MOC_Stock_Resultante']]);
        self::assertSame('11.0000', $this->ok(200, 'GET', "/api/v1/consumibles/{$id}")['CON_Costo_Unitario']);
        self::assertSame([400, 'CURRENCY_DIFFERS_FROM_VALUATION:GTQ'], $this->detalle('POST', "/api/v1/consumibles/{$id}/entrada", ['MOC_Cantidad' => 1, 'precio_unitario' => 1, 'moneda' => 'USD']));
        self::assertSame([409, 'INSUFFICIENT_STOCK'], $this->detalle('POST', "/api/v1/consumibles/{$id}/salida", ['MOC_Cantidad' => 16]));
        self::assertSame(15, $this->ok(200, 'GET', "/api/v1/consumibles/{$id}")['CON_Stock_Actual'], 'Una salida rechazada no toca el stock.');

        // Cruzar el mínimo avisa (una sola vez) y la respuesta propone reabastecer.
        $antes = D::contar("SELECT COUNT(*) FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Plantilla\" = 'stock_bajo'");
        $s = $this->ok(201, 'POST', "/api/v1/consumibles/{$id}/salida", ['MOC_Cantidad' => 11, 'MOC_Motivo' => 'Entrega']);
        self::assertSame([4, true, 5], [$s['MOC_Stock_Resultante'], $s['bajo_minimo'], $s['CON_Stock_Minimo']]);
        $this->ok(201, 'POST', "/api/v1/consumibles/{$id}/salida", ['MOC_Cantidad' => 1]);
        self::assertSame($antes + 1, D::contar("SELECT COUNT(*) FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Plantilla\" = 'stock_bajo'"));

        $kardex = D::conexion()->fetchAllAssociative("SELECT \"KDX_Tipo\", \"KDX_Saldo\", \"KDX_Saldo_Valor\" FROM \"INV_KARDEX\" WHERE \"CON_Consumible\" = :c ORDER BY \"KDX_Secuencia\"", ['c' => $id]);
        self::assertSame(['CONSUMIBLE_ENTRADA', 'CONSUMIBLE_ENTRADA', 'CONSUMIBLE_SALIDA', 'CONSUMIBLE_SALIDA'], array_column($kardex, 'KDX_Tipo'));
        self::assertSame([10, 15, 4, 3], array_map('intval', array_column($kardex, 'KDX_Saldo')), 'El kardex cuadra con la existencia.');
        self::assertSame('33', rtrim(rtrim((string) $kardex[3]['KDX_Saldo_Valor'], '0'), '.'), '3 × 11.0000');
        self::assertCount(3, $this->ok(200, 'GET', "/api/v1/consumibles/{$id}/movimientos"), 'El saldo inicial va al kardex, no es un movimiento.');
        self::assertSame([409, 'CANNOT_DELETE_CONSUMABLE_HAS_MOVEMENTS'], $this->detalle('DELETE', "/api/v1/consumibles/{$id}"));
        self::assertSame([409, 'CONSUMABLE_ALREADY_EXISTS'], $this->detalle('POST', '/api/v1/consumibles', ['CON_Nombre' => $c['CON_Nombre']]));
    }

    public function testTodoAjustePorConteoPasaPorAprobacion(): void
    {
        $id = $this->consumible(['CON_Stock_Actual' => 8])['CON_Consumible'];
        // Inflar la existencia también oculta sustracciones: subir requiere aprobación (fase 8).
        $sube = $this->ok(202, 'POST', "/api/v1/consumibles/{$id}/ajuste", ['CON_Stock_Real' => 10, 'MOC_Motivo' => 'Conteo físico']);
        self::assertSame(8, $this->ok(200, 'GET', "/api/v1/consumibles/{$id}")['CON_Stock_Actual'], 'No sube hasta que se aprueba.');
        self::assertSame([400, 'STOCK_ALREADY_MATCHES'], $this->detalle('POST', "/api/v1/consumibles/{$id}/ajuste", ['CON_Stock_Real' => 8, 'MOC_Motivo' => 'Conteo físico']));
        $this->ok(200, 'POST', "/api/v1/aprobaciones/{$sube['APR_Aprobacion']}/aprobar", [], $this->jefe);
        self::assertSame([10, 'AJUSTE'], [$this->ok(200, 'GET', "/api/v1/consumibles/{$id}")['CON_Stock_Actual'],
            $this->ok(200, 'GET', "/api/v1/consumibles/{$id}/movimientos")[0]['MOC_Tipo']]);
        $s = $this->ok(202, 'POST', "/api/v1/consumibles/{$id}/ajuste", ['CON_Stock_Real' => 7, 'MOC_Motivo' => 'Conteo físico']);
        self::assertSame(['AJUSTE_CONSUMIBLE', 10], [$s['APR_Tipo'], $s['APR_Payload']['stock_antes']]);
        self::assertSame(10, $this->ok(200, 'GET', "/api/v1/consumibles/{$id}")['CON_Stock_Actual'], 'No baja hasta que se aprueba.');
        self::assertSame(409, $this->pedir('POST', "/api/v1/consumibles/{$id}/ajuste", ['CON_Stock_Real' => 6, 'MOC_Motivo' => 'Otro conteo'])[0]);
        $this->ok(200, 'POST', "/api/v1/aprobaciones/{$s['APR_Aprobacion']}/aprobar", [], $this->jefe);
        self::assertSame(7, $this->ok(200, 'GET', "/api/v1/consumibles/{$id}")['CON_Stock_Actual']);

        // Si la existencia cambió desde la solicitud, la aprobación no ejecuta nada.
        $s2 = $this->ok(202, 'POST', "/api/v1/consumibles/{$id}/ajuste", ['CON_Stock_Real' => 5, 'MOC_Motivo' => 'Conteo físico']);
        $this->ok(201, 'POST', "/api/v1/consumibles/{$id}/entrada", ['MOC_Cantidad' => 1]);
        self::assertSame([409, 'STOCK_CHANGED_SINCE_REQUEST'], $this->detalle('POST', "/api/v1/aprobaciones/{$s2['APR_Aprobacion']}/aprobar", [], $this->jefe));
        self::assertSame('PENDIENTE', $this->ok(200, 'GET', '/api/v1/aprobaciones?estado=PENDIENTE')[0]['APR_Estado']);
    }

    public function testOrdenRecepcionParcialSobranteAprobadoYCierreConFaltante(): void
    {
        $prv = I::proveedor();
        $con = $this->consumible();
        $tipo = I::tipo('COMPUTO');
        $modelo = I::modelo($tipo);
        $orden = $this->ok(201, 'POST', '/api/v1/compras/ordenes', ['OCO_Numero' => 'OC-'.D::sufijo(), 'OCO_Fecha' => '2026-10-01', 'PRV_Proveedor' => $prv,
            'lineas' => [
                ['OCL_Descripcion' => 'Tóner', 'OCL_Cantidad' => 10, 'OCL_Precio_Unitario' => 25.5, 'OCL_Tipo' => 'CONSUMIBLE', 'CON_Consumible' => $con['CON_Consumible']],
                ['OCL_Descripcion' => 'Laptop', 'OCL_Cantidad' => 2, 'OCL_Precio_Unitario' => 7000, 'OCL_Tipo' => 'ACTIVO'],
                ['OCL_Descripcion' => 'Flete', 'OCL_Cantidad' => 1, 'OCL_Precio_Unitario' => 100, 'OCL_Tipo' => 'OTRO'],
            ]]);
        self::assertSame(['BORRADOR', '14355.00', $this->sede], [$orden['OCO_Estado'], $orden['OCO_Total'], $orden['SED_Sede']]);
        [$lCon, $lAct, $lOtro] = array_column($orden['lineas'], 'OCL_Linea');
        $id = $orden['OCO_Orden'];

        self::assertSame([400, "RECEIPT_EXCEEDS_PENDING:{$lCon}:10"], $this->detalle('POST', "/api/v1/compras/ordenes/{$id}/recibir",
            ['consumibles' => [['OCL_Linea' => $lCon, 'CON_Consumible' => $con['CON_Consumible'], 'cantidad' => 11]]]));
        self::assertSame([400, "PRICE_DIFFERENCE_REQUIRES_JUSTIFICATION:{$lCon}"], $this->detalle('POST', "/api/v1/compras/ordenes/{$id}/recibir",
            ['consumibles' => [['OCL_Linea' => $lCon, 'CON_Consumible' => $con['CON_Consumible'], 'cantidad' => 4, 'precio_unitario' => 30]]]));
        self::assertSame([400, "LINE_TYPE_MISMATCH:{$lAct}"], $this->detalle('POST', "/api/v1/compras/ordenes/{$id}/recibir",
            ['otros' => [['OCL_Linea' => $lAct, 'cantidad' => 1]]]));

        $r = $this->ok(200, 'POST', "/api/v1/compras/ordenes/{$id}/recibir", ['documento' => 'GR-1',
            'consumibles' => [['OCL_Linea' => $lCon, 'CON_Consumible' => $con['CON_Consumible'], 'cantidad' => 4]],
            'activos' => [['OCL_Linea' => $lAct, 'ACT_Serie_Fabricante' => 'OC-SN-'.D::sufijo(), 'MOD_Modelo' => $modelo, 'TAC_Tipo_Activo' => $tipo,
                'ACT_Fecha_Compra' => '2026-10-05', 'ACT_Fin_Garantia' => '2027-10-05']],
            'otros' => [['OCL_Linea' => $lOtro, 'cantidad' => 1]]]);
        self::assertSame(['PARCIAL', 1, 1], [$r['OCO_Estado'], $r['consumibles_reabastecidos'], $r['activos_creados']]);
        self::assertSame([[$lCon, 6], [$lAct, 1]], array_map(static fn (array $p): array => [$p['OCL_Linea'], $p['pendiente']], $r['pendientes']));
        $stock = $this->ok(200, 'GET', "/api/v1/consumibles/{$con['CON_Consumible']}");
        self::assertSame([4, '25.5000'], [$stock['CON_Stock_Actual'], $stock['CON_Costo_Unitario']]);
        $activo = $this->ok(200, 'POST', '/api/v1/core/activos/search', ['q' => $r['activos_codigos'][0]])['items'][0];
        self::assertSame(['En Bodega', '7000.00', $this->sede], [$activo['estado_operativo']['EOP_Nombre'], $activo['ACT_Costo'], $activo['SED_Sede']]);
        $garantias = $this->ok(200, 'GET', '/api/v1/compras/garantias?dias=3650');
        self::assertContains($r['activos_codigos'][0], array_column($garantias, 'ACT_Codigo_Interno'));

        // Sobrante: no entra hasta que otra persona lo aprueba.
        $sobra = $this->ok(200, 'POST', "/api/v1/compras/ordenes/{$id}/recibir", [
            'consumibles' => [['OCL_Linea' => $lCon, 'CON_Consumible' => $con['CON_Consumible'], 'cantidad' => 8]],
            'diferencias' => [['OCL_Linea' => $lCon, 'motivo' => 'Llegaron de más', 'aceptar_sobrante' => true]]]);
        self::assertNotNull($sobra['aprobacion_pendiente']);
        self::assertSame(['SOBRANTE', null], [$sobra['diferencias'][0]['tipo'], $sobra['REC_Recepcion']]);
        // Un reintento no crea otra solicitud: aprobar las dos duplicaría el ingreso.
        self::assertSame([409, "APPROVAL_ALREADY_PENDING:{$sobra['aprobacion_pendiente']}"], $this->detalle('POST', "/api/v1/compras/ordenes/{$id}/recibir", [
            'consumibles' => [['OCL_Linea' => $lCon, 'CON_Consumible' => $con['CON_Consumible'], 'cantidad' => 8]],
            'diferencias' => [['OCL_Linea' => $lCon, 'motivo' => 'Llegaron de más', 'aceptar_sobrante' => true]]]));
        self::assertSame(4, $this->ok(200, 'GET', "/api/v1/consumibles/{$con['CON_Consumible']}")['CON_Stock_Actual']);
        $this->ok(200, 'POST', "/api/v1/aprobaciones/{$sobra['aprobacion_pendiente']}/aprobar", [], $this->jefe);
        self::assertSame(12, $this->ok(200, 'GET', "/api/v1/consumibles/{$con['CON_Consumible']}")['CON_Stock_Actual']);
        $recepciones = $this->ok(200, 'GET', "/api/v1/compras/ordenes/{$id}/recepciones");
        self::assertSame(['PARCIAL', 'SOBRANTE'], [$recepciones[1]['REC_Resultado'], $recepciones[1]['lineas'][0]['RCL_Diferencia']]);

        // Lo que falta (una laptop) no llegará: cierre con faltante aprobado.
        $cierre = $this->ok(202, 'POST', "/api/v1/compras/ordenes/{$id}/cerrar", ['motivo' => 'El proveedor no tiene más']);
        $this->ok(200, 'POST', "/api/v1/aprobaciones/{$cierre['APR_Aprobacion']}/aprobar", [], $this->jefe);
        self::assertSame('CERRADA_CON_FALTANTE', $this->ok(200, 'GET', "/api/v1/compras/ordenes/{$id}")['OCO_Estado']);
        self::assertSame([409, 'ORDER_NOT_RECEIVABLE'], $this->detalle('POST', "/api/v1/compras/ordenes/{$id}/recibir",
            ['otros' => [['OCL_Linea' => $lOtro, 'cantidad' => 1]]]));
    }

    public function testCancelacionSoloDeBorradorYConMotivo(): void
    {
        $orden = $this->ok(201, 'POST', '/api/v1/compras/ordenes', ['OCO_Numero' => 'OC-'.D::sufijo(), 'OCO_Fecha' => '2026-10-01', 'PRV_Proveedor' => I::proveedor()]);
        $id = $orden['OCO_Orden'];
        self::assertSame([409, 'ORDER_STATE_TRANSITION_NOT_ALLOWED'], $this->detalle('PATCH', "/api/v1/compras/ordenes/{$id}/estado", ['OCO_Estado' => 'RECIBIDA']));
        self::assertSame([422, 'CANCEL_REASON_REQUIRED'], $this->detalle('PATCH', "/api/v1/compras/ordenes/{$id}/estado", ['OCO_Estado' => 'CANCELADA']));
        $s = $this->ok(202, 'PATCH', "/api/v1/compras/ordenes/{$id}/estado", ['OCO_Estado' => 'CANCELADA', 'motivo' => 'Ya no se necesita']);
        $this->ok(200, 'POST', "/api/v1/aprobaciones/{$s['APR_Aprobacion']}/aprobar", [], $this->jefe);
        self::assertSame(['CANCELADA', 'Ya no se necesita'], [$this->ok(200, 'GET', "/api/v1/compras/ordenes/{$id}")['OCO_Estado'],
            $this->ok(200, 'GET', "/api/v1/compras/ordenes/{$id}")['OCO_Motivo_Cierre']]);
        self::assertSame([409, 'PURCHASE_ORDER_NUMBER_EXISTS'], $this->detalle('POST', '/api/v1/compras/ordenes', ['OCO_Numero' => $orden['OCO_Numero'],
            'OCO_Fecha' => '2026-10-01', 'PRV_Proveedor' => I::proveedor()]));
        $compras = $this->tokenDe(D::crearUsuario(['rol' => 'COMPRAS', 'sedes' => [$this->sede]]));
        self::assertSame(403, $this->pedir('POST', "/api/v1/compras/ordenes/{$id}/recibir", ['otros' => []], $compras)[0], 'Recibir en bodega es de TI.');
        self::assertSame(403, $this->pedir('GET', '/api/v1/consumibles/1/movimientos', token: $compras)[0]);
    }

    public function testSobranteDeLicenciasNoGuardaLasClavesEnClaroEnLaAprobacion(): void
    {
        $orden = $this->ok(201, 'POST', '/api/v1/compras/ordenes', ['OCO_Numero' => 'OC-'.D::sufijo(), 'OCO_Fecha' => '2026-10-01', 'PRV_Proveedor' => I::proveedor(),
            'lineas' => [['OCL_Descripcion' => 'Suite', 'OCL_Cantidad' => 1, 'OCL_Precio_Unitario' => 50, 'OCL_Tipo' => 'LICENCIA']]]);
        $linea = $orden['lineas'][0]['OCL_Linea'];
        $clave = 'SECRETA-'.D::sufijo();
        $referencia = 'REF-'.D::sufijo();
        $sobra = $this->ok(200, 'POST', "/api/v1/compras/ordenes/{$orden['OCO_Orden']}/recibir", [
            'licencias' => [['OCL_Linea' => $linea, 'SOF_Software' => I::software(), 'TLI_Tipo_Licencia' => I::tipoLicencia(),
                'claves' => [['LCL_Clave_Activacion' => $clave, 'LCL_Referencia' => $referencia], ['LCL_Clave_Activacion' => $clave.'-2', 'LCL_Referencia' => $referencia]]]],
            'diferencias' => [['OCL_Linea' => $linea, 'motivo' => 'Vino una clave de más', 'aceptar_sobrante' => true]]]);
        $aprobacion = $sobra['aprobacion_pendiente'];
        self::assertNotNull($aprobacion);
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "INV_APROBACION" WHERE "APR_Aprobacion" = :a AND DBMS_LOB.INSTR("APR_Payload", :c) > 0',
            ['a' => $aprobacion, 'c' => $clave]), 'La clave nunca queda en claro en la aprobación.');
        $listado = (string) json_encode($this->ok(200, 'GET', '/api/v1/aprobaciones', null, $this->jefe));
        self::assertStringContainsString((string) $aprobacion, $listado);
        self::assertStringNotContainsString($clave, $listado);

        $this->ok(200, 'POST', "/api/v1/aprobaciones/{$aprobacion}/aprobar", [], $this->jefe);
        self::assertSame(2, D::contar('SELECT COUNT(*) FROM "INV_LICENCIA_CLAVE" WHERE "LCL_Referencia" = :r', ['r' => $referencia]),
            'Al aprobar, las claves se descifran y se registran como credenciales.');
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "INV_LICENCIA_CLAVE" WHERE "LCL_Referencia" = :r AND "LCL_Clave_Activacion" = :c',
            ['r' => $referencia, 'c' => $clave]), 'La credencial se guarda cifrada.');
    }

    public function testUnaCredencialQueDebeRotarseNoSeEntregaAOtraPersona(): void
    {
        $sw = I::software();
        I::formaConteo('EQUIPO');
        $lic = $this->ok(201, 'POST', '/api/v1/soft/licencias', ['SOF_Software' => $sw, 'TLI_Tipo_Licencia' => I::tipoLicencia(), 'LIC_Cantidad_Total' => 1,
            'LIC_Claves' => [['LCL_Clave_Activacion' => 'ROTAR-'.D::sufijo()]]]);
        $credencial = $this->ok(200, 'GET', "/api/v1/soft/licencias/{$lic['LIC_Licencia']}/claves")[0]['LCL_Licencia_Clave'];
        // La dejó un empleado que salió (offboarding): el secreto que conocía debe cambiarse antes de reasignarla.
        D::conexion()->executeStatement('UPDATE "INV_LICENCIA_CLAVE" SET "LCL_Requiere_Cambio" = 1 WHERE "LCL_Licencia_Clave" = :c', ['c' => $credencial]);
        $tipo = I::tipo('COMPUTO');
        $activo = $this->ok(201, 'POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'ROT-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-01-01',
            'MOD_Modelo' => I::modelo($tipo), 'TAC_Tipo_Activo' => $tipo, 'EOP_Estado_Operativo' => I::estado('En Bodega'), 'SED_Sede' => $this->sede])['ACT_Activo'];

        self::assertSame([409, 'NO_LICENSE_KEYS_AVAILABLE'], $this->detalle('POST', '/api/v1/soft/instalaciones', ['ACT_Activo' => $activo, 'LIC_Licencia' => $lic['LIC_Licencia']]));
        self::assertSame([409, 'LICENSE_KEY_NOT_AVAILABLE'], $this->detalle('POST', '/api/v1/soft/instalaciones',
            ['ACT_Activo' => $activo, 'LIC_Licencia' => $lic['LIC_Licencia'], 'LCL_Licencia_Clave' => $credencial]));
        self::assertSame(0, $this->ok(200, 'GET', "/api/v1/soft/licencias/{$lic['LIC_Licencia']}")['LIC_Cantidad_Usada'], 'El intento no consume cupo.');

        D::conexion()->executeStatement('UPDATE "INV_LICENCIA_CLAVE" SET "LCL_Requiere_Cambio" = 0 WHERE "LCL_Licencia_Clave" = :c', ['c' => $credencial]);
        self::assertSame($credencial, $this->ok(201, 'POST', '/api/v1/soft/instalaciones', ['ACT_Activo' => $activo, 'LIC_Licencia' => $lic['LIC_Licencia']])['LCL_Licencia_Clave']);
    }

    public function testLicenciasCuposClavesYCredenciales(): void
    {
        $sw = I::software();
        $tl = I::tipoLicencia();
        I::formaConteo('EQUIPO');
        $lic = $this->ok(201, 'POST', '/api/v1/soft/licencias', ['SOF_Software' => $sw, 'TLI_Tipo_Licencia' => $tl, 'LIC_Cantidad_Total' => 2,
            'LIC_Claves' => [['LCL_Clave_Activacion' => 'AAAA-'.D::sufijo()], ['LCL_Clave_Activacion' => 'BBBB-'.D::sufijo()]]]);
        self::assertSame([null, 2, 2, 0], [$lic['LIC_Clave_Activacion'], $lic['LIC_Claves_Total'], $lic['LIC_Claves_Disponibles'], $lic['LIC_Cantidad_Usada']]);
        self::assertSame(422, $this->pedir('POST', '/api/v1/soft/licencias', ['SOF_Software' => $sw, 'TLI_Tipo_Licencia' => $tl, 'LIC_Cantidad_Total' => 3,
            'LIC_Claves' => [['LCL_Clave_Activacion' => 'X']]])[0], 'LICENSE_KEY_COUNT_MISMATCH');
        $claves = $this->ok(200, 'GET', "/api/v1/soft/licencias/{$lic['LIC_Licencia']}/claves");
        self::assertMatchesRegularExpression('/^•••• .{5}$/u', $claves[0]['LCL_Clave_Activacion'], 'Nunca la key completa en un listado.');

        $tipo = I::tipo('COMPUTO');
        $nuevo = fn (): string => $this->ok(201, 'POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'SW-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-01-01',
            'MOD_Modelo' => I::modelo($tipo), 'TAC_Tipo_Activo' => $tipo, 'EOP_Estado_Operativo' => I::estado('En Bodega'), 'SED_Sede' => $this->sede])['ACT_Activo'];
        [$a1, $a2, $a3] = [$nuevo(), $nuevo(), $nuevo()];
        $i1 = $this->ok(201, 'POST', '/api/v1/soft/instalaciones', ['ACT_Activo' => $a1, 'LIC_Licencia' => $lic['LIC_Licencia']]);
        self::assertNotNull($i1['LCL_Licencia_Clave']);
        self::assertSame([400, 'LICENSE_ALREADY_ASSIGNED_TO_TARGET'], $this->detalle('POST', '/api/v1/soft/instalaciones', ['ACT_Activo' => $a1, 'LIC_Licencia' => $lic['LIC_Licencia']]));
        $this->ok(201, 'POST', '/api/v1/soft/instalaciones', ['ACT_Activo' => $a2, 'LIC_Licencia' => $lic['LIC_Licencia']]);
        self::assertSame([409, 'NO_LICENSE_KEYS_AVAILABLE'], $this->detalle('POST', '/api/v1/soft/instalaciones', ['ACT_Activo' => $a3, 'LIC_Licencia' => $lic['LIC_Licencia']]));
        self::assertSame(2, $this->ok(200, 'GET', "/api/v1/soft/licencias/{$lic['LIC_Licencia']}")['LIC_Cantidad_Usada']);
        self::assertSame(422, $this->pedir('POST', '/api/v1/soft/instalaciones', ['LIC_Licencia' => $lic['LIC_Licencia']])[0], 'INSTALLATION_TARGET_XOR_REQUIRED');

        $this->ok(200, 'POST', '/api/v1/soft/instalaciones/desinstalar', ['ACT_Activo' => $a1, 'LIC_Licencia' => $lic['LIC_Licencia'], 'motivo' => 'Cambio de equipo']);
        self::assertSame([404, 'INSTALLATION_NOT_FOUND_OR_ALREADY_REMOVED'], $this->detalle('POST', '/api/v1/soft/instalaciones/desinstalar',
            ['ACT_Activo' => $a1, 'LIC_Licencia' => $lic['LIC_Licencia']]), 'Una doble desinstalación no descuenta dos veces.');
        self::assertSame(1, $this->ok(200, 'GET', "/api/v1/soft/licencias/{$lic['LIC_Licencia']}")['LIC_Cantidad_Usada']);
        $historial = $this->ok(200, 'GET', "/api/v1/soft/licencias/{$lic['LIC_Licencia']}/historial");
        $retirada = array_values(array_filter($historial, static fn (array $h): bool => !$h['INS_Estado']))[0];
        self::assertSame(['ACTIVO', 'Cambio de equipo', 2], [$retirada['destino'], $retirada['INS_Motivo_Retiro'], \count($historial)]);
        self::assertSame([409, 'CANNOT_DELETE_LICENSE_WITH_ACTIVE_INSTALLATIONS:1'], $this->detalle('DELETE', "/api/v1/soft/licencias/{$lic['LIC_Licencia']}"));
        self::assertSame(1, D::contar("SELECT COUNT(*) FROM \"INV_KARDEX\" WHERE \"LIC_Licencia\" = :l AND \"KDX_Tipo\" = 'LICENCIA_RETIRADA'", ['l' => $lic['LIC_Licencia']]));
    }
}
