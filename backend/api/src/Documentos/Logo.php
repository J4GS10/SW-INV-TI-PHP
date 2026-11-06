<?php

declare(strict_types=1);

namespace App\Documentos;

/**
 * Logo de la empresa ya validado y normalizado a PNG (sea cual sea el formato de
 * origen: así lo admiten TCPDF y Word, y no se incrusta el archivo remoto tal cual).
 */
final readonly class Logo
{
    /** Lado máximo del PNG incrustado. */
    private const int MAX_LADO_PX = 2000;
    /** Una imagen más grande ni se decodifica (bomba de descompresión: 4096² ≈ 64 MB en memoria). */
    private const int MAX_ORIGEN_PX = 4096;

    private function __construct(public string $png, public int $ancho, public int $alto)
    {
    }

    /** null si los bytes no son una imagen que GD pueda decodificar. */
    public static function desdeBytes(string $bytes): ?self
    {
        $info = @getimagesizefromstring($bytes);
        if (false === $info || $info[0] <= 0 || $info[1] <= 0 || $info[0] > self::MAX_ORIGEN_PX || $info[1] > self::MAX_ORIGEN_PX) {
            return null;
        }
        $imagen = @imagecreatefromstring($bytes);
        if (false === $imagen) {
            return null;
        }
        try {
            $escala = min(1.0, self::MAX_LADO_PX / max(imagesx($imagen), imagesy($imagen)));
            if ($escala < 1.0) {
                $reducida = imagescale($imagen, (int) round(imagesx($imagen) * $escala));
                if (false !== $reducida) {
                    $imagen = $reducida;
                }
            }
            imagesavealpha($imagen, true);
            ob_start();
            imagepng($imagen);
            $png = (string) ob_get_clean();

            return new self($png, imagesx($imagen), imagesy($imagen));
        } finally {
            unset($imagen);
        }
    }

    /**
     * Tamaño que cabe en la caja (ancho, alto) conservando la proporción, en las
     * mismas unidades que la caja.
     *
     * @return array{0: float, 1: float}
     */
    public function ajustadoA(float $anchoMax, float $altoMax): array
    {
        $escala = min($anchoMax / $this->ancho, $altoMax / $this->alto);

        return [$this->ancho * $escala, $this->alto * $escala];
    }
}
