<?php

declare(strict_types=1);

namespace App\Autenticacion;

use App\Config\Ajustes;
use App\Correo\Notificador;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Reloj;
use App\Dominio\Usuario;
use App\Repositorio\CredencialesRepositorio;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\CodigosSeguridad;
use App\Seguridad\Contrasenas;

/** What users do with their own password: ask for a reset by e-mail, complete it, or change it while signed in. */
final readonly class AutoservicioContrasena
{
    public function __construct(
        private Transaccion $transaccion,
        private UsuarioRepositorio $usuarios,
        private CredencialesRepositorio $credenciales,
        private GobiernoRepositorio $gobierno,
        private CambioContrasena $cambio,
        private IntentosFallidos $intentos,
        private Notificador $notificador,
        private Ajustes $ajustes,
    ) {
    }

    /**
     * Starts a reset. It never tells whether the account exists: when it does not apply, nothing happens.
     * The single-use token goes only to the registered corporate e-mail, which proves the request is the owner's.
     */
    public function solicitarRestablecimiento(string $identificador, ?string $ip, ?string $ua): void
    {
        $usuario = $this->usuarios->porUsernameOCorreo(trim($identificador));
        $persona = null !== $usuario && $usuario->activo ? $this->usuarios->persona($usuario) : null;
        if (null === $usuario || null === $persona || null === $persona->correo || '' === $persona->correo) {
            return;
        }
        // An SSO-only account gets no password by e-mail: whoever controls the mailbox would bypass the identity provider.
        if ($usuario->ssoHabilitado && null === $usuario->passwordHash) {
            return;
        }
        // Cooldown per account: no second e-mail inside the window (anti-flooding).
        if ($this->credenciales->solicitudResetReciente($usuario->id, $this->ajustes->resetEnfriamientoMinutos)) {
            return;
        }
        [$plano, $hash] = CodigosSeguridad::tokenRestablecimiento();
        $this->transaccion->ejecutar(function () use ($usuario, $hash, $ip, $ua): void {
            $this->credenciales->invalidarResets($usuario->id);
            $this->credenciales->crearReset($usuario->id, $hash, Reloj::ahora()->modify("+{$this->ajustes->resetExpiraMinutos} minutes"));
            $this->gobierno->registrarAuditoria('PASSWORD_RESET_REQUEST', 'INV_USUARIO', ['username' => $usuario->username], $usuario->id, $ip, $ua);
        });
        // Only to the owner's address, never in copy: the token is their secret.
        $this->notificador->enviar('password_reset', [
            'persona_nombre' => $persona->nombreCorto(),
            'username' => $usuario->username,
            'token' => $plano,
            // In the fragment: the browser sends it neither to the server nor in the Referer.
            'reset_url' => $this->ajustes->urlFrontend.'/reset-password#token='.rawurlencode($plano),
            'minutos' => $this->ajustes->resetExpiraMinutos,
        ], [$persona->correo], false);
    }

    /** Completes a reset: valid single-use token, password policy, and every token of the user revoked. */
    public function confirmarRestablecimiento(string $token, string $nueva, ?string $ip, ?string $ua): void
    {
        $invalido = ErrorDominio::invalido('INVALID_OR_EXPIRED_RESET_TOKEN');
        $hash = CodigosSeguridad::hashRestablecimiento(trim($token));
        $usuarioId = $this->credenciales->resetVigente($hash) ?? throw $invalido;
        $usuario = $this->usuarios->porId($usuarioId);
        if (null === $usuario || !$usuario->activo) {
            throw $invalido;
        }
        // Checked before it is consumed: a rejected password does not spend the link.
        $this->cambio->validar($usuario, $nueva);
        $this->transaccion->ejecutar(function () use ($usuario, $hash, $nueva, $ip, $ua, $invalido): void {
            if (null === $this->credenciales->consumirReset($hash)) {
                throw $invalido; // the same link used twice at once
            }
            $this->cambio->aplicar($usuario, $nueva, true);
            $this->gobierno->registrarAuditoria('PASSWORD_RESET', 'INV_USUARIO',
                ['username' => $usuario->username, 'via' => 'email_token'], $usuario->id, $ip, $ua);
        });
        $this->avisar($usuario, 'Restablecimiento por email', $ip);
    }

    /**
     * Changes the password of the signed-in user: checks the current one again (a wrong one counts towards the
     * lockout: a stolen token does not allow guessing passwords), applies policy and history, and revokes every
     * token of the user.
     */
    public function cambiar(Usuario $u, string $actual, string $nueva, ?string $ip, ?string $ua): void
    {
        if (!Contrasenas::verificar($actual, $u->passwordHash)) {
            $this->intentos->registrar($u, Reloj::ahora(), null, 'cambio_password', $ip, $ua);
            throw ErrorDominio::invalido('INCORRECT_CURRENT_PASSWORD');
        }
        $this->cambio->validar($u, $nueva);
        $this->transaccion->ejecutar(function () use ($u, $nueva, $ip, $ua): void {
            $this->cambio->aplicar($u, $nueva, false);
            $this->gobierno->registrarAuditoria('PASSWORD_CHANGE', 'INV_USUARIO',
                ['username' => $u->username, 'self_service' => true], $u->id, $ip, $ua);
        });
        $this->avisar($u, 'Autoservicio (cambio manual)', $ip);
    }

    private function avisar(Usuario $u, string $metodo, ?string $ip): void
    {
        $persona = $this->usuarios->persona($u);
        $this->notificador->contrasenaCambiada($persona?->nombreCorto() ?? $u->username, $u->username, $persona?->correo, $metodo, $ip);
    }
}
