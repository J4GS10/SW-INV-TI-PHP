<?php

declare(strict_types=1);

namespace App\Operations\Ports;

/** What the health checks need to know about the data; the data layer answers (App\Repositorio\OperationsOracle). */
interface SystemState
{
    /** E-mails that failed for good, or that have been waiting to be sent since before `$waitingSince`. */
    public function stuckEmails(\DateTimeImmutable $waitingSince): int;

    /** IT administrators able to sign in at `$now`: active and not locked by the system administrator. */
    public function itAdministratorsAbleToSignIn(\DateTimeImmutable $now): int;

    /** Inventory counts whose closing has started and not finished. */
    public function countsBeingClosed(): int;
}
