<?php

declare(strict_types=1);

namespace App\Controller;

use App\Autenticacion\CambioContrasena;
use App\Autenticacion\DosFactores;
use App\Autenticacion\InicioSesion;
use App\Autenticacion\Sesiones;
use App\Config\Ajustes;
use App\Correo\Notificador;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Usuario;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Http\ErrorValidacion;
use App\Http\Limite;
use App\ProfilePhoto\Domain\ProfilePhoto;
use App\ProfilePhoto\Infrastructure\ProfilePhotoRepository;
use App\Repositorio\CredencialesRepositorio;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\SesionRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\Autenticado;
use App\Seguridad\BloqueoCuenta;
use App\Seguridad\CacheAuth;
use App\Seguridad\CodigosSeguridad;
use App\Seguridad\Contrasenas;
use App\Seguridad\PoliticaSesion;
use App\Seguridad\Tokens;
use App\Security\Mfa\TrustedDevices;
use App\Seguridad\UsuarioActual;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Autenticación y autoservicio de la cuenta. Contrato idéntico al backend
 * actual (rutas, códigos HTTP y cuerpos).
 */
#[Route('/api/v1')]
final readonly class LoginController
{
    private const string MENSAJE_RESET = 'Si la cuenta existe, se enviaron instrucciones al correo registrado.';

    public function __construct(
        private Connection $conexion,
        private UsuarioRepositorio $usuarios,
        private GobiernoRepositorio $gobierno,
        private SesionRepositorio $sesiones,
        private CredencialesRepositorio $credenciales,
        private BloqueoCuenta $bloqueo,
        private PoliticaSesion $politica,
        private Tokens $tokens,
        private InicioSesion $inicio,
        private Sesiones $cierre,
        private DosFactores $dosFactores,
        private CambioContrasena $cambio,
        private Notificador $notificador,
        private CacheAuth $cache,
        private Ajustes $ajustes,
        private TrustedDevices $equipos,
        private ProfilePhotoRepository $fotos,
    ) {
    }

    /** Login compatible con OAuth2 (formulario username/password), con bloqueo progresivo y auditoría. */
    #[Route('/login/access-token', methods: ['POST'])]
    #[Limite(ajuste: 'limiteLogin')]
    public function accessToken(Request $request): Response
    {
        [$ip, $ua] = [ClienteHttp::ip($request), ClienteHttp::userAgent($request)];
        $form = $request->request->all();
        ErrorValidacion::exigirCampos($form, 'username', 'password');
        if (isset($form['grant_type']) && 'password' !== $form['grant_type']) {
            throw new ErrorValidacion([[
                'type' => 'string_pattern_mismatch', 'loc' => ['body', 'grant_type'],
                'msg' => "String should match pattern '^password$'", 'input' => $form['grant_type'],
            ]]);
        }
        $username = (string) $form['username'];
        $password = (string) $form['password'];

        // Un nombre más largo que la columna (50) no puede existir: no se consulta la base.
        $usuario = mb_strlen($username) > 50 ? null : $this->usuarios->porUsername($username);
        // Usuario inexistente: mismo costo de CPU y mismo mensaje que una contraseña errónea.
        if (null === $usuario) {
            Contrasenas::verificarSenuelo();
            $this->gobierno->registrarAuditoria('LOGIN_FAILED', 'INV_USUARIO',
                ['username' => mb_substr($username, 0, 64), 'reason' => 'user_not_found'], null, $ip, $ua);
            throw new ErrorApi(400, 'INCORRECT_USERNAME_OR_PASSWORD');
        }

        $ahora = Reloj::ahora();
        $this->bloqueo->liberarSiVencido($usuario, $ahora);
        $bloqueada = $this->bloqueo->estaBloqueada($usuario, $ahora, $ip);
        // La contraseña se verifica ANTES de revelar el bloqueo: con una errónea la
        // respuesta es la de un usuario inexistente (no se enumeran cuentas bloqueándolas).
        $passwordOk = Contrasenas::verificar($password, $usuario->passwordHash);

        if ($bloqueada) {
            $this->falloSinIntento($usuario, 'account_locked', $ip, $ua);
            throw new ErrorApi($passwordOk ? 429 : 400, $passwordOk ? 'ACCOUNT_LOCKED' : 'INCORRECT_USERNAME_OR_PASSWORD');
        }
        if (!$passwordOk) {
            $motivo = null === $usuario->passwordHash || '' === $usuario->passwordHash ? 'password_login_disabled' : 'bad_password';
            $this->registrarFallo($usuario, $ahora, $motivo, 'password', $ip, $ua);
            throw new ErrorApi(400, 'INCORRECT_USERNAME_OR_PASSWORD');
        }
        if (!$usuario->activo) {
            $this->falloSinIntento($usuario, 'inactive_user', $ip, $ua);
            throw new ErrorApi(400, 'INACTIVE_USER');
        }

        // Contraseña correcta: rehash si toca (bcrypt → argon2id). Los intentos se limpian
        // solo al completar el login: con 2FA, limpiarlos aquí permitiría repetir la
        // contraseña para seguir probando códigos del segundo factor.
        if (Contrasenas::necesitaRehash((string) $usuario->passwordHash)) {
            $usuario->passwordHash = Contrasenas::hashear($password);
        }

        // Equipo recordado tras un código válido: la contraseña basta hasta que venza.
        $recordado = $usuario->dosFactoresHabilitado && $this->equipos->isTrusted($usuario, $request);
        if ($usuario->dosFactoresHabilitado && !$recordado) {
            $this->conexion->transactional(function () use ($usuario, $ip, $ua): void {
                $this->usuarios->guardarEstadoAcceso($usuario);
                $this->gobierno->registrarAuditoria('2FA_CHALLENGE', 'INV_USUARIO',
                    ['username' => $usuario->username, 'metodo' => $usuario->dosFactoresMetodo], $usuario->id, $ip, $ua);
            });
            if ('EMAIL' === $usuario->dosFactoresMetodo) {
                $this->dosFactores->emitirCodigoCorreo($usuario);
            }

            return InicioSesion::sinCache(new JsonResponse([
                'requires_2fa' => true,
                'method' => $usuario->dosFactoresMetodo,
                'challenge_token' => $this->tokens->crearDesafio2fa($usuario->username, $usuario->passwordHash),
                // Días que se puede recordar este equipo al ingresar el código (0 = no se ofrece).
                'remember_days' => $this->ajustes->mfaRecordarDias,
            ]));
        }

        $this->bloqueo->limpiar($usuario, $ip);
        $usuario->ultimoLogin = $ahora;
        $respuesta = new JsonResponse();
        $sesion = $this->conexion->transactional(function () use ($usuario, $ip, $ua, $respuesta, $recordado): array {
            $this->usuarios->guardarEstadoAcceso($usuario);
            $this->gobierno->registrarAuditoria('LOGIN_SUCCESS', 'INV_USUARIO',
                ['username' => $usuario->username, 'rol' => $usuario->rol, ...($recordado ? ['2fa' => 'trusted_device'] : [])],
                $usuario->id, $ip, $ua);

            return $this->inicio->iniciar($usuario, $this->politica->claims($usuario), $recordado ? '2fa' : 'password', $ip, $ua, $respuesta);
        });
        $this->inicio->avisarDispositivo($usuario, $sesion['dispositivo_nuevo'], $ip);

        return InicioSesion::sinCache($respuesta->setData($this->inicio->respuestaTokens($usuario, $sesion['claims'])));
    }

    /**
     * Segundo paso del login con 2FA: código TOTP o de correo, o un código de
     * recuperación, contra el desafío emitido tras la contraseña.
     */
    #[Route('/login/2fa/verify', methods: ['POST'])]
    #[Limite(ajuste: 'limiteLogin')]
    public function verificar2fa(Request $request): Response
    {
        [$ip, $ua] = [ClienteHttp::ip($request), ClienteHttp::userAgent($request)];
        $entrada = Entrada::json($request);
        $desafioToken = $entrada->texto('challenge_token', min: 10);
        $codigo = $entrada->texto('code', min: 4, max: 20);
        $recordar = true === $entrada->logico('remember_device');
        $entrada->validar();

        $invalido = new ErrorApi(401, 'INVALID_OR_EXPIRED_2FA_CHALLENGE');
        $desafio = $this->tokens->decodificarDesafio((string) $desafioToken) ?? throw $invalido;
        $usuario = $this->usuarios->porUsername((string) $desafio['sub']);
        if (null === $usuario || !$usuario->activo || !$usuario->dosFactoresHabilitado) {
            throw $invalido;
        }
        // El desafío sirve una sola vez y solo con la contraseña con que se emitió.
        if (!hash_equals($this->tokens->huellaPassword($usuario->passwordHash), (string) ($desafio['pwf'] ?? ''))
            || null !== $this->gobierno->revocadoEn((string) $desafio['jti'])) {
            throw $invalido;
        }
        $ahora = Reloj::ahora();
        if ($this->bloqueo->estaBloqueada($usuario, $ahora, $ip)) {
            throw new ErrorApi(429, 'ACCOUNT_LOCKED');
        }
        if ($this->dosFactores->totpYaUsado($usuario, (string) $codigo)) {
            // Un código válido ya usado (otra pestaña, doble clic) no suma al bloqueo.
            throw new ErrorApi(400, 'TOTP_ALREADY_USED');
        }
        if (!$this->dosFactores->verificarLogin($usuario, (string) $codigo)) {
            // Los códigos fallidos cuentan para el bloqueo igual que las contraseñas.
            $bloqueadaAhora = $this->registrarFallo($usuario, $ahora, 'bad_2fa_code', '2fa', $ip, $ua);
            throw $bloqueadaAhora ? new ErrorApi(429, 'ACCOUNT_LOCKED') : new ErrorApi(400, 'INVALID_2FA_CODE');
        }

        $viaSso = 'sso' === ($desafio['amr'] ?? null);
        $respuesta = new JsonResponse();
        $sesion = $this->conexion->transactional(function () use ($usuario, $desafio, $ahora, $viaSso, $ip, $ua, $respuesta, $invalido): array {
            // Dos verificaciones simultáneas con el mismo desafío: solo una abre sesión.
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

            return $this->inicio->iniciar($usuario, $this->politica->claims($usuario, $viaSso), $viaSso ? 'sso' : '2fa', $ip, $ua, $respuesta);
        });
        if ($recordar && $this->equipos->enabled()) {
            $this->conexion->transactional(function () use ($usuario, $respuesta, $ip, $ua): void {
                $this->equipos->remember($usuario, $respuesta, $ip, SesionRepositorio::etiquetaDispositivo($ua));
                $this->gobierno->registrarAuditoria('MFA_DEVICE_REMEMBERED', 'INV_USUARIO',
                    ['username' => $usuario->username, 'dias' => $this->ajustes->mfaRecordarDias], $usuario->id, $ip, $ua);
            });
        }
        $this->inicio->avisarDispositivo($usuario, $sesion['dispositivo_nuevo'], $ip);

        return InicioSesion::sinCache($respuesta->setData($this->inicio->respuestaTokens($usuario, $sesion['claims'])));
    }

    /**
     * Nuevo access token a partir del refresh (cuerpo o cookie HttpOnly), ROTANDO
     * el refresh de forma atómica. Reusar un refresh ya rotado fuera del margen
     * de gracia indica robo: se revocan todas las sesiones del usuario.
     */
    #[Route('/login/refresh', methods: ['POST'])]
    #[Limite(ajuste: 'limiteRefresh', clave: Limite::CLAVE_REFRESH)]
    public function refresh(Request $request): Response
    {
        [$ip, $ua] = [ClienteHttp::ip($request), ClienteHttp::userAgent($request)];
        $entrada = Entrada::json($request);
        $delCuerpo = $entrada->texto('refresh_token', opcional: true);
        $entrada->validar();
        $token = $delCuerpo ?: $request->cookies->get(InicioSesion::COOKIE_REFRESH);
        if (!\is_string($token) || '' === $token) {
            throw new ErrorApi(401, 'MISSING_REFRESH_TOKEN');
        }
        $payload = $this->tokens->decodificar($token, 'refresh');
        $jti = (string) ($payload['jti'] ?? '');
        if ('' === $jti) {
            throw new ErrorApi(401, 'COULD_NOT_VALIDATE_CREDENTIALS');
        }
        $usuario = $this->usuarios->porUsername((string) $payload['sub']);
        if (null === $usuario || !$usuario->activo) {
            throw new ErrorApi(401, 'USER_INACTIVE_OR_NOT_FOUND');
        }
        $ahora = Reloj::ahora();
        if ($usuario->bloqueoAdministrativoVigente($ahora)) {
            throw new ErrorApi(401, 'ACCOUNT_LOCKED');
        }
        $authTime = (int) ($payload['auth_time'] ?? $payload['iat'] ?? 0);
        if ($authTime > 0 && $ahora->getTimestamp() - $authTime > $this->ajustes->sesionMaxHoras * 3600 + 60) {
            throw new ErrorApi(401, 'SESSION_EXPIRED');
        }
        $emitido = Tokens::emitidoEn($payload);
        if (null !== $emitido && $this->gobierno->revocadoGlobalmente($usuario->id, $emitido)) {
            throw new ErrorApi(401, 'TOKEN_REVOKED');
        }
        // Sesión cerrada individualmente (o expirada): el refresh deja de valer.
        $sid = isset($payload['sid']) ? (string) $payload['sid'] : null;
        $sesion = null === $sid ? null : $this->sesiones->obtener($sid);
        if (null !== $sid && (!SesionRepositorio::activa($sesion) || $sesion['usuario'] !== $usuario->id)) {
            throw new ErrorApi(401, 'TOKEN_REVOKED');
        }

        $viaSso = 'sso' === ($payload['amr'] ?? null);
        $respuesta = new JsonResponse();
        $resultado = $this->conexion->transactional(function () use ($usuario, $payload, $jti, $sesion, $ahora, $authTime, $viaSso, $ip, $ua, $respuesta): ?array {
            if (!$this->gobierno->revocarJtiUnaVez($jti, 'refresh', Tokens::expira($payload), $usuario->id)) {
                $previa = $this->gobierno->revocadoEn($jti) ?? $ahora;
                if ($ahora->getTimestamp() - $previa->getTimestamp() > $this->ajustes->graciaReusoRefreshSegundos) {
                    $this->gobierno->revocarTodo($usuario->id, $ahora->modify('+'.($this->ajustes->refreshDias + 1).' days'));
                    $this->gobierno->registrarAuditoria('REFRESH_TOKEN_REUSE', 'INV_USUARIO',
                        ['username' => $usuario->username, 'jti' => $jti], $usuario->id, $ip, $ua);
                }

                return null;
            }
            // Token anterior al registro de sesiones: se registra ahora.
            $sesionId = $sesion['id'] ?? $this->sesiones->abrir($usuario->id, $ip, $ua, $viaSso ? 'sso' : 'password')['id'];
            // Con el inicio de la sesión: la que empezó antes de omitir el MFA sigue sin pedirlo.
            $claims = [...$this->politica->claims($usuario, $viaSso, $sesion['creada'] ?? null), 'sid' => Ident::aTexto($sesionId)];
            $nuevo = $this->tokens->crearRefresh($usuario->username, $usuario->rol, $authTime > 0 ? $authTime : null, $claims);
            $this->sesiones->tocar($sesionId, Tokens::jtiSinVerificar($nuevo), $ip);
            $this->inicio->fijarCookie($respuesta, $nuevo, $usuario->id);

            return $claims;
        });
        if (null === $resultado) {
            // Carrera perdida o refresh robado y reusado.
            throw new ErrorApi(401, 'TOKEN_REVOKED');
        }

        return InicioSesion::sinCache($respuesta->setData($this->inicio->respuestaTokens($usuario, $resultado)));
    }

    /**
     * Cierra la sesión actual: revoca el refresh (cuerpo o cookie) y el access del
     * header, y borra la cookie. No exige un access vigente (el cierre por
     * inactividad ocurre con el access ya expirado): cada token se valida por
     * firma antes de revocarlo. Idempotente: siempre 204.
     */
    #[Route('/login/logout', methods: ['POST'])]
    #[Limite('20/minute')]
    public function logout(Request $request): Response
    {
        [$ip, $ua] = [ClienteHttp::ip($request), ClienteHttp::userAgent($request)];
        $entrada = Entrada::json($request);
        $delCuerpo = $entrada->texto('refresh_token', opcional: true);
        $entrada->validar();

        $candidatos = [];
        $refresh = $delCuerpo ?: $request->cookies->get(InicioSesion::COOKIE_REFRESH);
        if (\is_string($refresh) && '' !== $refresh) {
            $candidatos[] = [$refresh, 'refresh'];
        }
        if (1 === preg_match('/^Bearer\s+(\S+)$/i', (string) $request->headers->get('Authorization', ''), $m)) {
            $candidatos[] = [$m[1], 'access'];
        }

        $revocados = $this->conexion->transactional(function () use ($candidatos, $ip, $ua): array {
            $usuario = null;
            $revocados = [];
            foreach ($candidatos as [$crudo, $tipo]) {
                $payload = $this->tokens->decodificarSinExpiracion($crudo, $tipo);
                if (null === $payload) {
                    continue;
                }
                $usuario ??= $this->usuarios->porUsername((string) $payload['sub']);
                if (null === $usuario || $usuario->username !== $payload['sub']) {
                    continue; // tokens de usuarios distintos: se ignora el segundo
                }
                $expira = Tokens::expira($payload);
                if ($expira > Reloj::ahora() && $this->gobierno->revocarJtiUnaVez((string) $payload['jti'], $tipo, $expira, $usuario->id)) {
                    $revocados[] = (string) $payload['jti'];
                }
                $sesion = isset($payload['sid']) ? $this->sesiones->obtener((string) $payload['sid']) : null;
                if (null !== $sesion && $sesion['usuario'] === $usuario->id) {
                    $this->cierre->cerrar($sesion['id'], $usuario->id, 'logout');
                }
            }
            if (null !== $usuario) {
                $this->gobierno->registrarAuditoria('LOGOUT', 'INV_USUARIO',
                    ['username' => $usuario->username, 'tokens_revocados' => \count($revocados)], $usuario->id, $ip, $ua);
            }

            return $revocados;
        });
        $this->cache->borrar(...array_map(static fn (string $j): string => "auth:jti:{$j}", $revocados));

        $respuesta = new Response(null, 204);
        $this->inicio->borrarCookie($respuesta);

        return $respuesta;
    }

    /** Cierra TODAS las sesiones del usuario en todos los dispositivos. */
    #[Route('/login/logout-all', methods: ['POST'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('5/minute')]
    public function logoutTodo(Request $request, UsuarioActual $actual): Response
    {
        $u = $actual->usuario();
        $this->conexion->transactional(function () use ($u, $request): void {
            $this->gobierno->revocarTodo($u->id, Reloj::ahora()->modify('+'.($this->ajustes->refreshDias + 1).' days'));
            $this->gobierno->registrarAuditoria('LOGOUT_ALL', 'INV_USUARIO', ['username' => $u->username], $u->id,
                ClienteHttp::ip($request), ClienteHttp::userAgent($request));
        });
        $respuesta = new Response(null, 204);
        $this->inicio->borrarCookie($respuesta);

        return $respuesta;
    }

    /**
     * Inicia el restablecimiento. Responde SIEMPRE lo mismo (no revela si la
     * cuenta existe). El token de un solo uso va únicamente al correo
     * corporativo registrado, que es la prueba de que la solicitud es del dueño.
     */
    #[Route('/login/password-reset/request', methods: ['POST'])]
    #[Limite(ajuste: 'limiteLogin')]
    public function solicitarRestablecimiento(Request $request): JsonResponse
    {
        $entrada = Entrada::json($request);
        $identificador = $entrada->texto('identifier', min: 1, max: 150);
        $entrada->validar();
        $generica = new JsonResponse(['message' => self::MENSAJE_RESET], 202);

        $usuario = $this->usuarios->porUsernameOCorreo(trim((string) $identificador));
        $persona = null !== $usuario && $usuario->activo ? $this->usuarios->persona($usuario) : null;
        if (null === $usuario || null === $persona || null === $persona->correo || '' === $persona->correo) {
            return $generica;
        }
        // Una cuenta solo SSO no obtiene contraseña por correo: quien controle el buzón esquivaría el
        // proveedor de identidad (fase 8). Misma respuesta genérica: no revela el tipo de cuenta.
        if ($usuario->ssoHabilitado && null === $usuario->passwordHash) {
            return $generica;
        }
        // Enfriamiento POR CUENTA: no se emite otro correo dentro de la ventana (anti-bombardeo).
        if ($this->credenciales->solicitudResetReciente($usuario->id, $this->ajustes->resetEnfriamientoMinutos)) {
            return $generica;
        }
        [$plano, $hash] = CodigosSeguridad::tokenRestablecimiento();
        $this->conexion->transactional(function () use ($usuario, $hash, $request): void {
            $this->credenciales->invalidarResets($usuario->id);
            $this->credenciales->crearReset($usuario->id, $hash, Reloj::ahora()->modify("+{$this->ajustes->resetExpiraMinutos} minutes"));
            $this->gobierno->registrarAuditoria('PASSWORD_RESET_REQUEST', 'INV_USUARIO', ['username' => $usuario->username],
                $usuario->id, ClienteHttp::ip($request), ClienteHttp::userAgent($request));
        });
        // Solo al correo del titular, nunca en copia: el token es un secreto suyo.
        $this->notificador->enviar('password_reset', [
            'persona_nombre' => $persona->nombreCorto(),
            'username' => $usuario->username,
            'token' => $plano,
            // En el fragmento (fase 8): el navegador no lo envía al servidor ni en el Referer.
            'reset_url' => $this->ajustes->urlFrontend.'/reset-password#token='.rawurlencode($plano),
            'minutos' => $this->ajustes->resetExpiraMinutos,
        ], [$persona->correo], false);

        return $generica;
    }

    /**
     * Completa el restablecimiento: token de un solo uso y vigente, política de
     * contraseñas, y revocación de TODOS los tokens del usuario.
     */
    #[Route('/login/password-reset/confirm', methods: ['POST'])]
    #[Limite(ajuste: 'limiteLogin')]
    public function confirmarRestablecimiento(Request $request): Response
    {
        $entrada = Entrada::json($request);
        $token = $entrada->texto('token', min: 8, max: 200);
        $nueva = $entrada->texto('new_password', min: 1);
        $entrada->validar();

        $invalido = new ErrorApi(400, 'INVALID_OR_EXPIRED_RESET_TOKEN');
        $hash = CodigosSeguridad::hashRestablecimiento(trim((string) $token));
        $usuarioId = $this->credenciales->resetVigente($hash) ?? throw $invalido;
        $usuario = $this->usuarios->porId($usuarioId);
        if (null === $usuario || !$usuario->activo) {
            throw $invalido;
        }
        // Se valida antes de consumir: una contraseña rechazada no gasta el enlace.
        $this->cambio->validar($usuario, (string) $nueva);
        $this->conexion->transactional(function () use ($usuario, $hash, $nueva, $request, $invalido): void {
            if (null === $this->credenciales->consumirReset($hash)) {
                throw $invalido; // otro uso simultáneo del mismo enlace
            }
            $this->cambio->aplicar($usuario, (string) $nueva, true);
            $this->gobierno->registrarAuditoria('PASSWORD_RESET', 'INV_USUARIO',
                ['username' => $usuario->username, 'via' => 'email_token'], $usuario->id,
                ClienteHttp::ip($request), ClienteHttp::userAgent($request));
        });
        $this->avisarContrasena($usuario, 'Restablecimiento por email', ClienteHttp::ip($request));

        return new Response(null, 204);
    }

    /** Información del usuario autenticado, incluido su alcance de datos. */
    #[Route('/me', methods: ['GET'])]
    #[Autenticado(permitirRestringida: true)]
    public function me(UsuarioActual $actual): JsonResponse
    {
        $u = $actual->usuario();

        return new JsonResponse([
            'username' => $u->username,
            'role' => $u->rol,
            'active' => $u->activo,
            'person_id' => Ident::aTexto($u->personaId),
            'scope' => ['global' => $u->esGlobal(), 'sedes' => $u->sedes],
            'foto_url' => ProfilePhoto::url('/api/v1/me/foto', $this->fotos->updatedAt($u->id)),
        ]);
    }

    /** Sesiones activas de la cuenta (la actual marcada con `actual`). */
    #[Route('/me/sessions', methods: ['GET'])]
    #[Autenticado(permitirRestringida: true)]
    public function misSesiones(UsuarioActual $actual): JsonResponse
    {
        $sid = $actual->payload()['sid'] ?? null;

        return new JsonResponse(array_map(
            static fn (array $s): array => SesionRepositorio::serializar($s, \is_string($sid) ? $sid : null),
            $this->sesiones->activas($actual->usuario()->id),
        ));
    }

    /** Cierra una sesión propia (p. ej. un equipo perdido o no reconocido). */
    #[Route('/me/sessions/{sessionId}', methods: ['DELETE'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('20/minute')]
    public function cerrarMiSesion(string $sessionId, Request $request, UsuarioActual $actual): Response
    {
        $u = $actual->usuario();
        $sesion = $this->sesiones->obtener($sessionId);
        if (null === $sesion || $sesion['usuario'] !== $u->id) {
            throw new ErrorApi(404, 'SESSION_NOT_FOUND');
        }
        $this->conexion->transactional(function () use ($sesion, $u, $sessionId, $request): void {
            if ($this->cierre->cerrar($sesion['id'], $u->id, 'cerrada_por_titular')) {
                $this->gobierno->registrarAuditoria('SESSION_CLOSED', 'SYS_SESION', [
                    'username' => $u->username, 'sesion' => $sessionId, 'dispositivo' => $sesion['dispositivo'], 'por' => 'titular',
                ], $u->id, ClienteHttp::ip($request), ClienteHttp::userAgent($request));
            }
        });

        return new Response(null, 204);
    }

    /**
     * Cambia la contraseña del usuario autenticado: re-valida la actual (un fallo
     * suma al bloqueo: un token robado no permite probar contraseñas), aplica la
     * política y el historial, y revoca TODOS sus tokens (debe volver a entrar).
     */
    #[Route('/me/password', methods: ['POST'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('5/minute')]
    public function cambiarMiContrasena(Request $request, UsuarioActual $actual): Response
    {
        $u = $actual->usuario();
        [$ip, $ua] = [ClienteHttp::ip($request), ClienteHttp::userAgent($request)];
        $entrada = Entrada::json($request);
        $actualPassword = $entrada->texto('current_password', min: 1);
        $nueva = $entrada->texto('new_password', min: 1);
        $entrada->validar();

        if (!Contrasenas::verificar((string) $actualPassword, $u->passwordHash)) {
            $this->registrarFallo($u, Reloj::ahora(), null, 'cambio_password', $ip, $ua);
            throw new ErrorApi(400, 'INCORRECT_CURRENT_PASSWORD');
        }
        $this->cambio->validar($u, (string) $nueva);
        $this->conexion->transactional(function () use ($u, $nueva, $ip, $ua): void {
            $this->cambio->aplicar($u, (string) $nueva, false);
            $this->gobierno->registrarAuditoria('PASSWORD_CHANGE', 'INV_USUARIO',
                ['username' => $u->username, 'self_service' => true], $u->id, $ip, $ua);
        });
        $this->avisarContrasena($u, 'Autoservicio (cambio manual)', $ip);

        return new Response(null, 204);
    }

    /**
     * Suma un intento fallido y, si con él se bloquea la cuenta, audita el
     * bloqueo con su escalada. `$motivo` null = sin evento LOGIN_FAILED (cambio
     * de contraseña). Devuelve true si la cuenta quedó bloqueada.
     */
    private function registrarFallo(Usuario $u, \DateTimeImmutable $ahora, ?string $motivo, string $origen, ?string $ip, ?string $ua): bool
    {
        return $this->conexion->transactional(function () use ($u, $ahora, $motivo, $origen, $ip, $ua): bool {
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

    /** Fallo de login que no suma intentos: persiste el estado y lo audita. */
    private function falloSinIntento(Usuario $u, string $motivo, ?string $ip, ?string $ua): void
    {
        $this->conexion->transactional(function () use ($u, $motivo, $ip, $ua): void {
            $this->usuarios->guardarEstadoAcceso($u);
            $this->gobierno->registrarAuditoria('LOGIN_FAILED', 'INV_USUARIO',
                ['username' => $u->username, 'reason' => $motivo], $u->id, $ip, $ua);
        });
    }

    private function avisarContrasena(Usuario $u, string $metodo, ?string $ip): void
    {
        $persona = $this->usuarios->persona($u);
        $this->notificador->contrasenaCambiada($persona?->nombreCorto() ?? $u->username, $u->username, $persona?->correo, $metodo, $ip);
    }
}
