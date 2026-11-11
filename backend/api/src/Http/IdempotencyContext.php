<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The Idempotency-Key reserved by the current request (App\Http\IdempotencyListener).
 *
 * The first commit of the request marks the key APPLIED in that same commit
 * (App\Db\IdempotencyCommitMiddleware): if the process dies after the commit and
 * before the answer is stored, a retry is refused instead of running twice.
 * FrankenPHP keeps services between requests: Symfony resets this one after each.
 */
final class IdempotencyContext implements ResetInterface
{
    /** @var array{clave: string, ruta: string, hash: string, usuario: string}|null */
    private ?array $claim = null;
    private bool $applied = false;
    private bool $markedInTransaction = false;

    /** @param array{clave: string, ruta: string, hash: string, usuario: string} $claim */
    public function begin(array $claim): void
    {
        $this->claim = $claim;
        $this->applied = false;
        $this->markedInTransaction = false;
    }

    /** @return array{clave: string, ruta: string, hash: string, usuario: string}|null */
    public function claim(): ?array
    {
        return $this->claim;
    }

    /** Key to mark inside the transaction about to commit, or null if there is none or it is already marked. */
    public function keyToMark(): ?string
    {
        return null === $this->claim || $this->applied || $this->markedInTransaction ? null : $this->claim['clave'];
    }

    public function markedInTransaction(): void
    {
        $this->markedInTransaction = true;
    }

    public function committed(): void
    {
        if ($this->markedInTransaction) {
            $this->applied = true;
            $this->markedInTransaction = false;
        }
    }

    public function rolledBack(): void
    {
        $this->markedInTransaction = false;
    }

    /** Did a write of this request commit? Then the key is no longer released on an error. */
    public function applied(): bool
    {
        return $this->applied;
    }

    public function reset(): void
    {
        $this->claim = null;
        $this->applied = false;
        $this->markedInTransaction = false;
    }
}
