<?php

declare(strict_types=1);

namespace App\Http;

use App\Documentos\TiposMime;
use Symfony\Component\HttpFoundation\Response;

/** Respuesta de descarga de un archivo generado (Content-Disposition: attachment). */
final class Descarga
{
    public static function archivo(string $contenido, string $tipo, string $nombre): Response
    {
        return new Response($contenido, 200, [
            'Content-Type' => $tipo,
            'Content-Disposition' => \sprintf('attachment; filename="%s"', $nombre),
        ]);
    }

    public static function csv(string $contenido, string $nombre): Response
    {
        return self::archivo($contenido, TiposMime::CSV, $nombre);
    }
}
