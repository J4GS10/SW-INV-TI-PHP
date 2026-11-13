<?php

declare(strict_types=1);

namespace App\Autenticacion;

use App\Db\Transaccion;
use App\Dominio\Usuario;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\BloqueoCuenta;

/** Failed sign-in attempts: counts them towards the lockout and leaves each one in the audit log. */
final readonly class IntentosFallidos
{
    public function __construct(
        private Transaccion $transaccion,
        private BloqueoCuenta $bloqueo,
        private UsuarioRepositorio $usuarios,
        private GobiernoRepositorio $gobierno,
    ) {
    }

    /**
     * Adds a failed attempt and, if it locks the account, audits the lockout with its escalation.
     * A null `$motivo` records no LOGIN_FAILED event (wrong current password on a password change).
     *
     * @return bool whether the account is locked now
     */
    public function registrar(Usuario $u, \DateTimeImmutable $ahora, ?string $motivo, string $origen, ?string $ip, ?string $ua): bool
    {
        return $this->transaccion->ejecutar(function () use ($u, $ahora, $motivo, $origen, $ip, $ua): bool {
            $bloqueada = $this->bloqueo->registrarFallo($u, $ahora, $ip);
            $this->usuarios->guardarEstadoAcceso($u);
            if (null !== $motivo) {
                $this->gobierno->registrarAuditoria('LOGIN_FAILED', 'INV_USUARIO', [
                    'username' => $u->username, 'reason' => $bloqueada ? 'account_locked_now' : $motivo,
                    'intentos' => $u->intentosFallidos,
                ], $u->id, $ip, $ua);
            }
            if ($bloqueada) {
                $this->gobierno->registrarAuditoria('ACCOUNT_LOCKED', 'INV_USUARIO', [
                    'username' => $u->username, 'origen' => $origen, 'intentos' => $u->intentosFallidos,
                    ...$this->bloqueo->detalle($u, $ip),
                ], $u->id, $ip, $ua);
            }

            return $bloqueada;
        });
    }

    /** A failed sign-in that adds no attempt (locked or inactive account): saves the access state and audits it. */
    public function sinIntento(Usuario $u, string $motivo, ?string $ip, ?string $ua): void
    {
        $this->transaccion->ejecutar(function () use ($u, $motivo, $ip, $ua): void {
            $this->usuarios->guardarEstadoAcceso($u);
            $this->gobierno->registrarAuditoria('LOGIN_FAILED', 'INV_USUARIO',
                ['username' => $u->username, 'reason' => $motivo], $u->id, $ip, $ua);
        });
    }
}
