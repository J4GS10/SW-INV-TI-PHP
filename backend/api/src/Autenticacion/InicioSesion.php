<?php

declare(strict_types=1);

namespace App\Autenticacion;

use App\Config\Ajustes;
use App\Correo\Notificador;
use App\Dominio\Ident;
use App\Dominio\Usuario;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\SesionRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\PoliticaSesion;
use App\Seguridad\Tokens;

/**
 * Apertura de una sesión tras un login completo (contraseña, 2FA o SSO): registro en SYS_SESION,
 * emisión del refresh, detección de dispositivo nuevo y cuerpo con el access token. No conoce HTTP:
 * devuelve el refresh y la capa de entrega lo pone en su cookie (App\Http\CookiesSesion).
 */
final readonly class InicioSesion
{
    public function __construct(
        private SesionRepositorio $sesiones,
        private GobiernoRepositorio $gobierno,
        private UsuarioRepositorio $usuarios,
        private Tokens $tokens,
        private Notificador $notificador,
        private Ajustes $ajustes,
    ) {
    }

    /**
     * Registra la sesión y emite su refresh. Llamar DENTRO de la transacción del
     * login; el aviso de dispositivo nuevo se envía con `avisarDispositivo()`
     * después del commit.
     *
     * @param array<string, mixed> $claims
     *
     * @return array{claims: array<string, mixed>, refresh: string, dispositivo_nuevo: ?string}
     */
    public function iniciar(Usuario $usuario, array $claims, string $metodo, ?string $ip, ?string $ua): array
    {
        $sesion = $this->sesiones->abrir($usuario->id, $ip, $ua, $metodo);
        $claims = [...$claims, 'sid' => Ident::aTexto($sesion['id'])];
        $refresh = $this->tokens->crearRefresh($usuario->username, $usuario->rol, extra: $claims);
        $this->sesiones->fijarRefreshJti($sesion['id'], Tokens::jtiSinVerificar($refresh));
        if ($sesion['nuevo_dispositivo']) {
            $this->gobierno->registrarAuditoria('LOGIN_NEW_DEVICE', 'INV_USUARIO',
                ['username' => $usuario->username, 'dispositivo' => $sesion['dispositivo']], $usuario->id, $ip, $ua);
        }

        return ['claims' => $claims, 'refresh' => $refresh, 'dispositivo_nuevo' => $sesion['nuevo_dispositivo'] ? $sesion['dispositivo'] : null];
    }

    /** Aviso al titular de un inicio de sesión desde un dispositivo no visto (post-commit). */
    public function avisarDispositivo(Usuario $usuario, ?string $dispositivo, ?string $ip): void
    {
        if (null === $dispositivo || !$this->ajustes->avisoDispositivoNuevo) {
            return;
        }
        $persona = $this->usuarios->persona($usuario);
        $this->notificador->eventoSeguridad('nuevo_dispositivo', $persona?->nombreCorto() ?? $usuario->username,
            $usuario->username, $persona?->correo, ['dispositivo' => $dispositivo, 'ip' => $ip ?? '']);
    }

    /**
     * Cuerpo de login/refresh. `restriction` indica un paso de seguridad pendiente.
     *
     * @param array<string, mixed> $claims
     *
     * @return array{access_token: string, token_type: string, expires_in: int, restriction: ?string}
     */
    public function respuestaTokens(Usuario $usuario, array $claims): array
    {
        return [
            'access_token' => $this->tokens->crearAcceso($usuario->username, $usuario->rol, $claims),
            'token_type' => 'bearer',
            'expires_in' => $this->ajustes->accesoMinutos * 60,
            'restriction' => PoliticaSesion::restriccion($claims),
        ];
    }
}
