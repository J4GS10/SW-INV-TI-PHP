<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Db\DiagnosticoBd;
use App\Db\FalloBd;
use App\Db\TipoFalloBd;
use App\Dominio\ErrorDominio;
use App\Dominio\MotivoError;
use App\Http\ErrorApi;
use App\Http\ErrorValidacion;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Traduce excepciones al contrato JSON del backend anterior (`{"detail": …}`):
 * - ErrorApi                     → su código y detalle;
 * - validación de la entrada     → 422 VALIDATION_ERROR con `errors`;
 * - Oracle: conexión perdida     → 503 DATABASE_UNAVAILABLE (se descarta la conexión);
 *           VPD/disparadores     → 403 SEDE_OUT_OF_SCOPE;
 *           dato rechazado       → 422 INVALID_PARAMETER_VALUE;
 *           integridad           → 409 INTEGRITY_CONSTRAINT_VIOLATED;
 *           otro                 → 500 DATABASE_ERROR (sin SQL ni parámetros en el log);
 * - rutas inexistentes / método  → 404 "Not Found" / 405 "Method Not Allowed".
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class ErroresListener
{
    public function __construct(private LoggerInterface $logger, private DiagnosticoBd $baseDeDatos)
    {
    }

    /**
     * The only place where a business refusal becomes an HTTP status: the rules say why
     * (App\Dominio\MotivoError) and this layer decides how the API tells the client.
     */
    public static function estadoHttp(MotivoError $motivo): int
    {
        return match ($motivo) {
            MotivoError::Invalido => 400,
            MotivoError::NoAutenticado => 401,
            MotivoError::Prohibido => 403,
            MotivoError::NoEncontrado => 404,
            MotivoError::Conflicto => 409,
            MotivoError::YaNoExiste => 410,
            MotivoError::DemasiadoGrande => 413,
            MotivoError::NoProcesable => 422,
            MotivoError::DemasiadosIntentos => 429,
            MotivoError::Interno => 500,
            MotivoError::ServicioExterno => 502,
            MotivoError::NoDisponible => 503,
        };
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        $ruta = $event->getRequest()->getPathInfo();
        $fallo = $this->baseDeDatos->de($e);

        $respuesta = match (true) {
            $e instanceof ErrorApi => new JsonResponse(['detail' => $e->cuerpo ?? $e->detalle], $e->estado, $e->cabeceras),
            $e instanceof ErrorDominio => new JsonResponse(['detail' => $e->cuerpo ?? $e->detalle], self::estadoHttp($e->motivo),
                MotivoError::NoAutenticado === $e->motivo ? ['WWW-Authenticate' => 'Bearer'] : []),
            $e instanceof ErrorValidacion => new JsonResponse(['detail' => 'VALIDATION_ERROR', 'errors' => $e->errores], 422),
            $e instanceof \App\Db\RecordInUse => new JsonResponse(['detail' => 'RECORD_IN_USE'], 409),
            null !== $fallo => $this->deBaseDeDatos($fallo, $ruta),
            $e instanceof HttpExceptionInterface => new JsonResponse(
                ['detail' => match ($e->getStatusCode()) {
                    404 => 'Not Found',
                    405 => 'Method Not Allowed',
                    default => $e->getMessage(),
                }],
                $e->getStatusCode(),
                $e->getHeaders(),
            ),
            default => null,
        };
        if (null === $respuesta) {
            $this->logger->error('app.error', ['error' => $e::class, 'mensaje' => mb_substr($e->getMessage(), 0, 300)]);
            $respuesta = new JsonResponse(['detail' => 'INTERNAL_SERVER_ERROR'], 500);
        }
        $event->setResponse($respuesta);
    }

    private function deBaseDeDatos(FalloBd $fallo, string $ruta): JsonResponse
    {
        [$grave, $evento, $detalle, $estado] = match ($fallo->tipo) {
            TipoFalloBd::Conexion => [false, 'db.connection_lost', 'DATABASE_UNAVAILABLE', 503],
            TipoFalloBd::FueraDeAlcance => [false, 'rls.denied', 'SEDE_OUT_OF_SCOPE', 403],
            TipoFalloBd::BorradoFisico => [true, 'db.physical_delete_refused', 'PHYSICAL_DELETE_FORBIDDEN', 409],
            TipoFalloBd::Datos => [false, 'db.data_error', 'INVALID_PARAMETER_VALUE', 422],
            // Un duplicado conocido se informa con su código (p. ej. BRAND_ALREADY_EXISTS).
            TipoFalloBd::Integridad => [false, 'db.integrity_error', $fallo->duplicado ?? 'INTEGRITY_CONSTRAINT_VIOLATED', 409],
            TipoFalloBd::Otro => [true, 'db.error', 'DATABASE_ERROR', 500],
        };
        // Sin el SQL ni sus parámetros: pueden traer datos personales.
        $contexto = ['path' => $ruta, 'ora' => $fallo->codigo, ...(TipoFalloBd::Otro === $fallo->tipo ? ['error' => $fallo->mensaje] : [])];
        $grave ? $this->logger->error($evento, $contexto) : $this->logger->warning($evento, $contexto);

        return new JsonResponse(['detail' => $detalle], $estado, TipoFalloBd::Conexion === $fallo->tipo ? ['Retry-After' => '5'] : []);
    }
}
