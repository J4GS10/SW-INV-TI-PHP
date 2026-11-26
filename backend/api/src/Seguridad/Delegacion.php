<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Dominio\Ident;
use App\Dominio\Usuario;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The account the request came from when an operator authorized it with their code (a shared scanner, or a
 * technician confirming on their own phone). The change runs as the operator; this keeps the account, so the
 * kardex and the audit trail say both who authorized it and from where.
 */
final class Delegacion implements ResetInterface
{
    /** @var array{usuario: string, username: string}|null */
    private ?array $cuenta = null;

    public function registrar(Usuario $cuenta): void
    {
        $this->cuenta = ['usuario' => Ident::aTexto($cuenta->id), 'username' => $cuenta->username];
    }

    /** @return array{usuario: string, username: string}|null null when the request was not authorized with a code */
    public function cuenta(): ?array
    {
        return $this->cuenta;
    }

    public function reset(): void
    {
        $this->cuenta = null;
    }
}
