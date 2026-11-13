<?php

declare(strict_types=1);

namespace App\Db;

/** What went wrong when the database refused or failed an operation. */
enum TipoFalloBd
{
    /** The connection was lost: nothing was done and the operation can be retried shortly. */
    case Conexion;
    /** A row-level policy refused it: the data belongs to a site outside the user's scope. */
    case FueraDeAlcance;
    /** Something tried to delete physically a row that may only be archived. */
    case BorradoFisico;
    /** A value does not fit its column (too long, wrong type, out of range). */
    case Datos;
    /** A constraint was violated (unique, foreign key, check). */
    case Integridad;
    case Otro;
}
