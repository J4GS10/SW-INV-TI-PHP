<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;

/** /trazabilidad y /mantenimiento: custodia, máquina de estados, offboarding, equipos compartidos, lotes y tickets. */
final class TrazabilidadTest extends PruebaApi
{
    private int $sede;
    private int $area;
    private int $tipo;
    private int $modelo;
    private string $persona;
    private string $persona2;

    protected function setUp(): void
    {
        parent::setUp();
        I::estados();
        foreach (['Asignación', 'Préstamo', 'Transferencia', 'Devolución'] as $t) {
            if (0 === D::contar('SELECT COUNT(*) FROM "INV_TIPO_MOVIMIENTO" WHERE "TMO_Nombre" = :n', ['n' => $t])) {
                D::conexion()->executeStatement('INSERT INTO "INV_TIPO_MOVIMIENTO" ("TMO_Nombre") VALUES (:n)', ['n' => $t]);
            }
        }
        $this->sede = D::crearSede('Sede T '.D::sufijo());
        $this->area = I::area($this->sede);
        $this->tipo = I::tipo('COMPUTO');
        $this->modelo = I::modelo($this->tipo);
        $this->persona = D::crearPersona($this->sede, 'Ana');
        $this->persona2 = D::crearPersona($this->sede, 'Beto');
        $this->token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
    }

    private function tipoMov(string $n): int
    {
        return (int) D::conexion()->fetchOne('SELECT MIN("TMO_Tipo_Movimiento") FROM "INV_TIPO_MOVIMIENTO" WHERE "TMO_Nombre" = :n', ['n' => $n]);
    }

    private function activo(string $clase = 'COMPUTO'): string
    {
        $tipo = 'COMPUTO' === $clase ? $this->tipo : I::tipo($clase);

        return $this->ok(201, 'POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'TR-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-01-01',
            'MOD_Modelo' => 'COMPUTO' === $clase ? $this->modelo : I::modelo($tipo), 'TAC_Tipo_Activo' => $tipo,
            'EOP_Estado_Operativo' => I::estado('En Bodega'), 'SED_Sede' => $this->sede])['ACT_Activo'];
    }

    private function uuid(string $hex): string
    {
        return strtolower(substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function asignar(string $activo, ?string $persona = null, array $extra = []): array
    {
        return $this->ok(201, 'POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $activo, 'PER_Persona' => $this->uuid($persona ?? $this->persona),
            'ARE_Area' => $this->area, 'TMO_Tipo_Movimiento' => $this->tipoMov('Asignación'), 'MOV_Motivo' => 'Nuevo ingreso', ...$extra]);
    }

    private function estado(string $activo): string
    {
        return $this->ok(200, 'GET', "/api/v1/core/activos/{$activo}")['estado_operativo']['EOP_Nombre'];
    }

    public function testCicloDeCustodiaConMaquinaDeEstados(): void
    {
        $a = $this->activo();
        $host = 'PC-'.D::sufijo();
        $m = $this->asignar($a, extra: ['ACT_Hostname' => $host]);
        self::assertSame(['Ana', 'Asignación', $this->area], [$m['persona']['PER_Primer_Nombre'], $m['tipo_movimiento']['TMO_Nombre'], $m['ARE_Area']]);
        self::assertSame(['Asignado', $host], [$this->estado($a), $this->ok(200, 'GET', "/api/v1/core/activos/{$a}")['ACT_Hostname']]);
        self::assertSame([409, 'ASSET_ALREADY_ASSIGNED_USE_TRANSFER'], $this->detalle('POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $a,
            'PER_Persona' => $this->uuid($this->persona2), 'ARE_Area' => $this->area, 'TMO_Tipo_Movimiento' => $this->tipoMov('Asignación'), 'MOV_Motivo' => 'x']));
        self::assertSame([422, 'MOVEMENT_REASON_REQUIRED'], $this->detalle('POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $this->activo(),
            'PER_Persona' => $this->uuid($this->persona2), 'ARE_Area' => $this->area, 'TMO_Tipo_Movimiento' => $this->tipoMov('Asignación')]));
        self::assertSame([400, 'MOVEMENT_TYPE_REQUIRES_SPECIFIC_OPERATION'], $this->detalle('POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $this->activo(),
            'PER_Persona' => $this->uuid($this->persona2), 'ARE_Area' => $this->area, 'TMO_Tipo_Movimiento' => $this->tipoMov('Devolución'), 'MOV_Motivo' => 'x']));

        $t = $this->ok(201, 'POST', '/api/v1/trazabilidad/transferencia', ['ACT_Activo' => $a, 'PER_Persona_Destino' => $this->uuid($this->persona2),
            'ARE_Area_Destino' => $this->area, 'MOV_Motivo' => 'Cambio de puesto']);
        self::assertSame(['Beto', 'Transferencia'], [$t['persona']['PER_Primer_Nombre'], $t['tipo_movimiento']['TMO_Nombre']]);
        self::assertSame([400, 'TRANSFER_TO_SAME_CUSTODIAN'], $this->detalle('POST', '/api/v1/trazabilidad/transferencia', ['ACT_Activo' => $a,
            'PER_Persona_Destino' => $this->uuid($this->persona2), 'ARE_Area_Destino' => $this->area, 'MOV_Motivo' => 'x']));
        $d = $this->ok(200, 'POST', '/api/v1/trazabilidad/devolucion', ['ACT_Activo' => $a, 'MOV_Motivo' => 'Renuncia', 'condicion' => 'CON_DANO']);
        self::assertSame(['En Bodega', ['ABRIR_TICKET_MANTENIMIENTO', 'DESCARGAR_HOJA_DESCARGO']], [$d['estado'], $d['siguientes_pasos']]);
        self::assertSame([400, 'ASSET_IS_NOT_ASSIGNED'], $this->detalle('POST', '/api/v1/trazabilidad/devolucion', ['ACT_Activo' => $a, 'MOV_Motivo' => 'x']));

        $historial = $this->ok(200, 'GET', "/api/v1/trazabilidad/activo/{$a}/historial");
        self::assertCount(2, $historial);
        self::assertNotNull($historial[1]['MOV_Fecha_Devolucion']);
        $kardex = array_column(D::conexion()->fetchAllAssociative('SELECT "KDX_Tipo" FROM "INV_KARDEX" WHERE "ACT_Activo" = :a ORDER BY "KDX_Secuencia"',
            ['a' => str_replace('-', '', $a)]), 'KDX_Tipo');
        self::assertSame(['ALTA', 'ASIGNACION', 'TRANSFERENCIA', 'DEVOLUCION'], $kardex);
        self::assertSame(['asignacion', 'transferencia', 'devolucion'], array_slice(array_column(D::conexion()->fetchAllAssociative(
            "SELECT \"EOB_Plantilla\" FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Creado_En\" > SYSTIMESTAMP - INTERVAL '2' MINUTE ORDER BY \"EOB_Creado_En\""), 'EOB_Plantilla'), -3));
        self::assertTrue(I::cadenaKardexIntegra());
    }

    public function testPrestamoReglasDePersonaYEquiposCompartidos(): void
    {
        $a = $this->activo();
        self::assertSame([400, 'LOAN_RETURN_DATE_REQUIRED'], $this->detalle('POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $a,
            'PER_Persona' => $this->uuid($this->persona), 'ARE_Area' => $this->area, 'TMO_Tipo_Movimiento' => $this->tipoMov('Préstamo'), 'MOV_Motivo' => 'x']));
        self::assertSame(422, $this->pedir('POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $a, 'PER_Persona' => $this->uuid($this->persona),
            'ARE_Area' => $this->area, 'TMO_Tipo_Movimiento' => $this->tipoMov('Préstamo'), 'MOV_Motivo' => 'x', 'MOV_Fecha_Prevista_Devolucion' => '2020-01-01'])[0]);
        $p = $this->asignar($a, extra: ['TMO_Tipo_Movimiento' => $this->tipoMov('Préstamo'), 'MOV_Fecha_Prevista_Devolucion' => '2099-01-01']);
        self::assertSame('2099-01-01', $p['MOV_Fecha_Prevista_Devolucion']);

        $inactiva = D::crearPersona($this->sede, 'Inactiva');
        D::conexion()->executeStatement('UPDATE "INV_PERSONA" SET "PER_Estado" = 0 WHERE "PER_Persona" = :p', ['p' => $inactiva]);
        self::assertSame([400, 'PERSON_INACTIVE'], $this->detalle('POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $this->activo(),
            'PER_Persona' => $this->uuid($inactiva), 'ARE_Area' => $this->area, 'TMO_Tipo_Movimiento' => $this->tipoMov('Asignación'), 'MOV_Motivo' => 'x']));
        $otraArea = I::area(D::crearSede('Fuera '.D::sufijo()));
        self::assertSame([403, 'SEDE_OUT_OF_SCOPE'], $this->detalle('POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $this->activo(),
            'PER_Persona' => $this->uuid($this->persona), 'ARE_Area' => $otraArea, 'TMO_Tipo_Movimiento' => $this->tipoMov('Asignación'), 'MOV_Motivo' => 'x']));

        // Una impresora se instala en un área; no se asigna a una persona.
        $impresora = $this->activo('IMPRESION');
        self::assertSame([422, 'ASSET_IS_SHARED_INSTALL_IN_AREA'], $this->detalle('POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $impresora,
            'PER_Persona' => $this->uuid($this->persona), 'ARE_Area' => $this->area, 'TMO_Tipo_Movimiento' => $this->tipoMov('Asignación'), 'MOV_Motivo' => 'x']));
        $i = $this->ok(200, 'POST', '/api/v1/trazabilidad/instalar-area', ['ACT_Activo' => $impresora, 'ARE_Area' => $this->area,
            'PER_Responsable' => $this->uuid($this->persona), 'MOV_Motivo' => 'Nueva oficina']);
        self::assertSame([true, $this->area, 'Ana Prueba'], [$i['ACT_Instalado'], $i['ARE_Area'], $i['responsable']]);
        self::assertSame('Instalado', $this->estado($impresora));
        self::assertSame([409, 'ASSET_ALREADY_INSTALLED_THERE'], $this->detalle('POST', '/api/v1/trazabilidad/instalar-area', ['ACT_Activo' => $impresora,
            'ARE_Area' => $this->area, 'PER_Responsable' => $this->uuid($this->persona), 'MOV_Motivo' => 'x']));
        $r = $this->ok(200, 'POST', '/api/v1/trazabilidad/retirar-area', ['ACT_Activo' => $impresora, 'MOV_Motivo' => 'A bodega']);
        self::assertSame([false, null], [$r['ACT_Instalado'], $r['PER_Responsable']]);
        self::assertSame('En Bodega', $this->estado($impresora));
    }

    public function testMantenimientoEstadosYCierreCondicional(): void
    {
        if (0 === D::contar('SELECT COUNT(*) FROM "INV_TIPO_MANTENIMIENTO"')) {
            D::conexion()->executeStatement("INSERT INTO \"INV_TIPO_MANTENIMIENTO\" (\"TMA_Nombre\") VALUES ('Correctivo')");
        }
        $tma = (int) D::conexion()->fetchOne('SELECT MIN("TMA_Tipo_Mantenimiento") FROM "INV_TIPO_MANTENIMIENTO"');
        $a = $this->activo();
        $this->asignar($a);
        $t = $this->ok(201, 'POST', '/api/v1/mantenimiento/', ['ACT_Activo' => $a, 'PER_Persona_Solicita' => $this->uuid($this->persona),
            'TMA_Tipo_Mantenimiento' => $tma, 'MAN_Descripcion_Falla' => 'No enciende', 'detalles' => [['DMA_Accion_Realizada' => 'Diagnóstico inicial', 'DMA_Costo_Item' => 50]]]);
        self::assertSame(['No enciende', '0.00', 'GTQ', '50.00'], [$t['MAN_Descripcion_Falla'], $t['MAN_Costo_Total'], $t['MAN_Moneda'], $t['detalles'][0]['DMA_Costo_Item']]);
        self::assertSame('En Reparación', $this->estado($a));
        self::assertSame([409, 'ASSET_ALREADY_HAS_OPEN_MAINTENANCE_TICKET'], $this->detalle('POST', '/api/v1/mantenimiento', ['ACT_Activo' => $a,
            'PER_Persona_Solicita' => $this->uuid($this->persona), 'TMA_Tipo_Mantenimiento' => $tma, 'MAN_Descripcion_Falla' => 'Otra']));
        [$e, $d] = $this->detalle('POST', '/api/v1/trazabilidad/transferencia', ['ACT_Activo' => $a,
            'PER_Persona_Destino' => $this->uuid($this->persona2), 'ARE_Area_Destino' => $this->area, 'MOV_Motivo' => 'x']);
        self::assertSame([400, 'INVALID_STATE_TRANSITION', 'En Reparación'], [$e, $d['error'], $d['current_state']], 'La máquina de estados va primero.');
        self::assertSame([422, 'MAINTENANCE_ACTION_REQUIRED'], $this->detalle('POST', "/api/v1/mantenimiento/{$t['MAN_Mantenimiento']}/detalles", ['DMA_Accion_Realizada' => 'x']));
        $det = $this->ok(201, 'POST', "/api/v1/mantenimiento/{$t['MAN_Mantenimiento']}/detalles", ['DMA_Accion_Realizada' => 'Cambio de fuente', 'DMA_Costo_Item' => 300]);
        $this->ok(200, 'PATCH', "/api/v1/mantenimiento/detalles/{$det['DMA_Detalle_Mant']}", ['DMA_Accion_Realizada' => 'Cambio de fuente 500W', 'DMA_Costo_Item' => 320]);

        self::assertSame([400, 'CLOSE_DATE_CANNOT_BE_IN_THE_FUTURE'], $this->detalle('PATCH', "/api/v1/mantenimiento/{$t['MAN_Mantenimiento']}/cerrar",
            ['MAN_Costo_Total' => 370, 'MAN_Fecha_Cierre' => '2099-01-01T00:00:00']));
        $c = $this->ok(200, 'PATCH', "/api/v1/mantenimiento/{$t['MAN_Mantenimiento']}/cerrar", ['MAN_Costo_Total' => 370, 'MAN_Moneda' => 'usd']);
        self::assertSame(['370.00', 'USD'], [$c['MAN_Costo_Total'], $c['MAN_Moneda']]);
        self::assertNotNull($c['MAN_Fecha_Cierre']);
        self::assertSame('Asignado', $this->estado($a), 'Con custodio vuelve a Asignado.');
        self::assertSame([400, 'TICKET_ALREADY_CLOSED'], $this->detalle('PATCH', "/api/v1/mantenimiento/{$t['MAN_Mantenimiento']}/cerrar", ['MAN_Costo_Total' => 1]));
        self::assertSame([400, 'CANNOT_ADD_DETAILS_TO_CLOSED_TICKET'], $this->detalle('POST', "/api/v1/mantenimiento/{$t['MAN_Mantenimiento']}/detalles",
            ['DMA_Accion_Realizada' => 'Otra acción']));
        $salida = D::conexion()->fetchAssociative("SELECT \"KDX_Costo\", \"KDX_Moneda\" FROM \"INV_KARDEX\" WHERE \"ACT_Activo\" = :a AND \"KDX_Tipo\" = 'MANTENIMIENTO_SALIDA'",
            ['a' => str_replace('-', '', $a)]);
        self::assertSame([370.0, 'USD'], [(float) $salida['KDX_Costo'], $salida['KDX_Moneda']]);
        self::assertContains($t['MAN_Mantenimiento'], array_column($this->ok(200, 'GET', '/api/v1/mantenimiento/'), 'MAN_Mantenimiento'));
    }

    public function testOffboardingAtomicoEIdempotente(): void
    {
        [$a1, $a2] = [$this->activo(), $this->activo()];
        $this->asignar($a1);
        $this->asignar($a2);
        $saliente = D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede]]);
        D::conexion()->executeStatement('UPDATE "INV_USUARIO" SET "PER_Persona" = :p WHERE "USU_Usuario" = :u', ['p' => $this->persona, 'u' => $saliente['id']]);
        $r = $this->ok(200, 'POST', '/api/v1/trazabilidad/persona/'.$this->uuid($this->persona).'/offboarding');
        self::assertSame([2, 2, true, true, false], [$r['movimientos_cerrados'], $r['activos_devueltos_a_bodega'], $r['usuario_desactivado'],
            $r['persona_inactivada'], $r['idempotent_noop']]);
        self::assertSame(['En Bodega', 'En Bodega'], [$this->estado($a1), $this->estado($a2)]);
        self::assertSame(0, D::contar('SELECT "USU_Estado" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $saliente['id']]));
        self::assertSame([], $this->ok(200, 'GET', '/api/v1/trazabilidad/persona/'.$this->uuid($this->persona).'/asignaciones'));
        // Un token anterior de la cuenta desactivada ya no sirve.
        self::assertSame([403, 'INACTIVE_USER'], $this->detalle('GET', '/api/v1/me', token: $this->tokenDe($saliente)));
        $otra = $this->ok(200, 'POST', '/api/v1/trazabilidad/persona/'.$this->uuid($this->persona).'/offboarding');
        // Una segunda salida no cierra nada (como en el backend anterior, vuelve a revocar la cuenta).
        self::assertSame([0, 0], [$otra['movimientos_cerrados'], $otra['activos_devueltos_a_bodega']]);

        $jefeTi = D::crearUsuario(['rol' => 'ADMIN_SEGURIDAD', 'sedes' => [$this->sede]]);
        $p = D::crearPersona($this->sede, 'Jefe');
        D::conexion()->executeStatement('UPDATE "INV_USUARIO" SET "PER_Persona" = :p WHERE "USU_Usuario" = :u', ['p' => $p, 'u' => $jefeTi['id']]);
        self::assertSame([403, 'OFFBOARDING_ACCOUNT_REQUIRES_IAM'], $this->detalle('POST', '/api/v1/trazabilidad/persona/'.$this->uuid($p).'/offboarding'));
    }

    public function testPurchasingReportShowsStockEntriesAndWhoHasEachAsset(): void
    {
        $a = $this->activo();
        $code = $this->ok(200, 'GET', "/api/v1/core/activos/{$a}")['ACT_Codigo_Interno'];
        $this->asignar($a);
        $purchasing = $this->tokenDe(D::crearUsuario(['rol' => 'COMPRAS', 'sedes' => [$this->sede]]));

        $stock = $this->ok(200, 'GET', '/api/v1/reports/stock?sede_id='.$this->sede, token: $purchasing);
        $row = array_values(array_filter($stock['assets'], fn (array $r): bool => $r['model_id'] === $this->modelo))[0];
        self::assertSame([1, 1, 0], [$row['total'], $row['assigned'], $row['available']]);
        self::assertNotNull($row['last_entry_at']);

        $entries = $this->ok(200, 'GET', '/api/v1/reports/entries?sede_id='.$this->sede, token: $purchasing);
        self::assertContains('ALTA', array_column($entries, 'type'));

        $assignments = $this->ok(200, 'GET', '/api/v1/reports/assignments?sede_id='.$this->sede, token: $purchasing);
        $assignment = array_values(array_filter($assignments, static fn (array $r): bool => $r['asset_code'] === $code))[0];
        self::assertStringContainsString('Ana', $assignment['person']);
        self::assertNotNull($assignment['assigned_at']);
        self::assertNull($assignment['returned_at']);

        $technician = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede]]));
        self::assertSame(403, $this->pedir('GET', '/api/v1/reports/stock', token: $technician)[0]);
        $otherSite = $this->tokenDe(D::crearUsuario(['rol' => 'COMPRAS', 'sedes' => [D::crearSede('Otra '.D::sufijo())]]));
        self::assertNotContains($code, array_column($this->ok(200, 'GET', '/api/v1/reports/assignments', token: $otherSite), 'asset_code'));
    }

    public function testEntregaYDevolucionEnLoteTodoONadaYRevertir(): void
    {
        [$a1, $a2, $a3] = [$this->activo(), $this->activo(), $this->activo()];
        $this->asignar($a2, $this->persona2);
        $e = $this->ok(201, 'POST', '/api/v1/trazabilidad/entrega-lote', ['PER_Persona' => $this->uuid($this->persona), 'ARE_Area' => $this->area,
            'activos' => [$a1, $a2], 'MOV_Motivo' => 'Nuevo puesto', 'ticket' => 'T-1']);
        self::assertSame([2, ['ASIGNACION', 'TRANSFERENCIA']], [$e['total'], array_column($e['items'], 'operacion')]);

        // Todo o nada: el segundo ya es de la persona, así que el primero tampoco se entrega.
        $codigo = $this->ok(200, 'GET', "/api/v1/core/activos/{$a1}")['ACT_Codigo_Interno'];
        self::assertSame([400, "BATCH_ITEM_FAILED:{$codigo}:ASSET_ALREADY_WITH_PERSON"], $this->detalle('POST', '/api/v1/trazabilidad/entrega-lote',
            ['PER_Persona' => $this->uuid($this->persona), 'ARE_Area' => $this->area, 'activos' => [$a3, $a1], 'MOV_Motivo' => 'x']));
        self::assertSame('En Bodega', $this->estado($a3), 'Nada se aplicó.');
        self::assertSame(422, $this->pedir('POST', '/api/v1/trazabilidad/entrega-lote', ['PER_Persona' => $this->uuid($this->persona), 'ARE_Area' => $this->area,
            'activos' => [$a3, $a3], 'MOV_Motivo' => 'x'])[0], 'DUPLICATED_ASSETS');

        // Deshacer: la asignación vuelve a bodega y la transferencia vuelve al custodio anterior.
        $rev = $this->ok(200, 'POST', '/api/v1/trazabilidad/movimientos/revertir', ['movimientos' => $e['movimientos']]);
        self::assertSame(['ASIGNACION_REVERTIDA', 'TRANSFERENCIA_REVERTIDA'], array_column($rev['items'], 'operacion'));
        self::assertSame('Beto', $this->ok(200, 'GET', "/api/v1/trazabilidad/activo/{$a2}/historial")[0]['persona']['PER_Primer_Nombre']);
        self::assertSame(409, $this->pedir('POST', '/api/v1/trazabilidad/movimientos/revertir', ['movimientos' => $e['movimientos']])[0]);

        $d = $this->ok(200, 'POST', '/api/v1/trazabilidad/devolucion-lote', ['activos' => [$a2], 'MOV_Motivo' => 'Fin de proyecto']);
        self::assertSame([1, 'En Bodega'], [$d['total'], $d['items'][0]['estado']]);
    }
}
