<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dominio\ErrorDominio;
use App\Adjuntos\TiposArchivo;
use App\Config\Ajustes;
use App\Documentos\Actas\TextosComunes;
use App\Documentos\ArchivoCsv;
use App\Documentos\CeldaSegura;
use App\Documentos\DescargaLogo;
use App\Documentos\FormatoLocal;
use App\Documentos\Idioma;
use App\Documentos\JsonLegible;
use App\Documentos\LibroExcel;
use App\Documentos\Logo;
use App\Documentos\Traducciones;
use App\Importacion\Texto;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Piezas puras de la fase 4 (caja blanca): mismas reglas que el backend anterior. */
final class DocumentosTest extends TestCase
{
    public function testIdiomaNormalizaVariantesYCaeAEspanol(): void
    {
        self::assertSame([Idioma::En, Idioma::It, Idioma::Es, Idioma::Es], [Idioma::desde('en-US'), Idioma::desde('IT_it'), Idioma::desde('fr'), Idioma::desde(null)]);
    }

    public function testTraduceCatalogosPorAliasSinTildesYDevuelveLoDesconocido(): void
    {
        self::assertSame('Under Repair', Traducciones::catalogo(Traducciones::ESTADO_OPERATIVO, 'en reparacion', Idioma::En));
        self::assertSame('In riparazione', Traducciones::catalogo(Traducciones::ESTADO_OPERATIVO, 'STATUS.IN_REPAIR', Idioma::It));
        self::assertSame('Asignación', Traducciones::catalogo(Traducciones::TIPO_MOVIMIENTO, 'Assignment', Idioma::Es), 'Inglés → español.');
        self::assertSame('Audit log export', Traducciones::catalogo(Traducciones::ACCION_AUDITORIA, 'AUDIT_EXPORT', Idioma::En));
        self::assertSame('Mi tipo propio', Traducciones::catalogo(Traducciones::TIPO_ACTIVO, 'Mi tipo propio', Idioma::En));
        self::assertSame(['', '  '], [Traducciones::catalogo(Traducciones::UNIDAD, null, Idioma::En), Traducciones::catalogo(Traducciones::UNIDAD, '  ', Idioma::En)]);
        self::assertSame(['Sì', 'No', 'Physical count', 'RARO'], [Traducciones::siNo(true, Idioma::It), Traducciones::siNo(false, Idioma::En),
            Traducciones::tipoEvento('INVENTARIO_FISICO', Idioma::En), Traducciones::tipoEvento('RARO', Idioma::En)]);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function celdas(): iterable
    {
        yield 'fórmula' => ['=HYPERLINK("x")', "'=HYPERLINK(\"x\")"];
        yield 'suma' => ['+1', "'+1"];
        yield 'resta' => ['-2', "'-2"];
        yield 'arroba' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tabulador' => ["\tx", "'\tx"];
        yield 'ancho completo' => ['＝1+1', "'＝1+1"];
        yield 'texto normal' => ['Laptop', 'Laptop'];
        yield 'negativo (igual que el backend anterior)' => [-5, "'-5"];
        yield 'entero' => [7, '7'];
        yield 'nulo' => [null, ''];
    }

    #[DataProvider('celdas')]
    public function testCeldaCsvNeutralizaFormulas(mixed $valor, string $esperado): void
    {
        self::assertSame($esperado, CeldaSegura::csv($valor));
    }

    public function testCsvConBomCrlfYComillasComoElModuloCsv(): void
    {
        $minimo = ArchivoCsv::minimo(['A', 'B'])->fila(['x,y', 'di "hola"'])->fila(["l1\nl2", 3])->contenido();
        self::assertSame("\u{FEFF}A,B\r\n\"x,y\",\"di \"\"hola\"\"\"\r\n\"l1\nl2\",3\r\n", $minimo);
        self::assertSame("\u{FEFF}\"A\"\r\n\"'=1\"\r\n", ArchivoCsv::entreComillas(['A'])->fila(['=1'])->contenido());
    }

    public function testTiposArchivoExigenFirmaYRechazanHtmlDisfrazado(): void
    {
        self::assertTrue(TiposArchivo::contenidoCoincide('.PDF', "%PDF-1.7\n"));
        self::assertFalse(TiposArchivo::contenidoCoincide('.pdf', "MZ\x90\x00 ejecutable"));
        self::assertTrue(TiposArchivo::contenidoCoincide('.webp', 'RIFF'."\x10\x00\x00\x00".'WEBPVP8 '));
        self::assertTrue(TiposArchivo::contenidoCoincide('.csv', "a,b\nñ,ü\n"));
        self::assertFalse(TiposArchivo::contenidoCoincide('.txt', "  <!DOCTYPE html><script>alert(1)</script>"));
        self::assertFalse(TiposArchivo::contenidoCoincide('.txt', "binario\0nulo"));
        self::assertFalse(TiposArchivo::contenidoCoincide('.exe', 'MZ'));
        self::assertSame(['application/pdf', 'application/octet-stream'], [TiposArchivo::mime('.PDF'), TiposArchivo::mime('.exe')]);
    }

    public function testNombreSaneadoSinRutasNiControlesYConExtensionAlTruncar(): void
    {
        self::assertSame('x.pdf', TiposArchivo::sanearNombre('../../etc/x.pdf'));
        self::assertSame('x.pdf', TiposArchivo::sanearNombre('C:\\Users\\a\\x.pdf'));
        self::assertSame('ab.pdf', TiposArchivo::sanearNombre("a\u{0000}b\u{202E}.pdf"));
        self::assertSame('archivo', TiposArchivo::sanearNombre('...'));
        $largo = TiposArchivo::sanearNombre(str_repeat('ñ', 300).'.docx');
        self::assertSame([255, '.docx'], [mb_strlen($largo), substr($largo, -5)]);
        self::assertSame(['.pdf', '', ''], [TiposArchivo::extension('a.b.PDF'), TiposArchivo::extension('.bashrc'), TiposArchivo::extension('sin')]);
    }

    public function testJsonLegibleComoJsonDumpsDelBackendActual(): void
    {
        self::assertSame('{"a": 1, "b": [1, 2], "c": {}, "d": "ñ/é", "e": null, "f": 1.5}',
            JsonLegible::reformatear('{"a":1,"b":[1,2],"c":{},"d":"ñ\/é","e":null,"f":1.5}'));
    }

    public function testTextoDeImportacionNormalizaYCompararCodigos(): void
    {
        self::assertSame(['12', 'Hola mundo', '2026-01-02 00:00:00'], [Texto::limpio(12.0), Texto::limpio("  Hola \n mundo "),
            Texto::limpio(new \DateTimeImmutable('2026-01-02'))]);
        self::assertSame('ubicacion actual', Texto::normalizar(' Ubicación   ACTUAL '));
        self::assertSame(Texto::claveCodigo('CAM-0001'), Texto::claveCodigo(' cam-00001'));
        self::assertNotSame(Texto::claveCodigo('CAM-1'), Texto::claveCodigo('CAM-10'));
        self::assertSame('X-ABC', Texto::claveCodigo('x-abc'));
    }

    public function testUrlDelLogoSoloHttpPuertosEstandarSinCredencialesNiLocalhost(): void
    {
        self::assertTrue(DescargaLogo::esUrlAdmitida('https://cdn.empresa.com/logo.png'));
        foreach (['ftp://x.com/l.png', 'file:///etc/passwd', 'https://u:p@x.com/l.png', 'https://x.com:8443/l.png', 'http://localhost/l.png',
            'http://a.localhost./l.png', 'nada', ''] as $url) {
            self::assertFalse(DescargaLogo::esUrlAdmitida($url), $url);
        }
    }

    public function testLogoSeNormalizaAPngYSeAjustaALaCaja(): void
    {
        $imagen = imagecreatetruecolor(400, 100);
        ob_start();
        imagejpeg($imagen);
        $logo = Logo::desdeBytes((string) ob_get_clean());
        self::assertNotNull($logo);
        self::assertStringStartsWith("\x89PNG", $logo->png);
        self::assertSame([2.6, 0.65], array_map(static fn (float $v): float => round($v, 2), $logo->ajustadoA(2.6, 1.0)));
        self::assertNull(Logo::desdeBytes('<svg onload="x"/>'), 'Lo que no es una imagen rasterizable se descarta.');
    }

    public function testFormatoLocalEnZonaDeLaOrganizacionYMontosBancarios(): void
    {
        $f = new FormatoLocal(Ajustes::desdeEntorno(['SECRET_KEY' => str_repeat('k', 40), 'ORG_TIMEZONE' => 'America/Guatemala']));
        self::assertSame('2026-10-28 18:30:00', $f->fechaHora('2026-10-29 00:30:00.000000'), 'UTC → Guatemala (UTC−6): sigue siendo el 28.');
        self::assertSame('2026-10-28 18:30:00', $f->fechaHora('2026-10-29T00:30:00'));
        self::assertSame(['', '2026-10-01'], [$f->fechaHora(null), $f->fecha('2026-10-01 00:00:00')]);
        self::assertSame('1º ottobre 2026', $f->fechaLarga(new \DateTimeImmutable('2026-10-01'), Idioma::It));
        self::assertSame('October 24, 2026', $f->fechaLarga(new \DateTimeImmutable('2026-10-24'), Idioma::En));
        self::assertSame(['0.12', '0.14', '5.00', ''], [FormatoLocal::monto('.125'), FormatoLocal::monto('0.135'), FormatoLocal::monto('5'), FormatoLocal::monto(null)]);
    }

    public function testRazonSocialSoloAgregaSaSiNoHayFormaSocietaria(): void
    {
        self::assertSame(['Lombardi S.A.', 'Acme Inc.', 'Foo, S.A. de C.V.'], [TextosComunes::razonSocial(' Lombardi '),
            TextosComunes::razonSocial('Acme Inc.'), TextosComunes::razonSocial('Foo, S.A. de C.V.')]);
    }

    public function testLibroExcelNoGuardaFormulasYTipaNumeros(): void
    {
        $libro = new LibroExcel();
        $hoja = $libro->hoja('Una hoja con nombre demasiado largo para Excel', ['A', 'B', 'C']);
        $libro->fila($hoja, ['=HYPERLINK("http://x")', 12.5, null]);
        $temporal = (string) tempnam(sys_get_temp_dir(), 'xls');
        file_put_contents($temporal, $libro->contenido());
        $leido = (new Xlsx())->load($temporal)->getSheet(0);
        unlink($temporal);
        self::assertSame(31, mb_strlen($leido->getTitle()));
        self::assertSame([DataType::TYPE_STRING, true], [$leido->getCell('A2')->getDataType(), $leido->getStyle('A2')->getQuotePrefix()]);
        self::assertSame([DataType::TYPE_NUMERIC, 12.5], [$leido->getCell('B2')->getDataType(), $leido->getCell('B2')->getValue()]);
        self::assertTrue($leido->getStyle('A1')->getFont()->getBold());
    }
    public function testDescargaDelLogoSoloAceptaImagenesPequenasYValidas(): void
    {
        $imagen = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($imagen);
        $png = (string) ob_get_clean();
        $con = static fn (MockResponse $r): DescargaLogo => new DescargaLogo(new NullLogger(), new MockHttpClient($r));
        // IP pública de documentación: el cliente protegido no la bloquea, el simulado responde.
        $url = 'https://93.184.216.34/logo.png';

        $logo = $con(new MockResponse($png, ['response_headers' => ['content-type' => 'image/png'], 'primary_ip' => '93.184.216.34']))->descargar($url);
        self::assertNotNull($logo);
        self::assertSame([40, 20], [$logo->ancho, $logo->alto]);
        self::assertNull($con(new MockResponse('<html>', ['response_headers' => ['content-type' => 'text/html'], 'primary_ip' => '93.184.216.34']))->descargar($url),
            'Solo image/*.');
        self::assertNull($con(new MockResponse('', ['http_code' => 404, 'primary_ip' => '93.184.216.34']))->descargar($url));
        self::assertNull($con(new MockResponse(str_repeat('x', 3_000_001), ['response_headers' => ['content-type' => 'image/png'], 'primary_ip' => '93.184.216.34']))
            ->descargar($url), 'Más de 3 MB: se desiste.');
        self::assertNull($con(new MockResponse($png, ['response_headers' => ['content-type' => 'image/png']]))->descargar('http://10.0.0.5/logo.png'),
            'Una IP privada nunca se consulta (SSRF).');
        self::assertNull($con(new MockResponse($png))->descargar(null));
    }

    public function testUnExporteQueSuperaElLimitePideFiltrosEnVezDeCortarse(): void
    {
        \App\Documentos\Infrastructure\Exportaciones::exigirTamano(\App\Documentos\Infrastructure\Exportaciones::MAX_ACTIVOS);
        try {
            \App\Documentos\Infrastructure\Exportaciones::exigirTamano(\App\Documentos\Infrastructure\Exportaciones::MAX_ACTIVOS + 1);
            self::fail("Debía rechazarse.");
        } catch (ErrorDominio $e) {
            self::assertSame(422, \App\EventListener\ErroresListener::estadoHttp($e->motivo));
            self::assertSame("EXPORT_TOO_LARGE_USE_FILTERS:10001", $e->detalle);
        }
    }
}
