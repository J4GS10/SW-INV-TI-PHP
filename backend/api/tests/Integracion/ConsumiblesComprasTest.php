<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;

/** /consumibles, /compras y su lazo con aprobaciones: valoración, stock atómico y licencias. */
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
