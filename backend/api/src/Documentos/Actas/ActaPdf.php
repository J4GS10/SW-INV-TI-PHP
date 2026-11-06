<?php

declare(strict_types=1);

namespace App\Documentos\Actas;

use App\Documentos\Pdf;

/**
 * Acta en PDF (carta), con el formato del acta corporativa de referencia:
 * logo (o el nombre de la empresa) y código del formulario con la fecha; título;
 * texto de respaldo; tabla DISPOSITIVO | MARCA | MODELO | SERIE con cabecera
 * oscura; descripción; dos firmas y el pie legal en letra pequeña.
 */
final readonly class ActaPdf implements GeneradorActa
{
    private const string ESTILOS = '
        .tinta{color:#0F172A}
        .encabezado{width:100%;border-collapse:collapse}
        .encabezado td{vertical-align:middle;padding:0}
        .empresa{font-size:22pt;font-weight:bold;color:#1F3A5F}
        .codigo{font-size:10pt;font-weight:bold;text-align:right;line-height:14pt}
        .fecha{font-size:10pt;text-align:right}
        .titulo{text-align:center;font-size:12pt;font-weight:bold;margin:22pt 0 18pt}
        p{font-size:10.5pt;line-height:15pt;text-align:justify;margin:0 0 10pt}
        .equipos{width:100%;border-collapse:collapse;margin:4pt 0 16pt;font-size:9.5pt}
        .equipos th{background:#1F3A5F;color:#FFFFFF;font-weight:bold}
        .equipos th,.equipos td{border:0.7pt solid #8C99AD;padding:7pt 4pt;text-align:center;vertical-align:middle}
        .equipos tr{page-break-inside:avoid}
        .firmas{width:100%;margin-top:66pt;border-collapse:collapse;page-break-inside:avoid}
        .firmas td{width:50%;text-align:center;vertical-align:top;font-size:10pt;line-height:14pt}
        .firmas .empresa-firma{font-size:9.5pt;color:#5A6473}
        .pie{margin-top:30pt;font-size:7.5pt;line-height:10pt;text-align:justify;color:#5A6473}';

    /** Anchos de DISPOSITIVO | MARCA | MODELO | SERIE (sobre 6,75"). */
    private const array COLUMNAS = ['28%', '20%', '27%', '25%'];

    public function generar(DatosActa $acta): string
    {
        return Pdf::desdeHtml($this->html($acta), self::ESTILOS, 'letter', 'portrait', '0.8in 0.9in 0.7in 0.9in',
            $acta->tituloDocumento(), $acta->empresa, $acta->idioma);
    }

    private function html(DatosActa $acta): string
    {
        $e = Pdf::e(...);
        $t = $acta->textos();
        $r = $acta->rotulos();

        $logo = null === $acta->logo
            ? '<span class="empresa">'.$e($acta->empresa).'</span>'
            : \sprintf('<img src="%s" style="width:%.3fin;height:%.3fin">', Pdf::imagen('image/png', $acta->logo->png), ...$acta->logo->ajustadoA(2.6, 1.0));
        // El nombre del colaborador va en negrita dentro del texto de respaldo.
        $intro = str_replace($e($acta->colaborador), '<b>'.$e($acta->colaborador).'</b>', $e($acta->introduccion()));

        $fila = static fn (array $celdas, string $etiqueta): string => '<tr>'.implode('', array_map(
            static fn (string $c, string $ancho): string => "<{$etiqueta} style=\"width:{$ancho}\">".$e($c)."</{$etiqueta}>", $celdas, self::COLUMNAS)).'</tr>';
        $equipos = implode('', array_map(static fn (array $q): string => $fila([$q['dispositivo'], $q['marca'], $q['modelo'], $q['serie']], 'td'), $acta->equipos));

        $firma = static function (array $f) use ($e): string {
            [$nombre, $accion, $depto, $empresa] = $f;

            return '<td>_______________________________<br>'.('' !== $nombre ? '<b>'.$e($nombre).'</b><br>' : '').$e($accion).'<br><b>'.$e($depto)
                .'</b><br><span class="empresa-firma">'.$e($empresa).'</span></td>';
        };
        [$izquierda, $derecha] = $acta->firmas();

        return '<div class="tinta">'
            .'<table class="encabezado"><tr><td style="width:50%">'.$logo.'</td><td style="width:50%"><div class="codigo">'.$e($acta->codigoFormulario).'</div>'
            .'<div class="fecha">'.$e($acta->ciudad.', '.$acta->fechaLarga).'</div></td></tr></table>'
            .'<div class="titulo">'.$e($acta->titulo()).'</div>'
            .'<p>'.$intro.'</p>'
            .'<table class="equipos"><thead>'.$fila([$r['dispositivo'], $r['marca'], $r['modelo'], $r['serie']], 'th').'</thead><tbody>'.$equipos.'</tbody></table>'
            .'<p>'.$e($t['cuerpo']).'</p>'
            .'<p><b>'.$e($r['descripcion']).':</b> '.$e($acta->motivo).'</p>'
            .'<table class="firmas"><tr>'.$firma($izquierda).$firma($derecha).'</tr></table>'
            .'<div class="pie">•&nbsp;&nbsp;'.$e($acta->pieLegal()).'</div>'
            .'</div>';
    }
}
