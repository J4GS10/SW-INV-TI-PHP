<?php

declare(strict_types=1);

namespace App\Db;

/**
 * The record was saved by someone else after this user loaded it (its ROW_VERSION moved on):
 * writing now would silently undo that change. The API answers 412 RECORD_CHANGED_BY_ANOTHER_USER
 * and the screen asks to reload.
 */
final class RowChangedByAnotherUser extends \RuntimeException
{
    public function __construct(public readonly string $table, public readonly int $expected, public readonly int $current)
    {
        parent::__construct("{$table}: expected version {$expected}, found {$current}");
    }
}
