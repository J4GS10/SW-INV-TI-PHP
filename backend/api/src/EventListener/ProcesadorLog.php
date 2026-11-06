<?php

declare(strict_types=1);

namespace App\EventListener;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Añade request_id, método y ruta a cada log de la petición. Solo depende de
 * RequestStack: el logger lo usa la conexión a Oracle, así que un procesador
 * que dependiera de la base formaría un ciclo.
 */
#[AsMonologProcessor]
final readonly class ProcesadorLog
{
    public function __construct(private RequestStack $peticiones)
    {
    }

    public function __invoke(LogRecord $registro): LogRecord
    {
        $request = $this->peticiones->getMainRequest();
        if (null === $request) {
            return $registro;
        }

        return $registro->with(extra: [
            ...$registro->extra,
            'request_id' => $request->attributes->get('_request_id'),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
        ]);
    }
}
