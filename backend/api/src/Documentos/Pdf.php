<?php

declare(strict_types=1);

namespace App\Documentos;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF a partir de HTML y CSS (Dompdf). El motor no descarga nada de la red ni
 * ejecuta PHP o JavaScript del documento: las imágenes (logo, QR) van incrustadas
 * como data URI. Helvetica, como los documentos del backend anterior.
 */
final class Pdf
{
    /**
     * @param string $papel       'letter' o 'a4'
     * @param string $orientacion 'portrait' o 'landscape'
     * @param string $margen      márgenes de la página en CSS (arriba derecha abajo izquierda)
     */
    public static function desdeHtml(string $cuerpo, string $estilos, string $papel, string $orientacion, string $margen, string $titulo, string $autor, Idioma $idioma): string
    {
        $opciones = new Options();
        $opciones->setIsRemoteEnabled(false);
        $opciones->setIsPhpEnabled(false);
        $opciones->setIsJavascriptEnabled(false);
        $opciones->setDefaultFont('Helvetica');
        $opciones->setDefaultPaperSize($papel);
        $opciones->setDefaultPaperOrientation($orientacion);
        // Caché de métricas en un directorio escribible (el código de la imagen es de solo lectura).
        $opciones->setFontCache(sys_get_temp_dir());
        $opciones->setTempDir(sys_get_temp_dir());
        $opciones->setChroot([sys_get_temp_dir()]);

        $pdf = new Dompdf($opciones);
        $pdf->loadHtml('<!DOCTYPE html><html lang="'.$idioma->value.'"><head><meta charset="utf-8"><title>'.self::e($titulo).'</title>'
            .'<style>@page{margin:'.$margen.'}body{font-family:Helvetica,sans-serif;margin:0}'.$estilos.'</style></head><body>'.$cuerpo.'</body></html>', 'UTF-8');
        $pdf->addInfo('Author', $autor);
        $pdf->addInfo('Creator', 'Inventario TI');
        $pdf->render();

        return (string) $pdf->output(['compress' => 1]);
    }

    /** Texto de usuario dentro del HTML. */
    public static function e(string $texto): string
    {
        return htmlspecialchars($texto, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    /** Imagen incrustada (sin acceso a la red ni al disco). */
    public static function imagen(string $tipo, string $bytes): string
    {
        return 'data:'.$tipo.';base64,'.base64_encode($bytes);
    }
}
