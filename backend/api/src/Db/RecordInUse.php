<?php

declare(strict_types=1);

namespace App\Db;

/**
 * A record cannot be deleted because a visible row of another table still points to it.
 * Callers translate it into their own code (CANNOT_DELETE_…_IN_USE); uncaught, it is 409 RECORD_IN_USE.
 */
final class RecordInUse extends \RuntimeException
{
    public function __construct(public readonly string $table, public readonly string $usedBy)
    {
        parent::__construct("{$table} is still referenced by {$usedBy}");
    }
}
