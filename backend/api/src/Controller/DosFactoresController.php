<?php

declare(strict_types=1);

namespace App\Controller;

use App\Autenticacion\DosFactores;
use App\Dominio\Reloj;
use App\Dominio\Usuario;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Http\Limite;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\Autenticado;
use App\Seguridad\BloqueoCuenta;
use App\Seguridad\Contrasenas;
use App\Seguridad\UsuarioActual;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Gestión del segundo factor del usuario autenticado (autoservicio). */
#[Route('/api/v1/me/2fa')]
final readonly class DosFactoresController
{
    public function __construct(
        private DosFactores $dosFactores,
        private BloqueoCuenta $bloqueo,
        private UsuarioRepositorio $usuarios,
        private GobiernoRepositorio $gobierno,
        private Connection $conexion,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[Autenticado(permitirRestringida: true)]
    public function estado(UsuarioActual $actual): JsonResponse
    {
        return new JsonResponse($this->dosFactores->estado($actual->usuario()));
    }

    /** Genera el secreto TOTP y el QR para enrolar la app autenticadora. */
    #[Route('/totp/setup', methods: ['POST'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('10/minute')]
    public function iniciarTotp(UsuarioActual $actual): Response
    {
        return self::sinCache(new JsonResponse($this->dosFactores->iniciarTotp($actual->usuario())));
    }

    /** Confirma el código de la app y activa el TOTP. Los códigos de recuperación se muestran una sola vez. */
    #[Route('/totp/activate', methods: ['POST'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('10/minute')]
    public function activarTotp(Request $request, UsuarioActual $actual): Response
    {
        $codigos = $this->dosFactores->activarTotp($actual->usuario(), $this->codigo($request),
            ClienteHttp::ip($request), ClienteHttp::userAgent($request));

        return self::sinCache(new JsonResponse(['recovery_codes' => $codigos]));
    }

    /** Envía un código de prueba al correo del usuario para enrolar el método EMAIL. */
    #[Route('/email/setup', methods: ['POST'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('5/minute')]
    public function iniciarCorreo(UsuarioActual $actual): JsonResponse
    {
        $this->dosFactores->iniciarCorreo($actual->usuario());

        return new JsonResponse(['message' => 'Código enviado al correo registrado.'], 202);
    }

    #[Route('/email/activate', methods: ['POST'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('10/minute')]
    public function activarCorreo(Request $request, UsuarioActual $actual): Response
    {
        $codigos = $this->dosFactores->activarCorreo($actual->usuario(), $this->codigo($request),
            ClienteHttp::ip($request), ClienteHttp::userAgent($request));

        return self::sinCache(new JsonResponse(['recovery_codes' => $codigos]));
    }

    /**
     * Desactiva el 2FA (exige la contraseña; bloqueado si el rol lo exige). Una
     * contraseña errónea suma al bloqueo de la cuenta, como en el cambio de
     * contraseña: un token robado no sirve para probar contraseñas.
     */
    #[Route('/disable', methods: ['POST'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('5/minute')]
    public function desactivar(Request $request, UsuarioActual $actual): Response
    {
        $u = $actual->usuario();
        [$ip, $ua] = [ClienteHttp::ip($request), ClienteHttp::userAgent($request)];
        $entrada = Entrada::json($request);
        $password = $entrada->texto('password', min: 1);
        $entrada->validar();

        $correcta = Contrasenas::verificar((string) $password, $u->passwordHash);
        if (!$correcta) {
            $this->falloDeReautenticacion($u, '2fa_disable', $ip, $ua);
        }
        $this->dosFactores->desactivar($u, $correcta, $ip, $ua);

        return new Response(null, 204);
    }

    /**
     * Códigos de recuperación nuevos: exige volver a autenticarse (fase 8), con la
     * contraseña o, en una cuenta solo SSO, con un código del segundo factor. Un fallo
     * suma al bloqueo: un token robado no sirve para obtener códigos ni para probar claves.
     */
    #[Route('/recovery-codes/regenerate', methods: ['POST'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('5/minute')]
    public function regenerar(Request $request, UsuarioActual $actual): Response
    {
        $u = $actual->usuario();
        [$ip, $ua] = [ClienteHttp::ip($request), ClienteHttp::userAgent($request)];
        $soloSso = null === $u->passwordHash || '' === $u->passwordHash;
        $entrada = Entrada::json($request);
        $secreto = $soloSso ? $entrada->texto('code', min: 4, max: 20) : $entrada->texto('password', min: 1);
        $entrada->validar();

        if (!$u->dosFactoresHabilitado) {
            throw new ErrorApi(400, '2FA_NOT_ENABLED');
        }
        $correcta = $soloSso ? $this->dosFactores->verificarLogin($u, (string) $secreto) : Contrasenas::verificar((string) $secreto, $u->passwordHash);
        if (!$correcta) {
            $this->falloDeReautenticacion($u, '2fa_recovery_regenerate', $ip, $ua);
            throw new ErrorApi(400, $soloSso ? 'INVALID_2FA_CODE' : 'INCORRECT_CURRENT_PASSWORD');
        }
        $codigos = $this->dosFactores->regenerarRecuperacion($u, $ip, $ua);

        return self::sinCache(new JsonResponse(['recovery_codes' => $codigos]));
    }

    /** Una reautenticación fallida suma al bloqueo de la cuenta, como en el login. */
    private function falloDeReautenticacion(Usuario $u, string $origen, ?string $ip, ?string $ua): void
    {
        $this->conexion->transactional(function () use ($u, $origen, $ip, $ua): void {
            if ($this->bloqueo->registrarFallo($u, Reloj::ahora(), $ip)) {
                $this->gobierno->registrarAuditoria('ACCOUNT_LOCKED', 'INV_USUARIO', [
                    'username' => $u->username, 'origen' => $origen, 'intentos' => $u->intentosFallidos,
                    ...$this->bloqueo->detalle($u, $ip),
                ], $u->id, $ip, $ua);
            }
            $this->usuarios->guardarEstadoAcceso($u);
        });
    }

    private function codigo(Request $request): string
    {
        $entrada = Entrada::json($request);
        $codigo = $entrada->texto('code', min: 4, max: 10);
        $entrada->validar();

        return (string) $codigo;
    }

    /** Secretos y códigos de recuperación no se guardan en cachés intermedias. */
    private static function sinCache(Response $respuesta): Response
    {
        $respuesta->headers->set('Cache-Control', 'no-store');

        return $respuesta;
    }
}
