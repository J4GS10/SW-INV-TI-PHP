<?php

declare(strict_types=1);

namespace App\Documentos;

/** Idioma de los documentos y reportes generados (actas, CSV, PDF, Excel). */
enum Idioma: string
{
    case Es = 'es';
    case En = 'en';
    case It = 'it';

    /** Patrón del parámetro `lang` de las rutas de archivos (como el backend anterior). */
    public const string PATRON = '^(es|en|it)$';

    /** «en-US», «it_IT», «EN» → idioma; cualquier otro valor → español. */
    public static function desde(?string $valor): self
    {
        $base = strtolower(trim(explode('_', explode('-', $valor ?? 'es')[0])[0]));

        return self::tryFrom($base) ?? self::Es;
    }

    /**
     * Elige el texto del idioma en una tabla {es, en, it}.
     *
     * @template T
     *
     * @param array{es: T, en: T, it: T} $textos
     *
     * @return T
     */
    public function de(array $textos): mixed
    {
        return $textos[$this->value];
    }
}
