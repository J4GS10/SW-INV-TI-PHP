<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Directorio\ErrorDirectorio;
use App\Directorio\FabricaDirectorio;
use App\Directorio\GrupoDirectorio;
use App\Directorio\SincronizacionDirectorio;
use App\Dominio\Ident;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Http\Limite;
use App\Integracion\FormularioIntegracion;
use App\Integracion\FuenteIntegracion;
use App\Integracion\ServicioIntegracion;
use App\Notificaciones\Destinatario;
use App\Notificaciones\ReglaNotificacion;
use App\Notificaciones\Infrastructure\ReglasNotificacion;
use App\Notificaciones\ResolutorDestinatarios;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use App\Repositorio\ConsultasComunes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Active Directory (estado, prueba, sincronización, grupos), cuenta de
 * servicio de correo y AD, y reglas de notificación.
 */
#[Route('/api/v1/directorio')]
final readonly class DirectorioController
{
    private const int MAX_GRUPOS_REGLA = 20;
    private const int MAX_CORREOS_REGLA = 30;

    public function __construct(
        private FuenteIntegracion $fuente,
        private FabricaDirectorio $directorios,
        private SincronizacionDirectorio $sincronizacion,
        private ServicioIntegracion $integracion,
        private ReglasNotificacion $reglas,
        private ResolutorDestinatarios $resolutor,
        private UsuarioActual $actual,
        private ConsultasComunes $consultas,
    ) {
    }

    // ================= ACTIVE DIRECTORY =================

    #[Route('/estado', methods: ['GET'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function estado(): JsonResponse
    {
        $ad = $this->fuente->obtener()->ad;

        return new JsonResponse([
            'enabled' => $ad->habilitado,
            'configured' => $ad->configurada(),
            'server' => [] === $ad->servidores ? null : implode(',', $ad->servidores),
            'base_dn' => '' === $ad->baseDn ? null : $ad->baseDn,
            'admin_group' => '' === $ad->grupoAdmin ? null : $ad->grupoAdmin,
            'sync_interval_minutes' => $ad->intervaloMinutos,
            'last_sync' => $this->sincronizacion->ultima(),
        ]);
    }

    /** Bind y conteo de usuarios con la configuración guardada (respeta el cortacircuitos). */
    #[Route('/probar', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    #[Limite('10/minute')]
    public function probar(): JsonResponse
    {
        $directorio = $this->directorios->activo();
        if (null === $directorio) {
            return new JsonResponse(['ok' => false, 'message' => 'AD_DISABLED', 'usuarios_encontrados' => 0]);
        }
        try {
            $total = $directorio->probarConexion();
        } catch (ErrorDirectorio $e) {
            return new JsonResponse(['ok' => false, 'message' => $e->getMessage(), 'usuarios_encontrados' => 0]);
        }

        return new JsonResponse(['ok' => true, 'message' => 'OK', 'usuarios_encontrados' => $total]);
    }

    /** `dry_run`: calcula sin guardar. `forzar_bajas`: confirma una desactivación masiva retenida. */
    #[Route('/sincronizar', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    #[Limite('6/minute')]
    public function sincronizar(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        $simulacion = $e->campo(Campo::logico('dry_run'), false)[1];
        $forzar = $e->campo(Campo::logico('forzar_bajas'), false)[1];
        $e->validar();

        return new JsonResponse($this->sincronizacion->sincronizar((bool) $simulacion, $this->actual->usuario()->id,
            ClienteHttp::ip($request), (bool) $forzar));
    }

    /** Autocompletar de grupos; si el AD no está disponible, lista vacía. */
    #[Route('/grupos', methods: ['GET'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function grupos(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        $texto = $e->texto('q', max: 100, opcional: true) ?? '';
        $e->validar();
        $directorio = $this->directorios->activo();
        if (null === $directorio) {
            return new JsonResponse([]);
        }
        try {
            return new JsonResponse(array_map(static fn (GrupoDirectorio $g): array => $g->aApi(), $directorio->buscarGrupos($texto)));
        } catch (ErrorDirectorio) {
            return new JsonResponse([]);
        }
    }

    // ================= CUENTA DE SERVICIO (CORREO + AD) =================

    /** Configuración de la cuenta de servicio. Nunca devuelve secretos. */
    #[Route('/integracion', methods: ['GET'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function integracion(): JsonResponse
    {
        return new JsonResponse($this->integracion->obtener());
    }

    #[Route('/integracion', methods: ['PUT'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function guardarIntegracion(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $datos = FormularioIntegracion::leerYComprobar($e);
        $e->validar();

        return new JsonResponse($this->integracion->guardar($datos, $this->actual->usuario()->id, ClienteHttp::ip($request)));
    }

    /** Envía un correo real con los valores del formulario (sin guardarlos). */
    #[Route('/integracion/probar-correo', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    #[Limite('5/minute')]
    public function probarCorreo(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $config = self::configuracion($e);
        [, $destinatario] = $e->campo(new Campo('destinatario', Campo::CORREO, requerido: true), true);
        $e->validar();

        return new JsonResponse($this->integracion->probarCorreo($config, (string) $destinatario));
    }

    /** Bind y búsqueda de usuarios con los valores del formulario (sin guardarlos). */
    #[Route('/integracion/probar-ad', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    #[Limite('5/minute')]
    public function probarAd(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $config = self::configuracion($e);
        $e->validar();

        return new JsonResponse($this->integracion->probarAd($config));
    }

    /** Reanuda envíos y consultas pausados por un fallo de autenticación. */
    #[Route('/integracion/desbloquear', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function desbloquear(): Response
    {
        $this->integracion->desbloquear();

        return new Response(null, 204);
    }

    // ================= REGLAS DE NOTIFICACIÓN =================

    #[Route('/notificaciones/reglas', methods: ['GET'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function reglas(): JsonResponse
    {
        return new JsonResponse(array_map(static fn (ReglaNotificacion $r): array => $r->aApi(), $this->reglas->listar()));
    }

    #[Route('/notificaciones/reglas/{evento}', methods: ['PUT'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function guardarRegla(Request $request, string $evento): JsonResponse
    {
        $e = Entrada::json($request);
        $datos = [];
        foreach (['activa' => true, 'notificar_afectado' => true, 'notificar_jefe' => false, 'copiar_admins' => true] as $campo => $defecto) {
            $datos[$campo] = self::logico($e, $campo, $defecto);
        }
        $datos['grupos_ad'] = $e->textos('grupos_ad', self::MAX_GRUPOS_REGLA) ?? [];
        $datos['correos_extra'] = $e->correos('correos_extra', self::MAX_CORREOS_REGLA) ?? [];
        foreach (['grupos_ad', 'correos_extra'] as $lista) {
            if ($e->presente($lista) && null === $e->crudo($lista)) {
                $e->errorDeTipo('list_type', $lista, 'Input should be a valid list', null);
            }
        }
        $e->validar();
        /** @var array{activa: bool, notificar_afectado: bool, notificar_jefe: bool, copiar_admins: bool, grupos_ad: list<string>, correos_extra: list<string>} $datos */
        $regla = $this->reglas->guardar(ReglasNotificacion::evento($evento), $datos, $this->actual->usuario()->id, ClienteHttp::ip($request));

        return new JsonResponse($regla->aApi());
    }

    #[Route('/notificaciones/reglas/{evento}', methods: ['DELETE'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function restablecerRegla(Request $request, string $evento): Response
    {
        $this->reglas->restablecer(ReglasNotificacion::evento($evento), $this->actual->usuario()->id, ClienteHttp::ip($request));

        return new Response(null, 204);
    }

    /** A quién llegaría el aviso de un evento (opcionalmente, para una persona). */
    #[Route('/notificaciones/vista-previa/{evento}', methods: ['GET'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function vistaPrevia(Request $request, string $evento): JsonResponse
    {
        $e = Entrada::consulta($request);
        [, $personaId] = $e->campo(new Campo('persona_id', Campo::UUID), false);
        $e->validar();
        $tipo = ReglasNotificacion::evento($evento);
        $afectados = [];
        if (null !== $personaId) {
            $correo = $this->consultas->correoDePersona(Ident::aHex((string) $personaId));
            if (false === $correo) {
                throw new ErrorApi(404, 'PERSON_NOT_FOUND');
            }
            $afectados = [$correo];
        }
        $destinatarios = $this->resolutor->resolver($tipo->value, $afectados, $afectados);

        return new JsonResponse([
            'destinatarios' => array_map(static fn (Destinatario $d): string => $d->correo, $destinatarios),
            'detalle' => array_map(static fn (Destinatario $d): array => $d->aApi(), $destinatarios),
        ]);
    }

    /**
     * `config` obligatorio (formulario completo), con sus errores bajo ["body", "config", …].
     *
     * @return array<string, mixed>
     */
    private static function configuracion(Entrada $e): array
    {
        $sub = $e->objeto('config', requerido: true);
        if (null === $sub) {
            return [];
        }
        $config = FormularioIntegracion::leerYComprobar($sub);
        $e->absorber($sub);

        return $config;
    }

    /** Lógico con valor por defecto; null explícito = error de tipo (campo no opcional). */
    private static function logico(Entrada $e, string $campo, bool $defecto): ?bool
    {
        if (!$e->presente($campo)) {
            return $defecto;
        }
        if (null === $e->crudo($campo)) {
            $e->errorDeTipo('bool_type', $campo, 'Input should be a valid boolean', null);

            return null;
        }

        return $e->campo(Campo::logico($campo), false)[1];
    }
}
