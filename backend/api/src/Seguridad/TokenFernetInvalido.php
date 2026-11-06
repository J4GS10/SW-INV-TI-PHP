<?php

declare(strict_types=1);

namespace App\Seguridad;

/** Valor cifrado manipulado, con otra clave o con formato inválido. */
final class TokenFernetInvalido extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('FIELD_DECRYPT_FAILED');
    }
}
