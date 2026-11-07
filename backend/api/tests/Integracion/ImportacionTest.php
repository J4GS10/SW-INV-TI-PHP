<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Importacion\LectorExcel;
use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as Lector;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Importación de activos desde Excel: plantilla, vista previa fila por fila (sin
 * guardar), confirmación en una sola transacción y reglas de unicidad.
 */
final class ImportacionTest extends PruebaApi
{
    private const string RUTA = '/api/v1/productividad/importar/activos';

    private int $sede;
    private string $areaNombre;
    private string $tipoNombre;
    private string $prefijo;

    protected function setUp(): void
    {
        parent::setUp();
        D::conexion()->executeStatement('DELETE FROM TRC_MONTH_END_CLOSE');
        I::estados();
        $this->sede = D::crearSede('Sede M '.D::sufijo());
        $area = I::area($this->sede);
        $this->areaNombre = (string) D::conexion()->fetchOne('SELECT "ARE_Nombre" FROM "INV_AREA" WHERE "ARE_Area" = :a', ['a' => $area]);
        $this->prefijo = 'M'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        $tipo = I::tipo('COMPUTO', $this->prefijo);
        $this->tipoNombre = (string) D::conexion()->fetchOne('SELECT "TAC_Nombre" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $tipo]);
        $this->token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
    }

    /**
     * Libro con la hoja INVENTARIO (encabezados en la fila 3, como el Excel original) y las filas dadas.
     *
     * @param list<list<mixed>> $filas
     * @param list<string>      $encabezados
     */
    private static function excel(array $filas, array $encabezados = ['Código', 'Categoría', 'Descripción breve', 'Marca', 'Modelo', 'Serie', 'Ubicación actual',
        'Fecha entrada', 'Costo', 'Moneda', 'ID Factura']): string
    {
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->setTitle('Resumen');
        $hoja = $libro->createSheet()->setTitle('Inventario');
        $hoja->fromArray([['Inventario GT'], [], $encabezados, ...$filas], null, 'A1', true);
        // Las fechas de Excel son números con formato de fecha (sin formato, también para openpyxl son números).
        for ($fila = 4; $fila <= $hoja->getHighestRow(); ++$fila) {
            if (\is_float($hoja->getCell("H{$fila}")->getValue())) {
                $hoja->getStyle("H{$fila}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            }
        }
        $temporal = (string) tempnam(sys_get_temp_dir(), 'imp');
        (new Xlsx($libro))->save($temporal);
        $contenido = (string) file_get_contents($temporal);
        unlink($temporal);

        return $contenido;
    }

    /** @return array{0: int, 1: mixed} */
    private function enviar(string $accion, string $contenido, string $consulta = ''): array
    {
        $r = $this->archivo('POST', self::RUTA."/{$accion}{$consulta}", archivos: ['archivo' => ['inventario.xlsx', $contenido]]);

        return [$r->getStatusCode(), json_decode((string) $r->getContent(), true)];
    }

    public function testPlantillaConListasProtegidas(): void
    {
        D::conexion()->executeStatement('INSERT INTO "INV_MARCA" ("MAR_Nombre") VALUES (:n)', ['n' => '=HYPERLINK("x") '.D::sufijo()]);
        $r = $this->archivo('GET', self::RUTA.'/plantilla');
        self::assertSame([200, 'attachment; filename="plantilla_activos.xlsx"'], [$r->getStatusCode(), $r->headers->get('Content-Disposition')]);
        $temporal = (string) tempnam(sys_get_temp_dir(), 'pla');
        file_put_contents($temporal, (string) $r->getContent());
        $libro = (new Lector())->load($temporal);
        unlink($temporal);
        self::assertSame(['INVENTARIO', 'Listas'], $libro->getSheetNames());
        self::assertSame('Código', $libro->getSheet(0)->getCell('A1')->getValue());
        $marcas = $libro->getSheet(1)->rangeToArray('C2:C'.$libro->getSheet(1)->getHighestRow(), null, false, false);
        foreach ($libro->getSheet(1)->getColumnIterator('C', 'C')->current()->getCellIterator(2) as $celda) {
            self::assertNotSame('f', $celda->getDataType(), 'Ningún nombre de usuario queda como fórmula.');
        }
        self::assertContains(['GTQ'], $libro->getSheet(1)->rangeToArray('D2:D4', null, false, false));
        self::assertNotEmpty($marcas);
        $tecnico = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede]]));
        self::assertSame(403, $this->archivo('GET', self::RUTA.'/plantilla', token: $tecnico)->getStatusCode());
    }

    public function testLaImportacionTerminaConElPrimerCierreMensual(): void
    {
        self::assertSame(['disponible' => true], $this->ok(200, 'GET', self::RUTA.'/estado'));
        D::conexion()->executeStatement('INSERT INTO TRC_MONTH_END_CLOSE (MEC_YEAR, MEC_MONTH, MEC_SNAPSHOT, MEC_USU_CLOSED_BY) '
            .'VALUES (2020, 1, :f, (SELECT "USU_Usuario" FROM "INV_USUARIO" FETCH FIRST 1 ROWS ONLY))', ['f' => '{}']);

        self::assertSame(['disponible' => false], $this->ok(200, 'GET', self::RUTA.'/estado'));
        [$estado, $cuerpo] = $this->enviar('previsualizar', self::excel([]));
        self::assertSame([409, 'IMPORT_ONLY_BEFORE_FIRST_CLOSE'], [$estado, $cuerpo['detail']]);
        [$estado, $cuerpo] = $this->enviar('confirmar', self::excel([]));
        self::assertSame([409, 'IMPORT_ONLY_BEFORE_FIRST_CLOSE'], [$estado, $cuerpo['detail']]);
    }

    public function testVistaPreviaYConfirmacionCreanActivosConKardexYSecuencia(): void
    {
        $marca = 'Marca '.D::sufijo();
        $serie = 'SER-'.D::sufijo();
        $codigoManual = $this->prefijo.'00050';
        $excel = self::excel([
            ['', $this->tipoNombre, 'Laptop 14', $marca, 'X1', $serie, $this->areaNombre, Date::PHPToExcel(new \DateTimeImmutable('2026-03-15')), '1,250.505', 'usd', 'F-1'],
            [$codigoManual, mb_strtoupper($this->tipoNombre), 'Laptop 14', $marca, 'X1', 'N/A', mb_strtoupper($this->areaNombre), '15/03/2026', '', '', ''],
            ['', 'Inexistente', '', '', '', 'ab', 'Área que no existe', 'ayer', '-5', 'EUR', ''],
            ['', '', '', '', '', '', '', '', '', '', ''],
        ]);
        [$e, $previa] = $this->enviar('previsualizar', $excel);
        self::assertSame(200, $e, json_encode($previa));
        self::assertSame(['filas' => 3, 'validas' => 2, 'con_error' => 1, 'marcas_nuevas' => 1, 'modelos_nuevos' => 1], $previa['resumen'], json_encode($previa['filas']));
        [$f1, $f2, $f3] = $previa['filas'];
        self::assertSame([4, 'aviso', ['NEW_BRAND', 'NEW_MODEL', 'AUTO_CODE']], [$f1['fila'], $f1['estado'], $f1['avisos']]);
        self::assertSame(['Laptop 14 (X1)', $this->areaNombre], [$f1['modelo'], $f1['ubicacion']]);
        self::assertSame([null, ['NEW_BRAND', 'NEW_MODEL', 'NO_SERIAL_USES_CODE']], [$f2['serie'], $f2['avisos']]);
        self::assertSame(['MISSING_BRAND', 'MISSING_MODEL', 'TYPE_NOT_FOUND', 'SERIAL_TOO_SHORT', 'AREA_NOT_FOUND', 'INVALID_DATE', 'INVALID_COST', 'INVALID_CURRENCY'],
            array_values(array_intersect(['MISSING_BRAND', 'MISSING_MODEL', 'TYPE_NOT_FOUND', 'SERIAL_TOO_SHORT', 'AREA_NOT_FOUND', 'INVALID_DATE', 'INVALID_COST', 'INVALID_CURRENCY'], $f3['errores'])));
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "INV_MARCA" WHERE "MAR_Nombre" = :m', ['m' => $marca]), 'La vista previa no guarda nada.');

        self::assertSame([400, 'IMPORT_HAS_ERRORS'], [$this->enviar('confirmar', $excel)[0], $this->enviar('confirmar', $excel)[1]['detail']]);
        [$e, $resultado] = $this->enviar('confirmar', $excel, '?omitir_errores=true');
        self::assertSame(201, $e, json_encode($resultado));
        self::assertSame([2, 1], [$resultado['creados'], $resultado['omitidos']]);
        [$auto, $manual] = $resultado['codigos'];
        self::assertSame($codigoManual, $manual);
        self::assertMatchesRegularExpression('/^'.$this->prefijo.'\d{5}$/', $auto);

        $activo = D::conexion()->fetchAssociative('SELECT a."ACT_Serie_Fabricante", a."ACT_Costo", a."ACT_Moneda", a."ACT_Fecha_Compra", a."ARE_Area_Ubicacion", a."SED_Sede", '
            .'e."EOP_Nombre" FROM "INV_ACTIVO" a JOIN "INV_ESTADO_OPERATIVO" e ON e."EOP_Estado_Operativo" = a."EOP_Estado_Operativo" WHERE a."ACT_Codigo_Interno" = :c', ['c' => $auto]);
        self::assertSame([$serie, 'USD', '2026-03-15', 'En Bodega', $this->sede], [$activo['ACT_Serie_Fabricante'], $activo['ACT_Moneda'],
            substr((string) $activo['ACT_Fecha_Compra'], 0, 10), $activo['EOP_Nombre'], (int) $activo['SED_Sede']]);
        self::assertSame('1250.5', rtrim(rtrim((string) $activo['ACT_Costo'], '0'), '.'), '1,250.505 → 1250.50 (redondeo bancario).');
        self::assertNotNull($activo['ARE_Area_Ubicacion']);
        self::assertSame($manual, D::conexion()->fetchOne('SELECT "ACT_Serie_Fabricante" FROM "INV_ACTIVO" WHERE "ACT_Codigo_Interno" = :c', ['c' => $manual]),
            'Sin serie del fabricante se usa el código.');
        self::assertSame(2, D::contar('SELECT COUNT(*) FROM "INV_KARDEX" k JOIN "INV_ACTIVO" a ON a."ACT_Activo" = k."ACT_Activo" '
            ."WHERE a.\"ACT_Codigo_Interno\" IN (:a, :b) AND k.\"KDX_Tipo\" = 'ALTA'", ['a' => $auto, 'b' => $manual]));
        self::assertGreaterThanOrEqual(50, (int) D::conexion()->fetchOne('SELECT "SEC_Ultimo_Numero" FROM "SYS_SECUENCIA" WHERE "SEC_Contexto" = :c',
            ['c' => "ASSET_{$this->prefijo}"]), 'La secuencia sube al mayor código importado.');
        self::assertSame(1, D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'ASSET_IMPORT' AND \"AUD_Snapshot_JSON\" LIKE :c",
            ['c' => '%'.$codigoManual.'%']));
        self::assertTrue(I::cadenaKardexIntegra());

        // Reimportar el mismo archivo: los códigos y las series ya existen.
        [, $otra] = $this->enviar('previsualizar', $excel);
        self::assertContains('SERIAL_EXISTS', $otra['filas'][0]['errores']);
        self::assertContains('CODE_EXISTS', $otra['filas'][1]['errores'], 'Unicidad sin importar ceros a la izquierda.');
    }

    /**
     * Fase 7 (hallazgo F-07): una «bomba zip» (hoja de decenas de MB que comprime a unos KB) tumbaba
     * el proceso de la API al descomprimirla. Se rechaza por el tamaño declarado en el ZIP, antes de leerla.
     */
    public function testBombaZipSeRechazaSinDescomprimir(): void
    {
        $normal = self::excel([['', $this->tipoNombre, 'Laptop', 'Marca', 'X1', 'SER-'.D::sufijo(), $this->areaNombre, '2026-01-01', '', '', '']]);
        $temporal = (string) tempnam(sys_get_temp_dir(), 'bomba');
        file_put_contents($temporal, $normal);
        $zip = new \ZipArchive();
        $zip->open($temporal);
        // ~40 MB de texto repetido en una sola celda por fila: comprime a decenas de KB.
        $relleno = str_repeat('0', 10_000);
        $filas = '';
        for ($i = 1; $i <= 4_000; ++$i) {
            $filas .= "<row r=\"{$i}\"><c r=\"A{$i}\" t=\"inlineStr\"><is><t>{$relleno}</t></is></c></row>";
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            ."<sheetData>{$filas}</sheetData></worksheet>");
        $zip->close();
        $bomba = (string) file_get_contents($temporal);
        unlink($temporal);
        self::assertLessThan(LectorExcel::MAX_BYTES, \strlen($bomba), 'La bomba pasa el límite de tamaño de la subida.');

        $inicio = microtime(true);
        self::assertSame([400, 'IMPORT_INVALID_FILE'], [$this->enviar('previsualizar', $bomba)[0], $this->enviar('previsualizar', $bomba)[1]['detail']]);
        self::assertLessThan(5.0, microtime(true) - $inicio, 'Se rechaza sin descomprimir la hoja.');
        // Un archivo legítimo sigue funcionando.
        self::assertSame(200, $this->enviar('previsualizar', $normal)[0]);
    }

    public function testArchivosInvalidosYDuplicadosDentroDelArchivo(): void
    {
        self::assertSame([400, 'IMPORT_INVALID_FILE'], [$this->enviar('previsualizar', 'no es un excel')[0], $this->enviar('previsualizar', 'no es un excel')[1]['detail']]);
        self::assertSame('IMPORT_HEADERS_NOT_FOUND', $this->enviar('previsualizar', self::excel([['a', 'b']], ['Nada', 'Que ver']))[1]['detail']);
        self::assertSame('IMPORT_EMPTY', $this->enviar('previsualizar', self::excel([]))[1]['detail']);
        self::assertSame([413, 'FILE_TOO_LARGE'], [$this->enviar('previsualizar', str_repeat('x', 5 * 1024 * 1024 + 1))[0], 'FILE_TOO_LARGE']);
        $r = $this->archivo('POST', self::RUTA.'/previsualizar');
        self::assertSame([422, ['body', 'archivo']], [$r->getStatusCode(), json_decode((string) $r->getContent(), true)['errors'][0]['loc']]);

        $fila = ['C-001', $this->tipoNombre, 'Monitor', 'Dell', 'P24', 'DUP-'.D::sufijo(), $this->areaNombre, '2026-01-01', '', '', ''];
        [, $previa] = $this->enviar('previsualizar', self::excel([$fila, [...\array_slice($fila, 0, 0), 'c-0001', ...\array_slice($fila, 1)]]));
        self::assertContains('CODE_PREFIX_MISMATCH', $previa['filas'][0]['avisos']);
        self::assertSame(['CODE_DUPLICATED_IN_FILE', 'SERIAL_DUPLICATED_IN_FILE'], $previa['filas'][1]['errores']);
        self::assertSame([400, 'IMPORT_NOTHING_TO_IMPORT'], [$this->enviar('confirmar', self::excel([['', 'X', '', '', '', '', '', '', '', '', '']]), '?omitir_errores=true')[0],
            'IMPORT_NOTHING_TO_IMPORT']);
    }
}
