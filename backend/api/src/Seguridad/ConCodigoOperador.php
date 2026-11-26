<?php

declare(strict_types=1);

namespace App\Seguridad;

/**
 * The action may be authorized with an operator code (headers X-Operator-User and X-Operator-Code): the change
 * then runs with the identity, role and sites of the code's owner. A scanner account (ESCANER) can only change
 * anything this way; for other accounts the code is optional and, if sent, is checked the same way.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class ConCodigoOperador
{
    public const string CABECERA_USUARIO = 'X-Operator-User';
    public const string CABECERA_CODIGO = 'X-Operator-Code';
}
