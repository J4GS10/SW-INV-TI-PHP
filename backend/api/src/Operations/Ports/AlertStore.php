<?php

declare(strict_types=1);

namespace App\Operations\Ports;

/** Where operational alerts are kept: one record per problem code (App\Repositorio\OperationsOracle). */
interface AlertStore
{
    /** @return array{open: bool, firstSeen: \DateTimeImmutable|null, lastNotified: \DateTimeImmutable|null}|null null when the problem was never seen */
    public function find(string $code): ?array;

    /**
     * The problem is seen for the first time or again after being resolved: it opens at `$now`, not yet notified.
     *
     * @param array<string, int|string> $detail
     */
    public function open(string $code, array $detail, \DateTimeImmutable $now): void;

    /**
     * The problem is still there: its detail and last sighting are refreshed.
     *
     * @param array<string, int|string> $detail
     */
    public function seenAgain(string $code, array $detail, \DateTimeImmutable $now): void;

    public function notified(string $code, \DateTimeImmutable $now): void;

    public function resolve(string $code, \DateTimeImmutable $now): void;

    /** @return list<string> */
    public function openCodes(): array;

    /** @return list<array{code: string, detail: mixed, firstSeen: \DateTimeImmutable|null}> oldest first */
    public function openAlerts(): array;
}
