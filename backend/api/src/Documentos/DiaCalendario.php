<?php

declare(strict_types=1);

namespace App\Documentos;

/** Fecha sin hora (columna DATE) para una celda de Excel con formato «aaaa-mm-dd». */
final readonly class DiaCalendario
{
    private function __construct(public \DateTimeImmutable $fecha)
    {
    }

    /** «2026-10-28» (o el inicio de un valor DATE de Oracle) → día; null si está vacío. */
    public static function desde(?string $valor): ?self
    {
        if (null === $valor || '' === $valor) {
            return null;
        }
        $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($valor, 0, 10), new \DateTimeZone('UTC'));

        return false === $fecha ? throw new \UnexpectedValueException("Fecha no reconocida: {$valor}") : new self($fecha);
    }
}
