<?php

declare(strict_types=1);

namespace App\Documentos;

use Symfony\Component\HttpFoundation\Response;

/** Respuesta de descarga de un archivo generado (Content-Disposition: attachment). */
final class Descarga
{
    public const string CSV = 'text/csv; charset=utf-8';
    public const string PDF = 'application/pdf';
    public const string DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    public const string XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public static function archivo(string $contenido, string $tipo, string $nombre): Response
    {
        return new Response($contenido, 200, [
            'Content-Type' => $tipo,
            'Content-Disposition' => \sprintf('attachment; filename="%s"', $nombre),
        ]);
    }

    public static function csv(string $contenido, string $nombre): Response
    {
        return self::archivo($contenido, self::CSV, $nombre);
    }
}
