<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 4, caja negra: exportaciones CSV (/export y /gov), archivos del inventario
 * (kardex, custodia, licencias, toma, hoja de vida, cierre mensual), etiquetas QR
 * y actas en Word y PDF. Contrato: tipo, nombre del archivo, contenido, roles,
 * alcance por sede y códigos de error.
 */
final class ArchivosTest extends PruebaApi
{
    private int $sede;
    private int $area;
    private int $tipo;
    private int $modelo;
    private string $persona;

    protected function setUp(): void
    {
        parent::setUp();
        I::estados();
        foreach (['Asignación', 'Devolución'] as $t) {
            if (0 === D::contar('SELECT COUNT(*) FROM "INV_TIPO_MOVIMIENTO" WHERE "TMO_Nombre" = :n', ['n' => $t])) {
                D::conexion()->executeStatement('INSERT INTO "INV_TIPO_MOVIMIENTO" ("TMO_Nombre") VALUES (:n)', ['n' => $t]);
            }
        }
        $this->sede = D::crearSede('Sede F '.D::sufijo());
        $this->area = I::area($this->sede);
        $this->tipo = I::tipo('COMPUTO', 'F'.substr(D::sufijo(), -4));
        $this->modelo = I::modelo($this->tipo);
        $this->persona = D::crearPersona($this->sede, 'Ñandú & Cía');
        $this->token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
    }

    private function uuid(string $hex): string
    {
        return strtolower(substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20));
    }

    /** @return array<string, mixed> */
    private function activo(string $serie = ''): array
    {
        return $this->ok(201, 'POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => '' !== $serie ? $serie : 'FA-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-10-02',
            'ACT_Costo' => 1234.5, 'ACT_Hostname' => 'PC-'.substr(D::sufijo(), -5), 'MOD_Modelo' => $this->modelo, 'TAC_Tipo_Activo' => $this->tipo,
            'EOP_Estado_Operativo' => I::estado('En Bodega'), 'SED_Sede' => $this->sede]);
    }

    /** Asigna el activo a la persona y devuelve el id del movimiento. */
    private function asignar(string $activo, ?string $persona = null, string $motivo = 'Entrega <inicial> & más'): string
    {
        return $this->ok(201, 'POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $activo, 'PER_Persona' => $this->uuid($persona ?? $this->persona),
            'ARE_Area' => $this->area, 'TMO_Tipo_Movimiento' => (int) D::conexion()->fetchOne("SELECT MIN(\"TMO_Tipo_Movimiento\") FROM \"INV_TIPO_MOVIMIENTO\" WHERE \"TMO_Nombre\" = 'Asignación'"),
            'MOV_Motivo' => 'Entrega', 'MOV_Observacion' => $motivo])['MOV_Movimiento'];
    }

    /** @return list<list<string>> filas del CSV (sin BOM) */
    private static function csv(Response $r): array
    {
        $contenido = (string) $r->getContent();
        self::assertStringStartsWith("\u{FEFF}", $contenido, 'BOM para que Excel detecte UTF-8.');
        $filas = [];
        $flujo = fopen('php://memory', 'r+b');
        fwrite($flujo, substr($contenido, 3));
        rewind($flujo);
        while (false !== ($f = fgetcsv($flujo, escape: ''))) {
            $filas[] = $f;
        }
        fclose($flujo);

        return $filas;
    }

    private static function adjuntoComo(Response $r, string $tipo, string $patronNombre): void
    {
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        self::assertStringStartsWith($tipo, (string) $r->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename="'.$patronNombre.'"$/', (string) $r->headers->get('Content-Disposition'));
    }

    // ================= /export =================

    public function testExportesConEncabezadosPorIdiomaComillasYRolesPropios(): void
    {
        $formula = '=HYPERLINK("http://malo/'.D::sufijo().'")';
        $a = $this->activo($formula);
        $r = $this->archivo('GET', '/api/v1/export/activos.csv?lang=en');
        self::adjuntoComo($r, 'text/csv; charset=utf-8', 'assets_\d{8}_\d{6}\.csv');
        self::assertStringContainsString("\"Internal code\",\"Serial number\"", (string) $r->getContent(), 'Todas las celdas entre comillas.');
        $fila = array_values(array_filter(self::csv($r), static fn (array $f): bool => $f[0] === $a['ACT_Codigo_Interno']))[0];
        self::assertSame(["'".$formula, 'In Storage', '2026-10-02', '1234.50', 'GTQ'], [$fila[1], $fila[6], $fila[7], $fila[9], $fila[10]],
            'Fórmula neutralizada, estado traducido, fecha ISO y monto con 2 decimales.');

        $otraSede = D::crearSede('Ajena '.D::sufijo());
        $ajeno = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$otraSede]]));
        $codigos = array_column(self::csv($this->archivo('GET', '/api/v1/export/activos.csv', token: $ajeno)), 0);
        self::assertNotContains($a['ACT_Codigo_Interno'], $codigos, 'Alcance por sede en el exporte.');

        $tecnico = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede]]));
        self::assertSame(403, $this->archivo('GET', '/api/v1/export/activos.csv', token: $tecnico)->getStatusCode(), 'Valores: solo finanzas.');
        $compras = $this->tokenDe(D::crearUsuario(['rol' => 'COMPRAS', 'sedes' => [$this->sede]]));
        self::assertSame(200, $this->archivo('GET', '/api/v1/export/proveedores.csv', token: $compras)->getStatusCode());
        self::assertSame(200, $this->archivo('GET', '/api/v1/export/ordenes.csv?lang=it', token: $compras)->getStatusCode());
        self::assertSame(403, $this->archivo('GET', '/api/v1/export/movimientos.csv', token: $compras)->getStatusCode());
        self::assertSame(422, $this->archivo('GET', '/api/v1/export/consumibles.csv?lang=fr')->getStatusCode());
        self::assertSame(422, $this->archivo('GET', '/api/v1/export/movimientos.csv?limit=0')->getStatusCode());
        self::assertSame(401, $this->archivo('GET', '/api/v1/export/activos.csv', token: 'x')->getStatusCode());
    }

    public function testExporteDeMovimientosYConsumibles(): void
    {
        $a = $this->activo();
        $this->asignar($a['ACT_Activo']);
        $filas = self::csv($this->archivo('GET', '/api/v1/export/movimientos.csv?limit=50&lang=es'));
        self::assertSame(['Fecha de asignación', 'Fecha de devolución', 'Código del activo'], \array_slice($filas[0], 0, 3));
        $mio = array_values(array_filter($filas, static fn (array $f): bool => $f[2] === $a['ACT_Codigo_Interno']))[0];
        self::assertSame(['', 'Ñandú & Cía Prueba', 'Asignación', 'Entrega <inicial> & más'], [$mio[1], $mio[4], $mio[7], $mio[8]]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $mio[0], 'Hora local sin «T» ni microsegundos.');

        D::conexion()->executeStatement('INSERT INTO "INV_CONSUMIBLE" ("CON_Nombre", "CON_Unidad", "CON_Stock_Actual", "CON_Stock_Minimo", "CON_Activo", "SED_Sede") '
            ."VALUES (:n, 'caja', 2, 5, 1, :s)", ['n' => 'Tóner '.D::sufijo(), 's' => $this->sede]);
        $cons = self::csv($this->archivo('GET', '/api/v1/export/consumibles.csv?lang=en'));
        $toner = array_values(array_filter($cons, static fn (array $f): bool => str_starts_with($f[0], 'Tóner')))[0];
        self::assertSame(['box', '2', '5', 'Yes', 'Yes'], [$toner[2], $toner[3], $toner[4], $toner[5], $toner[6]], 'Unidad traducida y bajo el mínimo.');
    }

    public function testBitacoraEnCsvSeRegistraAsiMismaYSePublicaEnGov(): void
    {
        $seguridad = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_SEGURIDAD', 'global' => true]));
        $antes = D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'AUDIT_EXPORT'");
        $r = $this->archivo('GET', '/api/v1/gov/auditoria.csv?limit=5&accion=AUDIT_EXPORT&lang=en', token: $seguridad);
        self::adjuntoComo($r, 'text/csv', 'audit_log_\d{8}_\d{6}\.csv');
        self::assertSame(['Date and time', 'Action', 'Affected entity', 'Source IP', 'User agent', 'User ID', 'Details (JSON)'], self::csv($r)[0]);
        self::assertSame($antes + 1, D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'AUDIT_EXPORT'"));
        $detalle = json_decode((string) D::conexion()->fetchOne("SELECT \"AUD_Snapshot_JSON\" FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'AUDIT_EXPORT' "
            .'ORDER BY "AUD_Fecha_Hora" DESC FETCH FIRST 1 ROWS ONLY'), true);
        self::assertSame(['idioma' => 'en', 'accion' => 'AUDIT_EXPORT'], ['idioma' => $detalle['idioma'], 'accion' => $detalle['accion']]);

        $segunda = self::csv($this->archivo('GET', '/api/v1/export/auditoria.csv?limit=1&accion=AUDIT_EXPORT', token: $seguridad));
        self::assertSame('Exportación de la bitácora', $segunda[1][1], 'Acción traducida.');
        self::assertStringStartsWith('{"registros": ', $segunda[1][6], 'JSON con el formato del backend anterior.');
        self::assertSame(403, $this->archivo('GET', '/api/v1/gov/auditoria.csv')->getStatusCode(), 'ADMIN_TI no lee la bitácora.');
        self::assertSame(422, $this->archivo('GET', '/api/v1/gov/auditoria.csv?limit=50001', token: $seguridad)->getStatusCode());
    }

    // ================= /inventario =================

    public function testKardexYCustodiaEnCsv(): void
    {
        $a = $this->activo();
        $this->asignar($a['ACT_Activo']);
        $r = $this->archivo('GET', "/api/v1/inventario/kardex.csv?activo_id={$a['ACT_Activo']}&lang=it");
        self::adjuntoComo($r, 'text/csv', 'registro_inventario_\d{8}\.csv');
        $filas = self::csv($r);
        self::assertSame(['Data e ora', 'Evento', 'Oggetto'], \array_slice($filas[0], 0, 3));
        self::assertSame([['Registrazione', 'Asset'], ['Assegnazione', 'Asset']], [\array_slice($filas[1], 1, 2), \array_slice($filas[2], 1, 2)], 'Orden cronológico.');
        self::assertSame(['Ñandú & Cía Prueba', '1234.50', 'No'], [$filas[2][7], $filas[1][13], $filas[1][18]]);
        self::assertSame(422, $this->archivo('GET', '/api/v1/inventario/kardex.csv?objeto=OTRO')->getStatusCode());
        $tecnico = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede]]));
        self::assertSame(403, $this->archivo('GET', '/api/v1/inventario/kardex.csv', token: $tecnico)->getStatusCode());

        $c = $this->archivo('GET', "/api/v1/inventario/custodia.csv?sede_id={$this->sede}&fecha=2099-12-31");
        self::adjuntoComo($c, 'text/csv', 'custodia_20991231\.csv');
        $mia = array_values(array_filter(self::csv($c), static fn (array $f): bool => $f[0] === $a['ACT_Codigo_Interno']))[0];
        self::assertSame(['Ñandú & Cía Prueba', 'Asignación'], [$mia[3], $mia[7]]);
    }

    public function testLicenciasYReporteDeTomaEnCsv(): void
    {
        D::conexion()->executeStatement('INSERT INTO "INV_LICENCIA" ("SOF_Software", "TLI_Tipo_Licencia", "LIC_Cantidad_Total", "LIC_Cantidad_Usada", "LIC_Fecha_Vencimiento", '
            ."\"LIC_Costo\", \"LIC_Moneda\") VALUES (:s, :t, 8, 1, DATE '2099-01-01', 99.995, 'USD')", ['s' => I::software(), 't' => I::tipoLicencia()]);
        $filas = self::csv($this->archivo('GET', '/api/v1/inventario/licencias.csv?lang=en'));
        self::assertSame('Usage (%)', $filas[0][7]);
        $lic = array_values(array_filter($filas, static fn (array $f): bool => '2099-01-01' === $f[8]))[0];
        self::assertSame(['8', '1', '7', '12', 'USD'], [$lic[4], $lic[5], $lic[6], $lic[7], $lic[11]], '12,5 % redondeado al par (como Python).');
        self::assertGreaterThan(26000, (int) $lic[9], 'Días para vencer.');

        $a = $this->activo();
        $toma = $this->ok(201, 'POST', '/api/v1/inventario/tomas', ['SED_Sede' => $this->sede]);
        $this->ok(201, 'POST', "/api/v1/inventario/tomas/{$toma['TOM_Toma']}/lecturas", ['codigo' => 'NO-EXISTE-'.D::sufijo()]);
        $r = $this->archivo('GET', "/api/v1/inventario/tomas/{$toma['TOM_Toma']}/reporte.csv?lang=en");
        self::adjuntoComo($r, 'text/csv', "inventory_count_{$toma['TOM_Toma']}\\.csv");
        $resultados = self::csv($r);
        self::assertSame(['Result', 'Code', 'Area'], $resultados[0]);
        self::assertContains(['MISSING', $a['ACT_Codigo_Interno'], ''], $resultados);
        self::assertContains('NOT REGISTERED', array_column($resultados, 0));
        self::assertSame(404, $this->archivo('GET', '/api/v1/inventario/tomas/999999999/reporte.csv')->getStatusCode());
    }

    public function testHojaDeVidaEnPdfOcultaCostosATecnicos(): void
    {
        $a = $this->activo();
        $this->asignar($a['ACT_Activo']);
        $r = $this->archivo('GET', "/api/v1/inventario/activos/{$a['ACT_Activo']}/hoja-de-vida.pdf");
        self::adjuntoComo($r, 'application/pdf', 'Hoja_de_vida_'.preg_quote($a['ACT_Codigo_Interno'], '/').'\.pdf');
        self::assertStringStartsWith('%PDF-', (string) $r->getContent());
        $tecnico = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede]]));
        $en = $this->archivo('GET', "/api/v1/inventario/activos/{$a['ACT_Activo']}/hoja-de-vida.pdf?lang=en", token: $tecnico);
        self::adjuntoComo($en, 'application/pdf', 'Asset_history_.*\.pdf');
        self::assertSame(404, $this->archivo('GET', '/api/v1/inventario/activos/00000000-0000-0000-0000-000000000000/hoja-de-vida.pdf')->getStatusCode());
        self::assertSame(422, $this->archivo('GET', '/api/v1/inventario/activos/no-es-uuid/hoja-de-vida.pdf')->getStatusCode());
        $ajeno = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [D::crearSede('Ajena '.D::sufijo())]]));
        self::assertSame(404, $this->archivo('GET', "/api/v1/inventario/activos/{$a['ACT_Activo']}/hoja-de-vida.pdf", token: $ajeno)->getStatusCode());
    }

    public function testCierreMensualEnExcelConHojasYValoresNumericos(): void
    {
        $a = $this->activo();
        $r = $this->archivo('GET', "/api/v1/inventario/cierre-mensual.xlsx?anio=2026&mes=10&sede_id={$this->sede}&lang=en");
        self::adjuntoComo($r, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'month_end_202610\.xlsx');
        $temporal = (string) tempnam(sys_get_temp_dir(), 'cie');
        file_put_contents($temporal, (string) $r->getContent());
        $libro = (new Xlsx())->load($temporal);
        unlink($temporal);
        self::assertSame(['Assets', 'Additions', 'Disposals', 'Consumables', 'Purchases received', 'Active licenses', 'Maintenance'], $libro->getSheetNames());
        $altas = $libro->getSheetByName('Additions');
        self::assertSame(['Code', 'Serial'], [$altas->getCell('A1')->getValue(), $altas->getCell('B1')->getValue()]);
        $fila = null;
        foreach ($altas->getRowIterator(2) as $f) {
            if ($altas->getCell('A'.$f->getRowIndex())->getValue() === $a['ACT_Codigo_Interno']) {
                $fila = $f->getRowIndex();
            }
        }
        self::assertNotNull($fila);
        self::assertSame([DataType::TYPE_NUMERIC, 1234.5], [$altas->getCell("I{$fila}")->getDataType(), $altas->getCell("I{$fila}")->getValue()], 'Costo numérico.');
        self::assertSame('yyyy-mm-dd', $altas->getStyle("F{$fila}")->getNumberFormat()->getFormatCode(), 'Fecha de compra como fecha de Excel.');
        self::assertSame(422, $this->archivo('GET', '/api/v1/inventario/cierre-mensual.xlsx?anio=2026')->getStatusCode());
    }

    // ================= Etiquetas =================

    public function testEtiquetasQrPorIdYPorCodigo(): void
    {
        $a = $this->activo();
        $b = $this->activo();
        $r = $this->archivo('POST', '/api/v1/core/activos/etiquetas.pdf?lang=en', ['activos_ids' => [$a['ACT_Activo']], 'codigos' => [' '.$b['ACT_Codigo_Interno'].' ']]);
        self::adjuntoComo($r, 'application/pdf', 'asset-labels\.pdf');
        self::assertStringStartsWith('%PDF-', (string) $r->getContent());
        self::assertSame([422, 'VALIDATION_ERROR'], [$this->archivo('POST', '/api/v1/core/activos/etiquetas.pdf', ['activos_ids' => null])->getStatusCode(),
            json_decode((string) $this->cliente->getResponse()->getContent(), true)['detail']]);
        self::assertStringContainsString('LABEL_ASSETS_REQUIRED', (string) $this->cliente->getResponse()->getContent());
        self::assertSame([404, 'ASSETS_NOT_FOUND'], $this->detalle('POST', '/api/v1/core/activos/etiquetas.pdf', ['codigos' => ['NO-EXISTE-'.D::sufijo()]]));
        self::assertSame([400, 'LABEL_ASSETS_REQUIRED'], $this->detalle('POST', '/api/v1/core/activos/etiquetas.pdf', ['codigos' => ['  ']]));
        $muchos = array_map(static fn (int $i): string => "X{$i}", range(1, 201));
        self::assertStringContainsString('TOO_MANY_LABELS_REQUESTED', (string) $this->archivo('POST', '/api/v1/core/activos/etiquetas.pdf', ['codigos' => $muchos])->getContent());
        $consulta = $this->tokenDe(D::crearUsuario(['rol' => 'CONSULTA', 'sedes' => [$this->sede]]));
        self::assertSame(403, $this->archivo('POST', '/api/v1/core/activos/etiquetas.pdf', ['codigos' => ['X']], $consulta)->getStatusCode());
    }

    // ================= Actas =================

    public function testActaDeEntregaEnWordYPdfConDatosEscapados(): void
    {
        // MERGE: en una base recién migrada la fila de configuración aún no existe.
        D::conexion()->executeStatement('MERGE INTO "SYS_CONFIGURACION" c USING (SELECT 1 AS id FROM DUAL) d ON (c."SYS_Configuracion" = d.id) '
            ."WHEN MATCHED THEN UPDATE SET \"SYS_Nombre_Empresa\" = 'Lombardi & Asociados', \"SYS_Logo_URL\" = 'http://127.0.0.1/logo.png' "
            ."WHEN NOT MATCHED THEN INSERT (\"SYS_Configuracion\", \"SYS_Nombre_Empresa\", \"SYS_Logo_URL\") VALUES (1, 'Lombardi & Asociados', 'http://127.0.0.1/logo.png')");
        $a = $this->activo();
        $mov = $this->asignar($a['ACT_Activo']);
        $r = $this->archivo('GET', "/api/v1/trazabilidad/acta/{$mov}?mensajero=Juan%20P%C3%A9rez");
        self::adjuntoComo($r, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', "Acta_Entrega_{$mov}\\.docx");
        $documento = self::xmlDeWord((string) $r->getContent());
        foreach (['NOTA: ENTREGA DE EQUIPO', 'Lombardi &amp; Asociados S.A.', 'Ñandú &amp; Cía Prueba', 'Entrega &lt;inicial&gt; &amp; más', 'Juan Pérez',
            'Mensajero externo', mb_strtoupper((string) D::conexion()->fetchOne('SELECT "MAR_Nombre" FROM "INV_MARCA" m JOIN "INV_MODELO" o ON o."MAR_Marca" = m."MAR_Marca" WHERE o."MOD_Modelo" = :m', ['m' => $this->modelo]))] as $texto) {
            self::assertStringContainsString($texto, $documento, 'El logo en una IP privada no se descarga (SSRF): sale el nombre en texto.');
        }

        $pdf = $this->archivo('GET', "/api/v1/trazabilidad/acta/{$mov}?formato=pdf&lang=it");
        self::adjuntoComo($pdf, 'application/pdf', "Verbale_Consegna_{$mov}\\.pdf");
        self::assertStringStartsWith('%PDF-', (string) $pdf->getContent());

        self::assertSame([409, 'MOVEMENT_NOT_RETURNED'], $this->detalle('GET', "/api/v1/trazabilidad/acta/{$mov}?tipo=descargo"));
        $falso = '00000000-0000-0000-0000-000000000001';
        self::assertSame([404, "MOVEMENT_NOT_FOUND: {$falso}"], $this->detalle('GET', "/api/v1/trazabilidad/acta/{$falso}"));
        self::assertSame(422, $this->archivo('GET', "/api/v1/trazabilidad/acta/{$mov}?formato=odt")->getStatusCode());
        $consulta = $this->tokenDe(D::crearUsuario(['rol' => 'CONSULTA', 'sedes' => [$this->sede]]));
        self::assertSame(403, $this->archivo('GET', "/api/v1/trazabilidad/acta/{$mov}", token: $consulta)->getStatusCode());
    }

    public function testActaPorLoteDeLaMismaPersonaYSusReglas(): void
    {
        $m1 = $this->asignar($this->activo()['ACT_Activo']);
        $m2 = $this->asignar($this->activo()['ACT_Activo']);
        $r = $this->archivo('POST', '/api/v1/trazabilidad/acta/lote?lang=en', ['movimientos_ids' => [$m1, $m2, $m1]]);
        self::adjuntoComo($r, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Handover_Record_Batch_\d{8}_\d{4}\.docx');
        self::assertSame(2, substr_count(self::xmlDeWord((string) $r->getContent()), 'PC-'), 'Un movimiento repetido no duplica el equipo.');

        $otra = $this->asignar($this->activo()['ACT_Activo'], D::crearPersona($this->sede, 'Otro'));
        self::assertSame([400, 'CANNOT_MIX_DIFFERENT_PEOPLE_IN_SAME_DOCUMENT'], $this->detalle('POST', '/api/v1/trazabilidad/acta/lote', ['movimientos_ids' => [$m1, $otra]]));
        self::assertSame([400, 'NO_MOVEMENTS_PROVIDED'], $this->detalle('POST', '/api/v1/trazabilidad/acta/lote', ['movimientos_ids' => []]));
        $ids = array_fill(0, 51, $m1);
        self::assertSame([400, 'TOO_MANY_MOVEMENTS_REQUESTED'], $this->detalle('POST', '/api/v1/trazabilidad/acta/lote', ['movimientos_ids' => $ids]));
        self::assertSame(422, $this->archivo('POST', '/api/v1/trazabilidad/acta/lote', [])->getStatusCode());
        $this->cliente->request('POST', '/api/v1/trazabilidad/acta/lote', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: '[]');
        self::assertSame([422, 'model_attributes_type'], [$this->cliente->getResponse()->getStatusCode(),
            json_decode((string) $this->cliente->getResponse()->getContent(), true)['errors'][0]['type']], 'Una lista en la raíz no es un objeto (como Pydantic).');
        self::assertSame(422, $this->archivo('POST', '/api/v1/trazabilidad/acta/lote', ['movimientos_ids' => ['x']])->getStatusCode());
    }

    /** XML del cuerpo de un .docx. */
    private static function xmlDeWord(string $docx): string
    {
        $temporal = (string) tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($temporal, $docx);
        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($temporal));
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($temporal);

        // Word parte el texto en varios «runs»: se unen para buscar frases.
        return (string) preg_replace('/<\/w:t>.*?<w:t(?: [^>]*)?>/s', '', $xml);
    }
}
