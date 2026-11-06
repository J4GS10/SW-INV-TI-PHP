<?php

declare(strict_types=1);

namespace App\Adjuntos;

/**
 * Validación del CONTENIDO de los adjuntos, no solo de la extensión. El navegador
 * envía el MIME que quiera y el nombre lo elige el usuario, así que:
 *  1. la firma del archivo (magic bytes) debe corresponder a su extensión;
 *  2. el MIME lo decide el servidor a partir de la extensión validada;
 *  3. el nombre original se sanea (sin rutas ni caracteres de control,
 *     conservando la extensión al truncar).
 */
final class TiposArchivo
{
    private const array MIME = [
        '.pdf' => 'application/pdf',
        '.png' => 'image/png',
        '.jpg' => 'image/jpeg',
        '.jpeg' => 'image/jpeg',
        '.webp' => 'image/webp',
        '.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        '.xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        '.csv' => 'text/csv; charset=utf-8',
        '.txt' => 'text/plain; charset=utf-8',
    ];

    private const string ZIP = "PK\x03\x04";
    private const array FIRMAS = [
        '.pdf' => '%PDF-',
        '.png' => "\x89PNG\r\n\x1a\n",
        '.jpg' => "\xff\xd8\xff",
        '.jpeg' => "\xff\xd8\xff",
        '.docx' => self::ZIP,
        '.xlsx' => self::ZIP,
    ];

    private const int MUESTRA_TEXTO = 8192;
    private const array CABECERAS_WEB = ['<!doctype', '<html', '<svg', '<script', '<?xml'];

    public static function mime(string $extension): string
    {
        return self::MIME[strtolower($extension)] ?? 'application/octet-stream';
    }

    /** Extensión en minúsculas con el punto («.pdf»), o '' si el nombre no tiene. */
    public static function extension(string $nombre): string
    {
        $ext = pathinfo($nombre, \PATHINFO_EXTENSION);

        return '' === $ext || str_starts_with($nombre, '.') && !str_contains(substr($nombre, 1), '.') ? '' : '.'.strtolower($ext);
    }

    /** ¿La firma del archivo corresponde a su extensión? */
    public static function contenidoCoincide(string $extension, string $contenido): bool
    {
        $ext = strtolower($extension);

        return match ($ext) {
            '.webp' => 'RIFF' === substr($contenido, 0, 4) && 'WEBP' === substr($contenido, 8, 4),
            '.csv', '.txt' => self::esTexto($contenido),
            default => isset(self::FIRMAS[$ext]) && str_starts_with($contenido, self::FIRMAS[$ext]),
        };
    }

    /** «../../etc/x.pdf» → «x.pdf»; quita caracteres de control y conserva la extensión al truncar. */
    public static function sanearNombre(?string $nombre, int $maximo = 255): string
    {
        $partes = explode('/', str_replace('\\', '/', $nombre ?? ''));
        $base = end($partes);
        $base = trim((string) preg_replace('/\p{C}+/u', '', '' === $base ? 'archivo' : $base), ' .');
        if ('' === $base) {
            $base = 'archivo';
        }
        if (mb_strlen($base) > $maximo) {
            $ext = self::extension($base);
            $raiz = mb_substr($base, 0, mb_strlen($base) - mb_strlen($ext));
            $base = mb_substr($raiz, 0, $maximo - mb_strlen($ext)).$ext;
        }

        return $base;
    }

    /** Texto UTF-8 sin bytes nulos que no sea una página web disfrazada (HTML, SVG, script). */
    private static function esTexto(string $contenido): bool
    {
        $muestra = substr($contenido, 0, self::MUESTRA_TEXTO);
        if (str_contains($muestra, "\0")) {
            return false;
        }
        if (!mb_check_encoding($muestra, 'UTF-8')) {
            // La muestra pudo cortar un carácter multibyte al final.
            $muestra = substr($muestra, 0, -3);
            if (!mb_check_encoding($muestra, 'UTF-8')) {
                return false;
            }
        }
        $cabeza = mb_substr(mb_strtolower(ltrim($muestra)), 0, 200);
        foreach (self::CABECERAS_WEB as $prefijo) {
            if (str_starts_with($cabeza, $prefijo)) {
                return false;
            }
        }

        return true;
    }
}
