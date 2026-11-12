<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Why a business operation was refused, in business terms. The delivery layer decides how
 * to tell the caller (App\EventListener\ErroresListener maps each one to an HTTP status).
 */
enum MotivoError
{
    /** The request breaks a business rule as it stands. */
    case Invalido;
    /** What was asked for does not exist, or the caller may not see it. */
    case NoEncontrado;
    /** The current state of the data does not allow it (already assigned, already closed…). */
    case Conflicto;
    /** Well formed, but its content cannot be applied. */
    case NoProcesable;
    case Prohibido;
    case NoAutenticado;
    /** It existed and is gone for good (an expired link). */
    case YaNoExiste;
    case DemasiadoGrande;
    /** The system itself failed. */
    case Interno;
    /** A system we depend on answered wrongly. */
    case ServicioExterno;
    /** A system we depend on is not answering. */
    case NoDisponible;
}
