<?php

declare(strict_types=1);

namespace App\Documentos;

/**
 * JSON con separadores «, » y «: » y caracteres sin escapar: el formato de
 * `json.dumps(..., ensure_ascii=False)` del backend anterior, que es el que ve el
 * usuario en la columna «Detalle (JSON)» del CSV de auditoría.
 */
final class JsonLegible
{
    /** Texto JSON guardado en la base → mismo JSON con el formato legible. */
    public static function reformatear(string $json): string
    {
        return self::codificar(json_decode($json, false, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING));
    }

    /** Objetos como \stdClass (distingue {} de []), listas como array. */
    public static function codificar(mixed $valor): string
    {
        return match (true) {
            $valor instanceof \stdClass => self::objeto(get_object_vars($valor)),
            \is_array($valor) => array_is_list($valor)
                ? '['.implode(', ', array_map(self::codificar(...), $valor)).']'
                : self::objeto($valor),
            default => json_encode($valor, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR),
        };
    }

    /** @param array<array-key, mixed> $pares */
    private static function objeto(array $pares): string
    {
        $partes = [];
        foreach ($pares as $clave => $valor) {
            $partes[] = self::codificar((string) $clave).': '.self::codificar($valor);
        }

        return '{'.implode(', ', $partes).'}';
    }
}
