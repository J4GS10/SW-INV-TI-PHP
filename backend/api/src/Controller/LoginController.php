<?php

declare(strict_types=1);

namespace App\Controller;

use App\Autenticacion\AccesoCuenta;
use App\Autenticacion\AutoservicioContrasena;
use App\Autenticacion\CierreDeSesion;
use App\Autenticacion\Domain\DesafioSegundoFactor;
use App\Autenticacion\Domain\SesionAbierta;
use App\Dominio\Ident;
use App\Http\ClienteHttp;
use App\Http\CookiesSesion;
use App\Http\Entrada;
use App\Http\ErrorValidacion;
use App\Http\Limite;
use App\ProfilePhoto\Domain\ProfilePhoto;
use App\ProfilePhoto\Infrastructure\ProfilePhotoRepository;
use App\Repositorio\SesionRepositorio;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
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
        private AccesoCuenta $acceso,
        private CierreDeSesion $cierreDeSesion,
        private AutoservicioContrasena $contrasenas,
        private CookiesSesion $cookies,
        private SesionRepositorio $sesiones,
        private ProfilePhotoRepository $fotos,
    ) {
    }

    /** Login compatible con OAuth2 (formulario username/password), con bloqueo progresivo y auditoría. */
    #[Route('/login/access-token', methods: ['POST'])]
    #[Limite(ajuste: 'limiteLogin')]
    public function accessToken(Request $request): Response
    {
        $form = $request->request->all();
        ErrorValidacion::exigirCampos($form, 'username', 'password');
        if (isset($form['grant_type']) && 'password' !== $form['grant_type']) {
            throw new ErrorValidacion([[
                'type' => 'string_pattern_mismatch', 'loc' => ['body', 'grant_type'],
                'msg' => "String should match pattern '^password$'", 'input' => $form['grant_type'],
            ]]);
        }
        $resultado = $this->acceso->conContrasena((string) $form['username'], (string) $form['password'], CookiesSesion::equipo($request),
            ClienteHttp::ip($request), ClienteHttp::userAgent($request));
        if ($resultado instanceof DesafioSegundoFactor) {
            return CookiesSesion::sinCache(new JsonResponse([
                'requires_2fa' => true,
                'method' => $resultado->metodo,
                'challenge_token' => $resultado->token,
                // Días que se puede recordar este equipo al ingresar el código (0 = no se ofrece).
                'remember_days' => $resultado->diasRecordar,
            ]));
        }

        return $this->conSesion($resultado);
    }

    /**
     * Segundo paso del login con 2FA: código TOTP o de correo, o un código de
     * recuperación, contra el desafío emitido tras la contraseña.
     */
    #[Route('/login/2fa/verify', methods: ['POST'])]
    #[Limite(ajuste: 'limiteLogin')]
    public function verificar2fa(Request $request): Response
    {
        $entrada = Entrada::json($request);
        $desafioToken = $entrada->texto('challenge_token', min: 10);
        $codigo = $entrada->texto('code', min: 4, max: 20);
        $recordar = true === $entrada->logico('remember_device');
        $entrada->validar();

        return $this->conSesion($this->acceso->conSegundoFactor((string) $desafioToken, (string) $codigo, $recordar,
            ClienteHttp::ip($request), ClienteHttp::userAgent($request)));
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
        $entrada = Entrada::json($request);
        $delCuerpo = $entrada->texto('refresh_token', opcional: true);
        $entrada->validar();

        return $this->conSesion($this->acceso->renovar($delCuerpo ?: CookiesSesion::refresh($request),
            ClienteHttp::ip($request), ClienteHttp::userAgent($request)));
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
        $refresh = $delCuerpo ?: CookiesSesion::refresh($request);
        if ('' !== $refresh) {
            $candidatos[] = [$refresh, 'refresh'];
        }
        if (1 === preg_match('/^Bearer\s+(\S+)$/i', (string) $request->headers->get('Authorization', ''), $m)) {
            $candidatos[] = [$m[1], 'access'];
        }

        $this->cierreDeSesion->actual($candidatos, $ip, $ua);

        $respuesta = new Response(null, 204);
        $this->cookies->cerrar($respuesta);

        return $respuesta;
    }

    /** Cierra TODAS las sesiones del usuario en todos los dispositivos. */
    #[Route('/login/logout-all', methods: ['POST'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('5/minute')]
    public function logoutTodo(Request $request, UsuarioActual $actual): Response
    {
        $u = $actual->usuario();
        $this->cierreDeSesion->todas($u, ClienteHttp::ip($request), ClienteHttp::userAgent($request));
        $respuesta = new Response(null, 204);
        $this->cookies->cerrar($respuesta);

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
        $this->contrasenas->solicitarRestablecimiento((string) $identificador, ClienteHttp::ip($request), ClienteHttp::userAgent($request));

        // Siempre la misma respuesta: no revela si la cuenta existe ni de qué tipo es.
        return new JsonResponse(['message' => self::MENSAJE_RESET], 202);
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
        $this->contrasenas->confirmarRestablecimiento((string) $token, (string) $nueva, ClienteHttp::ip($request), ClienteHttp::userAgent($request));

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
        $this->cierreDeSesion->propia($actual->usuario(), $sessionId, ClienteHttp::ip($request), ClienteHttp::userAgent($request));

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
        $entrada = Entrada::json($request);
        $actualPassword = $entrada->texto('current_password', min: 1);
        $nueva = $entrada->texto('new_password', min: 1);
        $entrada->validar();
        $this->contrasenas->cambiar($actual->usuario(), (string) $actualPassword, (string) $nueva, ClienteHttp::ip($request), ClienteHttp::userAgent($request));

        return new Response(null, 204);
    }

    /** La respuesta de una sesión abierta o renovada: el access en el cuerpo y el refresh (y la marca del equipo) en sus cookies. */
    private function conSesion(SesionAbierta $sesion): Response
    {
        $respuesta = new JsonResponse($sesion->cuerpo);
        $this->cookies->abrir($respuesta, $sesion->refresh, $sesion->usuarioId);
        if (null !== $sesion->marcaEquipo && null !== $sesion->marcaExpira) {
            $this->cookies->recordarEquipo($respuesta, $sesion->marcaEquipo, $sesion->marcaExpira);
        }

        return CookiesSesion::sinCache($respuesta);
    }
}
