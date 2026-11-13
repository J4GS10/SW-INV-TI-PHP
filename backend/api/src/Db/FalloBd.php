<?php

declare(strict_types=1);

namespace App\Db;

/** A database failure already classified, without the SQL or its parameters (they may carry personal data). */
final readonly class FalloBd
{
    /**
     * @param ?int    $codigo    the engine's error number
     * @param ?string $duplicado for a known unique constraint, the code that names what is repeated (BRAND_ALREADY_EXISTS…)
     * @param string  $mensaje   first line of the engine's message
     */
    public function __construct(public TipoFalloBd $tipo, public ?int $codigo, public ?string $duplicado, public string $mensaje)
    {
    }
}
