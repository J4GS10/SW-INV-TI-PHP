<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;

/** /core: alta, edición, búsqueda, especificaciones, componentes, baja con aprobación y alcance. */
final class ActivosTest extends PruebaApi
{
    private int $sede;
    private int $tipo;
    private int $modelo;

    protected function setUp(): void
    {
        parent::setUp();
        I::estados();
        I::motivosBaja();
        $this->sede = D::crearSede('Sede '.D::sufijo());
        $this->tipo = I::tipo('COMPUTO');
        $this->modelo = I::modelo($this->tipo);
        $this->token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function alta(array $extra = []): array
    {
        return $this->ok(201, 'POST', '/api/v1/core/activos', [
            'ACT_Serie_Fabricante' => 'SN-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-01-15', 'ACT_Costo' => 1500.5,
            'MOD_Modelo' => $this->modelo, 'TAC_Tipo_Activo' => $this->tipo, 'EOP_Estado_Operativo' => I::estado('En Bodega'), ...$extra]);
    }

    public function testAltaConCodigoCorrelativoKardexSelladoYReglas(): void
    {
        $a = $this->alta();
        self::assertMatchesRegularExpression('/^T[0-9A-F]{5}00001$/', $a['ACT_Codigo_Interno']);
        self::assertSame(['1500.50', 'GTQ', $this->sede, false], [$a['ACT_Costo'], $a['ACT_Moneda'], $a['SED_Sede'], $a['ACT_Instalado']]);
        $b = $this->alta(['ACT_Moneda' => 'usd']);
        self::assertSame([substr($a['ACT_Codigo_Interno'], 0, -1).'2', 'USD'], [$b['ACT_Codigo_Interno'], $b['ACT_Moneda']]);

        $kardex = D::conexion()->fetchAssociative("SELECT \"KDX_Tipo\", \"KDX_Hash\", \"KDX_Secuencia\", \"KDX_Costo\" FROM \"INV_KARDEX\" WHERE \"ACT_Activo\" = :a",
            ['a' => str_replace('-', '', $a['ACT_Activo'])]);
        self::assertSame('ALTA', $kardex['KDX_Tipo']);
        self::assertSame(64, \strlen((string) $kardex['KDX_Hash']), 'Oracle sella cada evento.');
        self::assertTrue(I::cadenaKardexIntegra());

        self::assertSame([400, 'SERIAL_NUMBER_ALREADY_EXISTS'], $this->detalle('POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => $a['ACT_Serie_Fabricante'],
            'ACT_Fecha_Compra' => '2026-01-15', 'MOD_Modelo' => $this->modelo, 'TAC_Tipo_Activo' => $this->tipo, 'EOP_Estado_Operativo' => I::estado('En Bodega')]));
        self::assertSame([400, 'INITIAL_STATE_MUST_BE_AVAILABLE_OR_STORAGE'], $this->detalle('POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'SN-'.D::sufijo(),
            'ACT_Fecha_Compra' => '2026-01-15', 'MOD_Modelo' => $this->modelo, 'TAC_Tipo_Activo' => $this->tipo, 'EOP_Estado_Operativo' => I::estado('Baja')]));
        self::assertSame([400, 'WARRANTY_DATE_CANNOT_BE_BEFORE_PURCHASE_DATE'], $this->detalle('POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'SN-'.D::sufijo(),
            'ACT_Fecha_Compra' => '2026-01-15', 'ACT_Fin_Garantia' => '2025-01-01', 'MOD_Modelo' => $this->modelo, 'TAC_Tipo_Activo' => $this->tipo,
            'EOP_Estado_Operativo' => I::estado('En Bodega')]));
        self::assertSame([422, 'ASSET_TYPE_IS_SUPPLY'], $this->detalle('POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'SN-'.D::sufijo(),
            'ACT_Fecha_Compra' => '2026-01-15', 'MOD_Modelo' => $this->modelo, 'TAC_Tipo_Activo' => I::tipo('INSUMO'), 'EOP_Estado_Operativo' => I::estado('En Bodega')]));
        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'SN-'.D::sufijo(),
            'ACT_Fecha_Compra' => '2026-01-15', 'ACT_Costo' => 1.234, 'MOD_Modelo' => $this->modelo, 'TAC_Tipo_Activo' => $this->tipo,
            'EOP_Estado_Operativo' => I::estado('En Bodega')]), 'Más de 2 decimales.');
        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'SN-'.D::sufijo(),
            'ACT_Fecha_Compra' => '2026-01-15', 'ACT_Moneda' => 'EUR', 'MOD_Modelo' => $this->modelo, 'TAC_Tipo_Activo' => $this->tipo,
            'EOP_Estado_Operativo' => I::estado('En Bodega')]));
    }

    public function testEdicionConReglasDeEstadoSedeYAlcance(): void
    {
        $a = $this->alta();
        $id = $a['ACT_Activo'];
        $host = 'PC-'.D::sufijo();
        $editado = $this->ok(200, 'PATCH', "/api/v1/core/activos/{$id}", ['EOP_Estado_Operativo' => I::estado('En Bodega'), 'ACT_Hostname' => $host]);
        self::assertSame([I::estado('En Bodega'), $host], [$editado['EOP_Estado_Operativo'], $editado['ACT_Hostname']]);
        self::assertSame(0, D::contar("SELECT COUNT(*) FROM \"INV_KARDEX\" WHERE \"ACT_Activo\" = :a AND \"KDX_Tipo\" = 'CAMBIO_ESTADO'", ['a' => str_replace('-', '', $id)]));
        self::assertSame([400, 'STATE_CHANGE_REQUIRES_OPERATION'], $this->detalle('PATCH', "/api/v1/core/activos/{$id}", ['EOP_Estado_Operativo' => I::estado('Asignado')]));
        self::assertSame([400, 'SEDE_REQUIRED'], $this->detalle('PATCH', "/api/v1/core/activos/{$id}", ['SED_Sede' => null]));
        $otra = D::crearSede('Otra '.D::sufijo());
        self::assertSame([403, 'SEDE_OUT_OF_SCOPE'], $this->detalle('PATCH', "/api/v1/core/activos/{$id}", ['SED_Sede' => $otra]));
        self::assertSame([400, 'PURCHASE_DATE_REQUIRED'], $this->detalle('PATCH', "/api/v1/core/activos/{$id}", ['ACT_Fecha_Compra' => null]));

        // Caja negra del alcance: otra sede no ve el activo (capa 1 + VPD).
        $ajeno = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$otra]]));
        self::assertSame([404, 'ASSET_NOT_FOUND'], $this->detalle('GET', "/api/v1/core/activos/{$id}", token: $ajeno));
        self::assertSame([404, 'ASSET_NOT_FOUND'], $this->detalle('PATCH', "/api/v1/core/activos/{$id}", ['ACT_Hostname' => 'x'], $ajeno));
        self::assertSame([409, 'USE_DECOMMISSION_REQUEST'], $this->detalle('DELETE', "/api/v1/core/activos/{$id}"));
        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('GET', '/api/v1/core/activos/no-es-uuid'));
    }

    public function testBusquedaPaginadaDetalleYCodigo(): void
    {
        $marca = 'BUSQ'.strtoupper(D::sufijo());
        $a = $this->alta(['ACT_Hostname' => "{$marca}-%_1"]);
        $this->alta(['ACT_Hostname' => "{$marca}-2"]);
        $r = $this->ok(200, 'POST', '/api/v1/core/activos/search', ['q' => $marca, 'per_page' => 1]);
        self::assertSame([2, 1, 1], [$r['total'], $r['page'], \count($r['items'])]);
        self::assertSame($this->modelo, $r['items'][0]['modelo']['MOD_Modelo']);
        $literal = $this->ok(200, 'POST', '/api/v1/core/activos/search', ['q' => '%_']);
        self::assertContains($a['ACT_Activo'], array_column($literal['items'], 'ACT_Activo'), 'Los comodines del usuario son literales.');
        self::assertSame(1, $this->ok(200, 'POST', '/api/v1/core/activos/search', ['q' => '%_'])['total'] >= 1 ? 1 : 0);
        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('POST', '/api/v1/core/activos/search', ['per_page' => 500]));
        $hosts = fn (array $orden): array => array_column($this->ok(200, 'POST', '/api/v1/core/activos/search', ['q' => $marca, ...$orden])['items'], 'ACT_Hostname');
        self::assertSame(["{$marca}-%_1", "{$marca}-2"], $hosts(['sort' => 'host']), 'Orden ascendente por columna.');
        self::assertSame(["{$marca}-2", "{$marca}-%_1"], $hosts(['sort' => 'host', 'sort_dir' => 'desc']), 'Orden descendente por columna.');
        self::assertCount(2, $hosts(['sort' => 'ACT_Costo; DROP', 'sort_dir' => 'x']), 'Una columna fuera de la lista cerrada se ignora, nunca llega al SQL.');
        $porCodigo = $this->ok(200, 'GET', '/api/v1/core/activos/by-code/'.rawurlencode(' '.$a['ACT_Codigo_Interno'].' '));
        self::assertSame([$a['ACT_Activo'], 'En Bodega'], [$porCodigo['ACT_Activo'], $porCodigo['estado_operativo']['EOP_Nombre']]);
        self::assertNotEmpty($this->ok(200, 'GET', '/api/v1/core/activos?limit=5'));
    }

    public function testEspecificacionesYComponentes(): void
    {
        $tes = (int) D::conexion()->fetchOne('SELECT MIN("TES_Tipo_Especificacion") FROM "INV_TIPO_ESPECIFICACION"') ?: $this->tipoEspecificacion();
        $equipo = $this->alta(['especificaciones' => [['TES_Tipo_Especificacion' => $tes, 'ESP_Valor' => '16 GB']]]);
        self::assertSame('16 GB', $equipo['especificaciones'][0]['ESP_Valor']);
        $id = $equipo['ACT_Activo'];
        $esp = $this->ok(200, 'GET', "/api/v1/core/activos/{$id}/especificaciones")[0];
        $this->ok(204, 'PATCH', "/api/v1/core/especificaciones/{$esp['ESP_Especificacion']}", ['ESP_Valor' => '32 GB', 'motivo' => 'Ampliación']);
        self::assertSame('32 GB', $this->ok(200, 'GET', "/api/v1/core/activos/{$id}/especificaciones")[0]['ESP_Valor']);

        $tipoRam = I::tipo('COMPONENTE');
        $ram = $this->ok(201, 'POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'RAM-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-01-15',
            'MOD_Modelo' => I::modelo($tipoRam), 'TAC_Tipo_Activo' => $tipoRam, 'EOP_Estado_Operativo' => I::estado('En Bodega')]);
        $c = $this->ok(200, 'POST', "/api/v1/core/activos/{$id}/componentes", ['ACT_Componente' => $ram['ACT_Activo'], 'motivo' => 'Ampliación']);
        self::assertSame([$ram['ACT_Activo']], array_column($c['actuales'], 'ACT_Activo'));
        self::assertSame('COMPONENTE_INSTALADO', $c['historial'][0]['KDX_Tipo']);
        self::assertSame([409, 'COMPONENT_ALREADY_INSTALLED'], $this->detalle('POST', "/api/v1/core/activos/{$id}/componentes", ['ACT_Componente' => $ram['ACT_Activo']]));
        self::assertSame([400, 'COMPONENT_CANNOT_BE_ITSELF'], $this->detalle('POST', "/api/v1/core/activos/{$id}/componentes", ['ACT_Componente' => $id]));
        $r = $this->ok(200, 'POST', "/api/v1/core/activos/{$id}/componentes/{$ram['ACT_Activo']}/retirar", []);
        self::assertSame([[], 'COMPONENTE_RETIRADO'], [$r['actuales'], $r['historial'][0]['KDX_Tipo']]);
        self::assertSame([400, 'COMPONENT_NOT_INSTALLED_IN_ASSET'], $this->detalle('POST', "/api/v1/core/activos/{$id}/componentes/{$ram['ACT_Activo']}/retirar", []));
    }

    private function tipoEspecificacion(): int
    {
        D::conexion()->executeStatement('INSERT INTO "INV_TIPO_ESPECIFICACION" ("TES_Nombre") VALUES (:n)', ['n' => 'RAM '.D::sufijo()]);

        return (int) D::conexion()->fetchOne('SELECT MAX("TES_Tipo_Especificacion") FROM "INV_TIPO_ESPECIFICACION"');
    }

    public function testBajaPorAprobacionConSeparacionDeFunciones(): void
    {
        $a = $this->alta();
        $id = $a['ACT_Activo'];
        self::assertSame([422, 'DECOMMISSION_REASON_NOT_FOUND'], $this->detalle('POST', "/api/v1/core/activos/{$id}/baja", ['motivo' => 'NO_EXISTE', 'destino' => 'RECICLAJE']));
        self::assertSame([422, 'OBSERVATION_REQUIRED_FOR_OTHER'], $this->detalle('POST', "/api/v1/core/activos/{$id}/baja", ['motivo' => 'OTRO', 'destino' => 'RECICLAJE']));
        $solicitud = $this->ok(202, 'POST', "/api/v1/core/activos/{$id}/baja", ['motivo' => 'OBSOLESCENCIA', 'destino' => 'RECICLAJE', 'documento' => 'ACTA-1']);
        self::assertSame(['BAJA', 'PENDIENTE'], [$solicitud['APR_Tipo'], $solicitud['APR_Estado']]);
        self::assertSame(409, $this->pedir('POST', "/api/v1/core/activos/{$id}/baja", ['motivo' => 'OBSOLESCENCIA', 'destino' => 'RECICLAJE'])[0]);

        $jefe = D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]);
        $tokenJefe = $this->tokenDe($jefe);
        // Nadie aprueba lo propio.
        self::assertSame([403, 'CANNOT_APPROVE_OWN_REQUEST'], $this->detalle('POST', "/api/v1/aprobaciones/{$solicitud['APR_Aprobacion']}/aprobar", []));
        $lista = $this->ok(200, 'GET', '/api/v1/aprobaciones?estado=PENDIENTE');
        self::assertTrue(array_column($lista, 'es_mia', 'APR_Aprobacion')[$solicitud['APR_Aprobacion']]);
        self::assertGreaterThanOrEqual(1, $this->ok(200, 'GET', '/api/v1/aprobaciones/pendientes', token: $tokenJefe)['total']);

        $aprobada = $this->ok(200, 'POST', "/api/v1/aprobaciones/{$solicitud['APR_Aprobacion']}/aprobar", ['comentario' => 'Visto'], $tokenJefe);
        self::assertSame('APROBADA', $aprobada['APR_Estado']);
        $final = $this->ok(200, 'GET', "/api/v1/core/activos/{$id}");
        self::assertSame(['Baja', 'OBSOLESCENCIA', 'RECICLAJE', 'ACTA-1'], [$final['estado_operativo']['EOP_Nombre'], $final['ACT_Baja_Motivo'],
            $final['ACT_Baja_Destino'], $final['ACT_Baja_Documento']]);
        $baja = D::conexion()->fetchAssociative("SELECT \"KDX_Detalle\" FROM \"INV_KARDEX\" WHERE \"ACT_Activo\" = :a AND \"KDX_Tipo\" = 'BAJA'",
            ['a' => str_replace('-', '', $id)]);
        $detalle = json_decode((string) $baja['KDX_Detalle'], true);
        self::assertSame([$solicitud['APR_Aprobacion'], 'OBSOLESCENCIA'], [$detalle['aprobacion'], $detalle['motivo']]);
        self::assertSame([409, 'APPROVAL_ALREADY_RESOLVED:APROBADA'], $this->detalle('POST', "/api/v1/aprobaciones/{$solicitud['APR_Aprobacion']}/rechazar",
            ['comentario' => 'Tarde ya'], $tokenJefe));
        self::assertSame(1, D::contar("SELECT COUNT(*) FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Plantilla\" = 'baja' AND \"EOB_Creado_En\" > SYSTIMESTAMP - INTERVAL '1' MINUTE") > 0 ? 1 : 0,
            'El aviso de baja se encola tras el commit.');
    }

    public function testRechazoYAnulacion(): void
    {
        $a = $this->alta();
        $s = $this->ok(202, 'POST', "/api/v1/core/activos/{$a['ACT_Activo']}/baja", ['motivo' => 'OBSOLESCENCIA', 'destino' => 'RECICLAJE']);
        $jefe = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
        self::assertSame(422, $this->pedir('POST', "/api/v1/aprobaciones/{$s['APR_Aprobacion']}/rechazar", ['comentario' => 'no'], $jefe)[0]);
        self::assertSame('RECHAZADA', $this->ok(200, 'POST', "/api/v1/aprobaciones/{$s['APR_Aprobacion']}/rechazar", ['comentario' => 'Aún sirve'], $jefe)['APR_Estado']);
        self::assertSame('En Bodega', $this->ok(200, 'GET', "/api/v1/core/activos/{$a['ACT_Activo']}")['estado_operativo']['EOP_Nombre']);

        $s2 = $this->ok(202, 'POST', "/api/v1/core/activos/{$a['ACT_Activo']}/baja", ['motivo' => 'OBSOLESCENCIA', 'destino' => 'RECICLAJE']);
        self::assertSame([403, 'ONLY_REQUESTER_CAN_CANCEL'], $this->detalle('POST', "/api/v1/aprobaciones/{$s2['APR_Aprobacion']}/anular", [],
            $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]))));
        self::assertSame('ANULADA', $this->ok(200, 'POST', "/api/v1/aprobaciones/{$s2['APR_Aprobacion']}/anular", [])['APR_Estado']);
    }

    public function testCostosOcultosALosRolesSinValoresYEdicionEnLote(): void
    {
        $a = $this->alta();
        $tecnico = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede]]));
        $visto = $this->ok(200, 'GET', "/api/v1/core/activos/{$a['ACT_Activo']}", token: $tecnico);
        self::assertArrayNotHasKey('ACT_Costo', $visto);
        $this->ok(200, 'PATCH', "/api/v1/core/activos/{$a['ACT_Activo']}", ['ACT_Costo' => 1, 'ACT_Hostname' => 'T1-'.D::sufijo()], $tecnico);
        self::assertSame('1500.50', $this->ok(200, 'GET', "/api/v1/core/activos/{$a['ACT_Activo']}")['ACT_Costo'], 'El técnico no puede borrar ni cambiar el costo.');

        $b = $this->alta();
        $area = I::area($this->sede);
        $lote = $this->ok(200, 'PATCH', '/api/v1/core/activos/lote', ['activos' => [$a['ACT_Activo'], $b['ACT_Activo']],
            'cambios' => ['ARE_Area_Ubicacion' => $area, 'ACT_Factura' => 'F-77']]);
        self::assertSame(2, $lote['total']);
        self::assertEqualsCanonicalizing(['ACT_Factura' => null, 'ARE_Area_Ubicacion' => null], $lote['anteriores'][0]['cambios']);
        $this->ok(200, 'POST', '/api/v1/core/activos/lote/restaurar', ['items' => array_map(static fn (array $x): array => ['ACT_Activo' => $x['ACT_Activo'],
            'cambios' => $x['cambios']], $lote['anteriores'])]);
        self::assertNull($this->ok(200, 'GET', "/api/v1/core/activos/{$b['ACT_Activo']}")['ACT_Factura']);
        $otraSede = I::area(D::crearSede('X '.D::sufijo()));
        [$e, $d] = $this->detalle('PATCH', '/api/v1/core/activos/lote', ['activos' => [$a['ACT_Activo']], 'cambios' => ['ARE_Area_Ubicacion' => $otraSede]]);
        self::assertSame([400, "BATCH_ITEM_FAILED:{$a['ACT_Codigo_Interno']}:ASSET_AREA_OTHER_SITE"], [$e, $d]);
    }
}
