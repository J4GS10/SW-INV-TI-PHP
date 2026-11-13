<?php

declare(strict_types=1);

namespace App\Autenticacion;

use App\Autenticacion\Domain\DesafioSegundoFactor;
use App\Autenticacion\Domain\SesionAbierta;
use App\Config\Ajustes;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Usuario;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\SesionRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Security\Mfa\TrustedDevices;
use App\Seguridad\BloqueoCuenta;
use App\Seguridad\Contrasenas;
use App\Seguridad\PoliticaSesion;
use App\Seguridad\Tokens;

/**
 * Getting into an account: with the password, with the second factor that follows it, and renewing the
 * session with its refresh token. It knows nothing of HTTP: it receives what the client sent and returns
 * either the open session or the challenge still to be answered.
 */
final readonly class AccesoCuenta
{
    /** The longest username the account table can hold: a longer one cannot exist and is not looked up. */
    private const int LARGO_USERNAME = 50;

    public function __construct(
        private Transaccion $transaccion,
        private UsuarioRepositorio $usuarios,
        private GobiernoRepositorio $gobierno,
        private SesionRepositorio $sesiones,
        private BloqueoCuenta $bloqueo,
        private PoliticaSesion $politica,
        private Tokens $tokens,
        private InicioSesion $inicio,
        private DosFactores $dosFactores,
        private IntentosFallidos $intentos,
        private TrustedDevices $equipos,
        private Ajustes $ajustes,
    ) {
    }

    /**
     * First step: username and password, with progressive lockout and audit. An unknown user, a wrong password
     * and a locked account answered with a wrong password are told apart by nobody outside: same cost, same message.
     *
     * @param string $marcaEquipo mark of a browser remembered after a second factor ('' if none): with a live one the password is enough
     */
    public function conContrasena(string $username, string $password, string $marcaEquipo, ?string $ip, ?string $ua): SesionAbierta|DesafioSegundoFactor
    {
        $usuario = mb_strlen($username) > self::LARGO_USERNAME ? null : $this->usuarios->porUsername($username);
        if (null === $usuario) {
            Contrasenas::verificarSenuelo();
            $this->gobierno->registrarAuditoria('LOGIN_FAILED', 'INV_USUARIO',
                ['username' => mb_substr($username, 0, 64), 'reason' => 'user_not_found'], null, $ip, $ua);
            throw ErrorDominio::invalido('INCORRECT_USERNAME_OR_PASSWORD');
        }

        $ahora = Reloj::ahora();
        $this->bloqueo->liberarSiVencido($usuario, $ahora);
        $bloqueada = $this->bloqueo->estaBloqueada($usuario, $ahora, $ip);
        // The password is checked BEFORE the lockout is revealed: with a wrong one the answer is that of an
        // unknown user (accounts cannot be enumerated by locking them).
        $passwordOk = Contrasenas::verificar($password, $usuario->passwordHash);

        if ($bloqueada) {
            $this->intentos->sinIntento($usuario, 'account_locked', $ip, $ua);
            throw $passwordOk ? ErrorDominio::demasiadosIntentos('ACCOUNT_LOCKED') : ErrorDominio::invalido('INCORRECT_USERNAME_OR_PASSWORD');
        }
        if (!$passwordOk) {
            $motivo = null === $usuario->passwordHash || '' === $usuario->passwordHash ? 'password_login_disabled' : 'bad_password';
            $this->intentos->registrar($usuario, $ahora, $motivo, 'password', $ip, $ua);
            throw ErrorDominio::invalido('INCORRECT_USERNAME_OR_PASSWORD');
        }
        if (!$usuario->activo) {
            $this->intentos->sinIntento($usuario, 'inactive_user', $ip, $ua);
            throw ErrorDominio::invalido('INACTIVE_USER');
        }

        // Right password: rehash when due (bcrypt to argon2id). The attempts are cleared only when the sign-in
        // is complete: with a second factor, clearing them here would let the password be repeated to keep
        // guessing codes.
        if (Contrasenas::necesitaRehash((string) $usuario->passwordHash)) {
            $usuario->passwordHash = Contrasenas::hashear($password);
        }

        $recordado = $usuario->dosFactoresHabilitado && $this->equipos->isTrusted($usuario, $marcaEquipo);
        if ($usuario->dosFactoresHabilitado && !$recordado) {
            return $this->desafiar($usuario, $ip, $ua);
        }

        $this->bloqueo->limpiar($usuario, $ip);
        $usuario->ultimoLogin = $ahora;
        $sesion = $this->transaccion->ejecutar(function () use ($usuario, $ip, $ua, $recordado): array {
            $this->usuarios->guardarEstadoAcceso($usuario);
            $this->gobierno->registrarAuditoria('LOGIN_SUCCESS', 'INV_USUARIO',
                ['username' => $usuario->username, 'rol' => $usuario->rol, ...($recordado ? ['2fa' => 'trusted_device'] : [])],
                $usuario->id, $ip, $ua);

            return $this->inicio->iniciar($usuario, $this->politica->claims($usuario), $recordado ? '2fa' : 'password', $ip, $ua);
        });
        $this->inicio->avisarDispositivo($usuario, $sesion['dispositivo_nuevo'], $ip);

        return new SesionAbierta($usuario->id, $this->inicio->respuestaTokens($usuario, $sesion['claims']), $sesion['refresh']);
    }

    /**
     * Second step: a TOTP or e-mail code, or a recovery code, against the challenge issued after the password.
     * The challenge works once and only with the password it was issued for; wrong codes count towards the lockout.
     *
     * @param bool $recordar the browser asks not to be challenged again for a while
     */
    public function conSegundoFactor(string $desafioToken, string $codigo, bool $recordar, ?string $ip, ?string $ua): SesionAbierta
    {
        $invalido = ErrorDominio::noAutenticado('INVALID_OR_EXPIRED_2FA_CHALLENGE');
        $desafio = $this->tokens->decodificarDesafio($desafioToken) ?? throw $invalido;
        $usuario = $this->usuarios->porUsername((string) $desafio['sub']);
        if (null === $usuario || !$usuario->activo || !$usuario->dosFactoresHabilitado) {
            throw $invalido;
        }
        if (!hash_equals($this->tokens->huellaPassword($usuario->passwordHash), (string) ($desafio['pwf'] ?? ''))
            || null !== $this->gobierno->revocadoEn((string) $desafio['jti'])) {
            throw $invalido;
        }
        $ahora = Reloj::ahora();
        if ($this->bloqueo->estaBloqueada($usuario, $ahora, $ip)) {
            throw ErrorDominio::demasiadosIntentos('ACCOUNT_LOCKED');
        }
        if ($this->dosFactores->totpYaUsado($usuario, $codigo)) {
            // A valid code already used (another tab, a double click) does not count towards the lockout.
            throw ErrorDominio::invalido('TOTP_ALREADY_USED');
        }
        if (!$this->dosFactores->verificarLogin($usuario, $codigo)) {
            throw $this->intentos->registrar($usuario, $ahora, 'bad_2fa_code', '2fa', $ip, $ua)
                ? ErrorDominio::demasiadosIntentos('ACCOUNT_LOCKED') : ErrorDominio::invalido('INVALID_2FA_CODE');
        }

        $viaSso = 'sso' === ($desafio['amr'] ?? null);
        $sesion = $this->transaccion->ejecutar(function () use ($usuario, $desafio, $ahora, $viaSso, $ip, $ua, $invalido): array {
            // Two verifications at once with the same challenge: only one opens a session.
            if (!$this->gobierno->revocarJtiUnaVez((string) $desafio['jti'], '2fa', Tokens::expira($desafio), $usuario->id)) {
                throw $invalido;
            }
            $this->bloqueo->limpiar($usuario, $ip);
            $usuario->ultimoLogin = $ahora;
            $this->usuarios->guardarEstadoAcceso($usuario);
            $this->gobierno->registrarAuditoria('LOGIN_SUCCESS', 'INV_USUARIO', [
                'username' => $usuario->username, 'rol' => $usuario->rol, '2fa' => $usuario->dosFactoresMetodo,
                ...($viaSso ? ['sso' => true] : []),
            ], $usuario->id, $ip, $ua);

            return $this->inicio->iniciar($usuario, $this->politica->claims($usuario, $viaSso), $viaSso ? 'sso' : '2fa', $ip, $ua);
        });
        $marca = $recordar ? $this->recordarEquipo($usuario, $ip, $ua) : null;
        $this->inicio->avisarDispositivo($usuario, $sesion['dispositivo_nuevo'], $ip);

        return new SesionAbierta($usuario->id, $this->inicio->respuestaTokens($usuario, $sesion['claims']), $sesion['refresh'],
            $marca['token'] ?? null, $marca['expires'] ?? null);
    }

    /**
     * A new access token from the refresh, ROTATING the refresh atomically. A refresh reused after it was
     * rotated, beyond the grace margin, means theft: every session of the user is revoked.
     */
    public function renovar(string $refresh, ?string $ip, ?string $ua): SesionAbierta
    {
        if ('' === $refresh) {
            throw ErrorDominio::noAutenticado('MISSING_REFRESH_TOKEN');
        }
        $payload = $this->tokens->decodificar($refresh, 'refresh');
        $jti = (string) ($payload['jti'] ?? '');
        if ('' === $jti) {
            throw ErrorDominio::noAutenticado();
        }
        $usuario = $this->usuarios->porUsername((string) $payload['sub']);
        if (null === $usuario || !$usuario->activo) {
            throw ErrorDominio::noAutenticado('USER_INACTIVE_OR_NOT_FOUND');
        }
        $ahora = Reloj::ahora();
        if ($usuario->bloqueoAdministrativoVigente($ahora)) {
            throw ErrorDominio::noAutenticado('ACCOUNT_LOCKED');
        }
        $authTime = (int) ($payload['auth_time'] ?? $payload['iat'] ?? 0);
        if ($authTime > 0 && $ahora->getTimestamp() - $authTime > $this->ajustes->sesionMaxHoras * 3600 + 60) {
            throw ErrorDominio::noAutenticado('SESSION_EXPIRED');
        }
        $emitido = Tokens::emitidoEn($payload);
        if (null !== $emitido && $this->gobierno->revocadoGlobalmente($usuario->id, $emitido)) {
            throw ErrorDominio::noAutenticado('TOKEN_REVOKED');
        }
        // A session closed on its own (or expired): its refresh is worth nothing.
        $sid = isset($payload['sid']) ? (string) $payload['sid'] : null;
        $sesion = null === $sid ? null : $this->sesiones->obtener($sid);
        if (null !== $sid && (!SesionRepositorio::activa($sesion) || $sesion['usuario'] !== $usuario->id)) {
            throw ErrorDominio::noAutenticado('TOKEN_REVOKED');
        }

        $viaSso = 'sso' === ($payload['amr'] ?? null);
        $renovada = $this->transaccion->ejecutar(function () use ($usuario, $payload, $jti, $sesion, $ahora, $authTime, $viaSso, $ip, $ua): ?array {
            if (!$this->gobierno->revocarJtiUnaVez($jti, 'refresh', Tokens::expira($payload), $usuario->id)) {
                $previa = $this->gobierno->revocadoEn($jti) ?? $ahora;
                if ($ahora->getTimestamp() - $previa->getTimestamp() > $this->ajustes->graciaReusoRefreshSegundos) {
                    $this->gobierno->revocarTodo($usuario->id, $ahora->modify('+'.($this->ajustes->refreshDias + 1).' days'));
                    $this->gobierno->registrarAuditoria('REFRESH_TOKEN_REUSE', 'INV_USUARIO',
                        ['username' => $usuario->username, 'jti' => $jti], $usuario->id, $ip, $ua);
                }

                return null;
            }
            // A token older than the session register: it is registered now.
            $sesionId = $sesion['id'] ?? $this->sesiones->abrir($usuario->id, $ip, $ua, $viaSso ? 'sso' : 'password')['id'];
            // With the start of the session: one that began before the second factor was waived still does not ask for it.
            $claims = [...$this->politica->claims($usuario, $viaSso, $sesion['creada'] ?? null), 'sid' => Ident::aTexto($sesionId)];
            $nuevo = $this->tokens->crearRefresh($usuario->username, $usuario->rol, $authTime > 0 ? $authTime : null, $claims);
            $this->sesiones->tocar($sesionId, Tokens::jtiSinVerificar($nuevo), $ip);

            return ['claims' => $claims, 'refresh' => $nuevo];
        });
        if (null === $renovada) {
            // A lost race, or a stolen refresh used again.
            throw ErrorDominio::noAutenticado('TOKEN_REVOKED');
        }

        return new SesionAbierta($usuario->id, $this->inicio->respuestaTokens($usuario, $renovada['claims']), $renovada['refresh']);
    }

    private function desafiar(Usuario $usuario, ?string $ip, ?string $ua): DesafioSegundoFactor
    {
        $this->transaccion->ejecutar(function () use ($usuario, $ip, $ua): void {
            $this->usuarios->guardarEstadoAcceso($usuario);
            $this->gobierno->registrarAuditoria('2FA_CHALLENGE', 'INV_USUARIO',
                ['username' => $usuario->username, 'metodo' => $usuario->dosFactoresMetodo], $usuario->id, $ip, $ua);
        });
        if ('EMAIL' === $usuario->dosFactoresMetodo) {
            $this->dosFactores->emitirCodigoCorreo($usuario);
        }

        return new DesafioSegundoFactor($usuario->dosFactoresMetodo, $this->tokens->crearDesafio2fa($usuario->username, $usuario->passwordHash),
            $this->ajustes->mfaRecordarDias);
    }

    /** @return array{token: string, expires: \DateTimeImmutable}|null */
    private function recordarEquipo(Usuario $usuario, ?string $ip, ?string $ua): ?array
    {
        if (!$this->equipos->enabled()) {
            return null;
        }

        return $this->transaccion->ejecutar(function () use ($usuario, $ip, $ua): ?array {
            $marca = $this->equipos->remember($usuario, $ip, SesionRepositorio::etiquetaDispositivo($ua));
            $this->gobierno->registrarAuditoria('MFA_DEVICE_REMEMBERED', 'INV_USUARIO',
                ['username' => $usuario->username, 'dias' => $this->ajustes->mfaRecordarDias], $usuario->id, $ip, $ua);

            return $marca;
        });
    }
}
