<?php

declare(strict_types=1);

namespace App\Documentos;

use App\Dominio\ErrorDominio;
use App\Seguridad\Alcance;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Doctrine\DBAL\Connection;

/**
 * Etiquetas imprimibles con QR del código interno, en hojas carta con cuadrícula
 * tipo Avery 5160 (3 columnas × 10 filas de 2,625" × 1"). El QR es vectorial
 * (nítido a cualquier escala de impresión), con corrección de errores M.
 */
final readonly class Etiquetas
{
    public const int MAXIMO = 200;

    private const array TEXTOS = [
        'es' => ['serie' => 'N.º de serie', 'titulo' => 'Etiquetas de activos', 'archivo' => 'etiquetas-activos'],
        'en' => ['serie' => 'Serial no.', 'titulo' => 'Asset labels', 'archivo' => 'asset-labels'],
        'it' => ['serie' => 'N. di serie', 'titulo' => 'Etichette degli asset', 'archivo' => 'etichette-asset'],
    ];

    private const int COLUMNAS = 3;
    private const int FILAS = 10;
    // Medidas en pulgadas.
    private const float ANCHO = 2.625;
    private const float ALTO = 1.0;
    private const float MARGEN_IZQUIERDO = 0.1875;
    private const float MARGEN_SUPERIOR = 0.5;
    private const float SEPARACION = 0.125;

    private const string ESTILOS = '
        .hoja{position:relative;width:8.5in;height:10.9in;page-break-after:always}
        .hoja:last-child{page-break-after:auto}
        .etiqueta{position:absolute;width:2.625in;height:1in;overflow:hidden}
        .qr{position:absolute;left:0.08in;top:0.16in;width:0.68in;height:0.68in}
        .texto{position:absolute;left:0.84in;width:1.725in;white-space:nowrap;overflow:hidden}
        .codigo{top:0.18in;font-size:10pt;font-weight:bold}
        .host{top:0.42in;font-size:7pt}
        .serie{top:0.60in;font-size:7pt}';

    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    public static function nombreArchivo(Idioma $idioma): string
    {
        return $idioma->de(self::TEXTOS)['archivo'].'.pdf';
    }

    /**
     * @param list<string> $activos UUID en hex
     * @param list<string> $codigos códigos internos
     */
    public function generar(array $activos, array $codigos, Idioma $idioma): string
    {
        $textos = $idioma->de(self::TEXTOS);
        $e = Pdf::e(...);
        $hojas = [];
        foreach (array_chunk($this->cargar($activos, $codigos), self::COLUMNAS * self::FILAS) as $hoja) {
            $etiquetas = '';
            foreach ($hoja as $i => $activo) {
                $codigo = (string) $activo['ACT_Codigo_Interno'];
                $izquierda = self::MARGEN_IZQUIERDO + ($i % self::COLUMNAS) * (self::ANCHO + self::SEPARACION);
                $arriba = self::MARGEN_SUPERIOR + intdiv($i, self::COLUMNAS) * self::ALTO;
                $host = (string) ($activo['ACT_Hostname'] ?? '');
                $etiquetas .= \sprintf('<div class="etiqueta" style="left:%.4fin;top:%.4fin">', $izquierda, $arriba)
                    .'<img class="qr" src="'.self::qr($codigo).'">'
                    .'<div class="texto codigo">'.$e(mb_substr($codigo, 0, 24)).'</div>'
                    .('' !== $host ? '<div class="texto host">'.$e(mb_substr($host, 0, 30)).'</div>' : '')
                    // Una serie larga se recorta al ancho de la etiqueta (overflow), como el backend anterior.
                    .'<div class="texto serie">'.$e($textos['serie'].': '.($activo['ACT_Serie_Fabricante'] ?? '')).'</div>'
                    .'</div>';
            }
            $hojas[] = '<div class="hoja">'.$etiquetas.'</div>';
        }

        return Pdf::desdeHtml(implode('', $hojas), self::ESTILOS, 'letter', 'portrait', '0', $textos['titulo'], '', $idioma);
    }

    /** QR vectorial con zona de silencio (data URI SVG). */
    private static function qr(string $codigo): string
    {
        return (new QRCode(new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
        ])))->render($codigo);
    }

    /**
     * Activos pedidos por id o por código (visibles para el usuario), ordenados por código.
     *
     * @param list<string> $activos
     * @param list<string> $codigos
     *
     * @return list<array<string, mixed>>
     */
    private function cargar(array $activos, array $codigos): array
    {
        $codigos = array_values(array_filter(array_map('trim', $codigos), static fn (string $c): bool => '' !== $c));
        $condiciones = [];
        $p = [];
        foreach (['ACT_Activo' => $activos, 'ACT_Codigo_Interno' => $codigos] as $columna => $valores) {
            if ([] === $valores) {
                continue;
            }
            $nombres = [];
            foreach ($valores as $i => $v) {
                $nombres[] = ":{$columna}_{$i}";
                $p["{$columna}_{$i}"] = $v;
            }
            $condiciones[] = "a.\"{$columna}\" IN (".implode(', ', $nombres).')';
        }
        if ([] === $condiciones) {
            throw ErrorDominio::invalido('LABEL_ASSETS_REQUIRED');
        }
        [$alcance, $pa] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $filas = $this->conexion->fetchAllAssociative('SELECT a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", a."ACT_Hostname" FROM "INV_ACTIVO" a '
            .'WHERE ('.implode(' OR ', $condiciones).') AND '.$alcance.' ORDER BY a."ACT_Codigo_Interno"', [...$p, ...$pa]);
        if ([] === $filas) {
            throw ErrorDominio::noEncontrado('ASSETS_NOT_FOUND');
        }
        if (\count($filas) > self::MAXIMO) {
            throw ErrorDominio::invalido('TOO_MANY_LABELS_REQUESTED');
        }

        return $filas;
    }
}
