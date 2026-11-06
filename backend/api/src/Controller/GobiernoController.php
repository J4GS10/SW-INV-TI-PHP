<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\Limite;
use App\Repositorio\BitacoraRepositorio;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use App\Tareas\PurgaSeguridad;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gobernanza: configuración global (marca de la organización), purga de
 * registros de seguridad caducados, cola de correos y consulta de la bitácora
 * forense (filtrada por el alcance del lector en las dos capas).
 */
#[Route('/api/v1/gov')]
final readonly class GobiernoController
{
    private const string COLOR = '^#([0-9a-fA-F]{3}){1,2}$';
    private const int LOGO_MAX_BYTES = 2097152;
    private const array COLUMNAS_CONFIG = ['SYS_Configuracion', 'SYS_Nombre_Empresa', 'SYS_Logo_URL', 'SYS_Color_Primario',
        'SYS_Color_Secundario', 'SYS_Color_Fondo', 'SYS_Idioma_Defecto', 'SYS_Codigo_Formulario', 'SYS_Ciudad'];

    public function __construct(
        private Connection $conexion,
        private GobiernoRepositorio $gobierno,
        private BitacoraRepositorio $bitacora,
        private UsuarioActual $actual,
    ) {
    }

    // ================= CONFIGURACIÓN =================

    /**
     * Configuración pública (nombre, logo, colores): la pide la pantalla de
     * login sin sesión. 300/min por IP: una oficina detrás de un NAT compartido
     * no debe perder su marca; sigue frenando el scraping masivo.
     */
    #[Route('/config', methods: ['GET'])]
    #[Limite('300/minute')]
    public function config(): JsonResponse
    {
        return new JsonResponse($this->leerConfig());
    }

    /** Sube el logo: se normaliza a PNG, así no se guarda ni se sirve otra cosa que una imagen. */
    #[Route('/config/logo', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function subirLogo(Request $request): JsonResponse
    {
        $subida = \App\Adjuntos\Subida::deFormulario($request, 'file', self::LOGO_MAX_BYTES);
        if (\strlen($subida->contenido) > self::LOGO_MAX_BYTES) {
            throw new \App\Http\ErrorApi(413, 'FILE_TOO_LARGE');
        }
        $logo = \App\Documentos\Logo::desdeBytes($subida->contenido) ?? throw new \App\Http\ErrorApi(400, 'LOGO_MUST_BE_AN_IMAGE');
        $config = $this->conexion->transactional(function () use ($logo, $request): array {
            $this->asegurarConfig();
            $url = '/api/v1/gov/config/logo?v='.time();
            $this->conexion->executeStatement('UPDATE "SYS_CONFIGURACION" SET "SYS_Logo_Image" = :i, "SYS_Logo_URL" = :u WHERE "SYS_Configuracion" = 1',
                ['i' => $logo->png, 'u' => $url], ['i' => \Doctrine\DBAL\ParameterType::LARGE_OBJECT]);
            $this->gobierno->registrarAuditoria('UPDATE', 'SYS_CONFIGURACION', ['cambios' => ['SYS_Logo_URL' => $url]], $this->actual->usuario()->id, ClienteHttp::ip($request));

            return $this->leerConfig();
        });

        return new JsonResponse($config);
    }

    /** Público: la pantalla de inicio de sesión también muestra el logo. */
    #[Route('/config/logo', methods: ['GET'])]
    #[Limite('300/minute')]
    public function logo(): \Symfony\Component\HttpFoundation\Response
    {
        $png = $this->conexion->fetchOne('SELECT "SYS_Logo_Image" FROM "SYS_CONFIGURACION" WHERE "SYS_Configuracion" = 1');
        if (false === $png || null === $png) {
            throw new \App\Http\ErrorApi(404, 'LOGO_NOT_FOUND');
        }

        return new \Symfony\Component\HttpFoundation\Response((string) $png, 200, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=300']);
    }

    /** Cambio parcial; un null explícito vacía el campo. */
    #[Route('/config', methods: ['PUT'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function cambiarConfig(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $valores = [
            'SYS_Nombre_Empresa' => $e->texto('SYS_Nombre_Empresa', 2, 100, opcional: true),
            'SYS_Logo_URL' => $e->texto('SYS_Logo_URL', max: 500, opcional: true),
            'SYS_Color_Primario' => $e->patron('SYS_Color_Primario', self::COLOR),
            'SYS_Color_Secundario' => $e->patron('SYS_Color_Secundario', self::COLOR),
            'SYS_Color_Fondo' => $e->patron('SYS_Color_Fondo', self::COLOR),
            'SYS_Idioma_Defecto' => $e->texto('SYS_Idioma_Defecto', max: 2, opcional: true),
            'SYS_Codigo_Formulario' => $e->texto('SYS_Codigo_Formulario', max: 50, opcional: true),
            'SYS_Ciudad' => $e->texto('SYS_Ciudad', max: 60, opcional: true),
        ];
        $e->validar();
        $cambios = array_filter($valores, static fn (string $columna): bool => $e->presente($columna), \ARRAY_FILTER_USE_KEY);

        $config = $this->conexion->transactional(function () use ($cambios, $request): array {
            $this->asegurarConfig();
            if ([] !== $cambios) {
                $sets = implode(', ', array_map(static fn (string $c): string => "\"{$c}\" = :{$c}", array_keys($cambios)));
                $this->conexion->executeStatement('UPDATE "SYS_CONFIGURACION" SET '.$sets.' WHERE "SYS_Configuracion" = 1', $cambios);
            }
            $this->gobierno->registrarAuditoria('UPDATE', 'SYS_CONFIGURACION', ['cambios' => $cambios],
                $this->actual->usuario()->id, ClienteHttp::ip($request));

            return $this->leerConfig();
        });

        return new JsonResponse($config);
    }

    // ================= MANTENIMIENTO DE SEGURIDAD =================

    /** Limpia tokens revocados expirados, claves de idempotencia de más de 24 h, enlaces y códigos gastados y correos entregados antiguos. */
    #[Route('/security/purge', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function purgar(PurgaSeguridad $purga): JsonResponse
    {
        return new JsonResponse($purga->ejecutar());
    }

    // ================= COLA DE CORREOS =================

    /** Estado de la cola persistente: conteos, pendiente más antiguo y últimos 50 fallidos. */
    #[Route('/email-outbox', methods: ['GET'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function cola(): JsonResponse
    {
        $conteos = $this->conexion->fetchAllKeyValue('SELECT "EOB_Estado", COUNT(*) FROM "SYS_EMAIL_OUTBOX" GROUP BY "EOB_Estado"');
        $antiguo = $this->conexion->fetchOne("SELECT MIN(\"EOB_Creado_En\") FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Estado\" = 'PENDIENTE'");
        $fallidos = $this->conexion->fetchAllAssociative(
            'SELECT "EOB_Id", "EOB_Plantilla", "EOB_Asunto", "EOB_Para", "EOB_Intentos", "EOB_Ultimo_Error", "EOB_Creado_En" '
            ."FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Estado\" = 'FALLIDO' ORDER BY \"EOB_Creado_En\" DESC FETCH FIRST 50 ROWS ONLY");

        return new JsonResponse([
            'pendientes' => (int) ($conteos['PENDIENTE'] ?? 0),
            'enviados' => (int) ($conteos['ENVIADO'] ?? 0),
            'fallidos' => (int) ($conteos['FALLIDO'] ?? 0),
            'pendiente_mas_antiguo' => Reloj::iso(Reloj::desdeBd(false === $antiguo ? null : $antiguo)),
            'ultimos_fallidos' => array_map(static fn (array $f): array => [
                'id' => Ident::aTexto((string) $f['EOB_Id']),
                'plantilla' => $f['EOB_Plantilla'],
                'asunto' => $f['EOB_Asunto'],
                'destinatarios' => json_decode((string) $f['EOB_Para'], true),
                'intentos' => (int) $f['EOB_Intentos'],
                'error' => $f['EOB_Ultimo_Error'],
                'creado_en' => Reloj::iso(Reloj::desdeBd($f['EOB_Creado_En'])),
            ], $fallidos),
        ]);
    }

    /** Devuelve a PENDIENTE un correo FALLIDO (o todos sin `outbox_id`); el worker lo toma en su siguiente sondeo. */
    #[Route('/email-outbox/reintentar', methods: ['POST'])]
    #[Autenticado(roles: [Roles::SUPER_ADMIN])]
    public function reintentar(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        [, $id] = $e->campo(new Campo('outbox_id', Campo::UUID), false);
        $e->validar();
        $sql = "UPDATE \"SYS_EMAIL_OUTBOX\" SET \"EOB_Estado\" = 'PENDIENTE', \"EOB_Intentos\" = 0, \"EOB_Proximo_Intento\" = :ahora "
            ."WHERE \"EOB_Estado\" = 'FALLIDO'";
        $p = ['ahora' => Reloj::aBd(Reloj::ahora())];
        if (null !== $id) {
            $sql .= ' AND "EOB_Id" = :id';
            $p['id'] = Ident::aHex((string) $id);
        }

        return new JsonResponse(['reencolados' => $this->conexion->executeStatement($sql, $p)]);
    }

    // ================= AUDITORÍA =================

    /** Bitácora paginada (más reciente primero). AUDITOR: eventos de sus sedes y sus propias acciones. */
    #[Route('/auditoria', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTORES_AUDITORIA)]
    public function auditoria(Request $request): JsonResponse
    {
        [$saltar, $limite] = Entrada::paginacion($request);
        $e = Entrada::consulta($request);
        $accion = $e->texto('accion', opcional: true);
        $entidad = $e->texto('entidad', opcional: true);
        [, $usuario] = $e->campo(new Campo('usuario_id', Campo::UUID), false);
        [$desde, $hasta] = self::rangoAuditoria($e);
        $filtro = $this->bitacora->filtro($desde, $hasta, $accion, $entidad, null === $usuario ? null : Ident::aHex((string) $usuario));

        return new JsonResponse(['total' => $this->bitacora->contar($filtro), 'items' => array_map(static fn (array $f): array => [
            'AUD_Auditoria' => Ident::aTexto((string) $f['AUD_Auditoria']),
            'AUD_Fecha_Hora' => Reloj::iso(Reloj::desdeBd($f['AUD_Fecha_Hora'])),
            'AUD_Accion' => $f['AUD_Accion'],
            'AUD_Entidad_Afectada' => $f['AUD_Entidad_Afectada'],
            'AUD_Snapshot_JSON' => null === $f['AUD_Snapshot_JSON'] ? null : json_decode((string) $f['AUD_Snapshot_JSON'], true),
            'AUD_IP_Origen' => $f['AUD_IP_Origen'],
            'AUD_User_Agent' => $f['AUD_User_Agent'],
            'USU_Usuario' => null === $f['USU_Usuario'] ? null : Ident::aTexto((string) $f['USU_Usuario']),
            'AUD_Sede' => null === $f['AUD_Sede'] ? null : (int) $f['AUD_Sede'],
            'usuario_nombre' => $f['USU_Username'],
        ], $this->bitacora->eventos($filtro, $saltar, $limite))]);
    }

    /** Conteos por acción y por entidad para los tableros forenses (mucho más barato que paginar). */
    #[Route('/auditoria/resumen', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTORES_AUDITORIA)]
    #[Limite('10/minute')]
    public function resumenAuditoria(Request $request): JsonResponse
    {
        [$desde, $hasta] = self::rangoAuditoria(Entrada::consulta($request));
        $filtro = $this->bitacora->filtro($desde, $hasta);
        $porAccion = $this->bitacora->contarPor(BitacoraRepositorio::POR_ACCION, $filtro);

        return new JsonResponse([
            'total' => array_sum($porAccion),
            'por_accion' => (object) $porAccion,
            'por_entidad' => (object) $this->bitacora->contarPor(BitacoraRepositorio::POR_ENTIDAD, $filtro),
            'from_date' => Reloj::iso($desde),
            'to_date' => Reloj::iso($hasta),
        ]);
    }

    /**
     * Rango de fechas de la consulta (`from_date`, `to_date`); 422 si no son fechas.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    public static function rangoAuditoria(Entrada $e): array
    {
        [, $desde] = $e->campo(new Campo('from_date', Campo::FECHAHORA), false);
        [, $hasta] = $e->campo(new Campo('to_date', Campo::FECHAHORA), false);
        $e->validar();

        return [$desde instanceof \DateTimeImmutable ? $desde : null, $hasta instanceof \DateTimeImmutable ? $hasta : null];
    }

    /** Fila única (id 1); se crea vacía la primera vez, como el backend anterior. */
    private function asegurarConfig(): void
    {
        try {
            $this->conexion->executeStatement('MERGE INTO "SYS_CONFIGURACION" t USING (SELECT 1 AS id FROM DUAL) s '
                .'ON (t."SYS_Configuracion" = s.id) WHEN NOT MATCHED THEN INSERT ("SYS_Configuracion") VALUES (1)');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Otra petición la creó a la vez.
        }
    }

    /** @return array<string, mixed> */
    private function leerConfig(): array
    {
        $this->asegurarConfig();
        $fila = $this->conexion->fetchAssociative('SELECT "'.implode('", "', self::COLUMNAS_CONFIG).'" FROM "SYS_CONFIGURACION" WHERE "SYS_Configuracion" = 1');
        $fila = false === $fila ? [] : $fila;
        $fila['SYS_Configuracion'] = 1;

        return $fila;
    }
}
