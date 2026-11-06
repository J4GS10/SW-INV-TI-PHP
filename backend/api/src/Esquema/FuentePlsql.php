<?php

declare(strict_types=1);

namespace App\Esquema;

/**
 * Comparación de fuentes PL/SQL por su contenido: se quitan los comentarios
 * (`--` hasta el fin de línea y `/* … *\/`, fuera de literales) y se reducen los
 * espacios. Así reinstalar la capa de seguridad no recompila un paquete que solo
 * difiere en sangría o en un comentario (recompilar INV_VPD, que tiene estado,
 * descartaría ese estado en las sesiones abiertas de la API: ORA-04068).
 */
final class FuentePlsql
{
    public static function normalizar(string $fuente): string
    {
        $salida = '';
        $n = \strlen($fuente);
        for ($i = 0; $i < $n; ++$i) {
            $c = $fuente[$i];
            if ("'" === $c) {
                // Literal completo, con las comillas duplicadas ('') como escape.
                $fin = $i + 1;
                while ($fin < $n && !("'" === $fuente[$fin] && ($fin + 1 >= $n || "'" !== $fuente[$fin + 1]))) {
                    $fin += "'" === $fuente[$fin] ? 2 : 1;
                }
                $salida .= substr($fuente, $i, $fin - $i + 1);
                $i = $fin;
            } elseif ('-' === $c && '-' === ($fuente[$i + 1] ?? '')) {
                $salto = strpos($fuente, "\n", $i);
                $i = false === $salto ? $n : $salto - 1;
                $salida .= ' ';
            } elseif ('/' === $c && '*' === ($fuente[$i + 1] ?? '')) {
                $cierre = strpos($fuente, '*/', $i + 2);
                $i = false === $cierre ? $n : $cierre + 1;
                $salida .= ' ';
            } else {
                $salida .= $c;
            }
        }

        $texto = trim((string) preg_replace('/\s+/', ' ', $salida));

        // La cabecera puede nombrar el objeto con o sin esquema y comillas
        // (Data Pump escribe TRIGGER "DUEÑO"."TRG_X"; el CREATE original, trg_x).
        return (string) preg_replace_callback(
            '/^(PACKAGE BODY|PACKAGE|TRIGGER) (?:"?[A-Za-z0-9_$#]+"?\.)?"?([A-Za-z0-9_$#]+)"?/i',
            static fn (array $m): string => strtoupper($m[1]).' '.strtoupper($m[2]),
            $texto,
        );
    }
}
