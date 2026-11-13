<?php

declare(strict_types=1);

namespace App\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Transacción de negocio con efectos posteriores al commit (notificaciones):
 * los efectos se ejecutan solo si la transacción EXTERNA se confirmó; si se
 * revierte, se descartan. Una operación anidada (aprobar → ejecutar la baja)
 * comparte la transacción de la externa.
 */
final class Transaccion implements ResetInterface
{
    /** @var list<\Closure(): void> */
    private array $alConfirmar = [];

    public function __construct(private readonly Connection $conexion, private readonly LoggerInterface $logger)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $bloque
     *
     * @return T
     */
    public function ejecutar(\Closure $bloque): mixed
    {
        $externa = !$this->conexion->isTransactionActive();
        try {
            $resultado = $this->conexion->transactional($bloque);
        } catch (\Throwable $e) {
            if ($externa) {
                $this->alConfirmar = [];
            }
            throw $e;
        }
        if ($externa) {
            $pendientes = $this->alConfirmar;
            $this->alConfirmar = [];
            foreach ($pendientes as $efecto) {
                try {
                    $efecto();
                } catch (\Throwable $e) {
                    $this->logger->warning('post_commit.fallido', ['error' => $e::class, 'mensaje' => mb_substr($e->getMessage(), 0, 200)]);
                }
            }
        }

        return $resultado;
    }

    /**
     * Como ejecutar(), pero el choque de un índice único (dos peticiones crean lo mismo a la vez) se convierte en `$siDuplica`.
     *
     * @template T
     *
     * @param \Closure(): T $bloque
     *
     * @return T
     */
    public function ejecutarUnica(\Closure $bloque, \Throwable $siDuplica): mixed
    {
        try {
            return $this->ejecutar($bloque);
        } catch (UniqueConstraintViolationException) {
            throw $siDuplica;
        }
    }

    /**
     * Ejecuta `$bloque` dentro de la transacción en curso; si viola una restricción de la base (única, foránea o de
     * comprobación) lanza `$siViola`, y quien abrió la transacción la revierte.
     *
     * @template T
     *
     * @param \Closure(): T $bloque
     *
     * @return T
     */
    public function sinViolaciones(\Closure $bloque, \Throwable $siViola): mixed
    {
        try {
            return $bloque();
        } catch (ConstraintViolationException) {
            throw $siViola;
        }
    }

    /** @param \Closure(): void $efecto se ejecuta tras el commit de la transacción externa */
    public function alConfirmar(\Closure $efecto): void
    {
        if (!$this->conexion->isTransactionActive()) {
            $efecto();

            return;
        }
        $this->alConfirmar[] = $efecto;
    }

    public function reset(): void
    {
        $this->alConfirmar = [];
    }
}
