<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Db\ErroresOracle;
use App\Db\Unicidad;
use App\Http\ErrorApi;
use App\Http\ErrorValidacion;
use Doctrine\DBAL\Exception as ExcepcionDbal;
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
 * - Oracle: VPD/disparadores     → 403 SEDE_OUT_OF_SCOPE;
 *           dato rechazado       → 422 INVALID_PARAMETER_VALUE;
 *           integridad           → 409 INTEGRITY_CONSTRAINT_VIOLATED;
 *           otro                 → 500 DATABASE_ERROR (sin SQL ni parámetros en el log);
 * - rutas inexistentes / método  → 404 "Not Found" / 405 "Method Not Allowed".
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class ErroresListener
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        $ruta = $event->getRequest()->getPathInfo();

        $respuesta = match (true) {
            $e instanceof ErrorApi => new JsonResponse(['detail' => $e->cuerpo ?? $e->detalle], $e->estado, $e->cabeceras),
            $e instanceof ErrorValidacion => new JsonResponse(['detail' => 'VALIDATION_ERROR', 'errors' => $e->errores], 422),
            $e instanceof ExcepcionDbal => $this->deBaseDeDatos($e, $ruta),
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

    private function deBaseDeDatos(ExcepcionDbal $e, string $ruta): JsonResponse
    {
        $codigo = ErroresOracle::codigo($e);
        if (ErroresOracle::esFueraDeAlcance($codigo)) {
            $this->logger->warning('rls.denied', ['path' => $ruta, 'ora' => $codigo]);

            return new JsonResponse(['detail' => 'SEDE_OUT_OF_SCOPE'], 403);
        }
        if (ErroresOracle::esDeDatos($codigo)) {
            $this->logger->warning('db.data_error', ['path' => $ruta, 'ora' => $codigo]);

            return new JsonResponse(['detail' => 'INVALID_PARAMETER_VALUE'], 422);
        }
        if (ErroresOracle::esDeIntegridad($codigo)) {
            $this->logger->warning('db.integrity_error', ['path' => $ruta, 'ora' => $codigo]);

            // Un duplicado conocido se informa con su código (p. ej. BRAND_ALREADY_EXISTS).
            return new JsonResponse(['detail' => Unicidad::codigo($e) ?? 'INTEGRITY_CONSTRAINT_VIOLATED'], 409);
        }
        // Sin el SQL ni sus parámetros: pueden traer datos personales.
        $mensaje = $e->getPrevious()?->getMessage() ?? $e->getMessage();
        $this->logger->error('db.error', ['path' => $ruta, 'ora' => $codigo, 'error' => mb_substr(strtok($mensaje, "\n") ?: '', 0, 300)]);

        return new JsonResponse(['detail' => 'DATABASE_ERROR'], 500);
    }
}
