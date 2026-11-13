<?php

declare(strict_types=1);

namespace App\Autenticacion\Domain;

/** The password was right but the account asks for a second factor: the challenge the client must answer. */
final readonly class DesafioSegundoFactor
{
    /** @param int $diasRecordar days the browser may be remembered once the code is given (0 = not offered) */
    public function __construct(public ?string $metodo, public string $token, public int $diasRecordar)
    {
    }
}
