<?php

declare(strict_types=1);

namespace App\Tareas\Infrastructure;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * El worker vive horas: antes de cada tarea se comprueba la conexión a Oracle
 * y, si se cortó (reinicio de la base, red, inactividad), se descarta para que
 * la tarea abra una nueva. Equivale a `doctrine_ping_connection`, que exige el
 * ORM (aquí solo se usa DBAL).
 */
final readonly class ConexionVivaMiddleware implements MiddlewareInterface
{
    public function __construct(private Connection $conexion, private LoggerInterface $logger)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($this->conexion->isConnected()) {
            try {
                $this->conexion->fetchOne('SELECT 1 FROM DUAL');
            } catch (\Throwable $e) {
                $this->logger->warning('worker.conexion_reabierta', ['error' => mb_substr($e->getMessage(), 0, 200)]);
                $this->conexion->close();
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
