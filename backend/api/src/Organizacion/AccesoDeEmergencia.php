<?php

declare(strict_types=1);

namespace App\Organizacion;

use App\Autenticacion\CambioContrasena;
use App\Correo\Notificador;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Organizacion\Infrastructure\CuentasStore;
use App\Repositorio\CredencialesRepositorio;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Security\Mfa\TrustedDevices;
use App\Seguridad\BloqueoCuenta;
use App\Seguridad\PoliticaContrasenas;

/**
 * Break-glass recovery of a super administrator, from the server console, for when nobody can sign in to do
 * it in the app (phone and recovery codes lost, every SUPER_ADMIN locked out). Only someone who already
 * controls the host can run it; even so the account ends up clean: no second factor, recovery codes,
 * remembered devices, sessions or lockouts, and MFA must be enrolled again at the next sign-in. Optionally a
 * temporary password that has to be changed on entry. Every use is audited with its reason and operator.
 */
final readonly class AccesoDeEmergencia
{
    public const int MOTIVO_MINIMO = 10;

    public function __construct(
        private UsuarioRepositorio $usuarios,
        private CuentasStore $cuentas,
        private CredencialesRepositorio $credenciales,
        private GobiernoRepositorio $gobierno,
        private BloqueoCuenta $bloqueo,
        private TrustedDevices $equipos,
        private CambioContrasena $cambio,
        private PoliticaContrasenas $politica,
        private Notificador $notificador,
        private Transaccion $transaccion,
    ) {
    }

    /**
     * @return string|null the temporary password, only when asked for (it is shown once and never stored in clear)
     */
    public function restablecer(string $username, string $motivo, string $operador, bool $contrasenaTemporal): ?string
    {
        $motivo = trim($motivo);
        $operador = trim($operador);
        if (mb_strlen($motivo) < self::MOTIVO_MINIMO || '' === $operador) {
            throw ErrorDominio::invalido('EMERGENCY_REASON_AND_OPERATOR_REQUIRED');
        }
        $usuario = $this->usuarios->porUsername(trim($username));
        if (null === $usuario || !$usuario->activo) {
            throw ErrorDominio::noEncontrado('USER_INACTIVE_OR_NOT_FOUND');
        }
        // Any other account is recovered in the app by a super administrator; this path stays as narrow as possible.
        if (Roles::SUPER_ADMIN !== $usuario->rol) {
            throw ErrorDominio::prohibido('EMERGENCY_ONLY_FOR_SUPER_ADMIN');
        }
        if ($contrasenaTemporal && null === $usuario->passwordHash
            && 1 === (int) (((array) $this->cuentas->porId($usuario->id))['USU_SSO_Habilitado'] ?? 0)) {
            throw ErrorDominio::invalido('SSO_ONLY_ACCOUNT');
        }
        $temporal = $contrasenaTemporal ? $this->politica->generarTemporal($usuario->username) : null;

        $this->transaccion->ejecutar(function () use ($usuario, $temporal, $motivo, $operador): void {
            $teniaMfa = $usuario->dosFactoresHabilitado;
            $usuario->dosFactoresHabilitado = false;
            $usuario->dosFactoresMetodo = null;
            $usuario->dosFactoresSecreto = null;
            $this->usuarios->guardarDosFactores($usuario);
            $this->credenciales->borrarRecuperacion($usuario->id);
            $this->credenciales->invalidarOtps($usuario->id);
            $equipos = $this->equipos->forgetAll($usuario->id);
            $this->bloqueo->limpiar($usuario);
            $this->usuarios->guardarEstadoAcceso($usuario);
            $this->usuarios->guardarBloqueoAdministrativo($usuario->id, null, null);
            if (null !== $temporal) {
                $this->cambio->aplicar($usuario, $temporal, true);
                $this->cuentas->exigirCambioDeContrasena($usuario->id);
            }
            $this->gobierno->revocarTodo($usuario->id, Reloj::ahora()->modify('+1 year'));
            $this->gobierno->registrarAuditoria('EMERGENCY_ACCESS_RESET', 'INV_USUARIO', [
                'target_id' => Ident::aTexto($usuario->id), 'target_username' => $usuario->username,
                'motivo' => mb_substr($motivo, 0, 500), 'operador' => mb_substr($operador, 0, 120),
                'tenia_mfa' => $teniaMfa, 'equipos_olvidados' => $equipos, 'contrasena_temporal' => null !== $temporal,
            ], null, null, 'console');
        });
        if (null !== $temporal) {
            $persona = $this->usuarios->persona($usuario);
            $this->notificador->contrasenaCambiada($persona?->nombreCorto() ?? $usuario->username, $usuario->username, $persona?->correo, 'emergencia', null);
        }

        return $temporal;
    }
}
