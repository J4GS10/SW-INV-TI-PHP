<?php

declare(strict_types=1);

namespace App\Security\Mfa\Infrastructure;

use App\Dominio\Reloj;
use Doctrine\DBAL\Connection;

/** Trusted devices as stored in SYS_TRUSTED_DEVICE (only the token's hash is kept): all their SQL lives here. */
final readonly class TrustedDeviceStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(string $user, string $tokenHash, string $stamp, ?string $device, ?string $ip, \DateTimeImmutable $now, \DateTimeImmutable $expires): void
    {
        $this->connection->executeStatement(
            'INSERT INTO SYS_TRUSTED_DEVICE (TDV_USER, TDV_TOKEN_HASH, TDV_STAMP, TDV_DEVICE, TDV_IP, TDV_CREATED_AT, TDV_EXPIRES_AT) '
            .'VALUES (:u, :h, :s, :d, :ip, :now, :exp)',
            ['u' => $user, 'h' => $tokenHash, 's' => $stamp, 'd' => $device, 'ip' => $ip, 'now' => Reloj::aBd($now), 'exp' => Reloj::aBd($expires)],
        );
    }

    /** @return array{id: mixed, stamp: string}|null the device of that account with that token, if it has not expired */
    public function findLive(string $user, string $tokenHash, \DateTimeImmutable $now): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT TDV_ID, TDV_STAMP FROM SYS_TRUSTED_DEVICE WHERE TDV_TOKEN_HASH = :h AND TDV_USER = :u AND TDV_EXPIRES_AT > :now',
            ['h' => $tokenHash, 'u' => $user, 'now' => Reloj::aBd($now)],
        );

        return false === $row ? null : ['id' => $row['TDV_ID'], 'stamp' => (string) $row['TDV_STAMP']];
    }

    public function markUsed(mixed $id, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('UPDATE SYS_TRUSTED_DEVICE SET TDV_LAST_USED_AT = :now WHERE TDV_ID = :id', ['now' => Reloj::aBd($now), 'id' => $id]);
    }

    /** @return list<array<string, mixed>> the account's devices that have not expired, newest first */
    public function live(string $user, \DateTimeImmutable $now): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT TDV_ID, TDV_DEVICE, TDV_IP, TDV_CREATED_AT, TDV_EXPIRES_AT, TDV_LAST_USED_AT FROM SYS_TRUSTED_DEVICE '
            .'WHERE TDV_USER = :u AND TDV_EXPIRES_AT > :now ORDER BY TDV_CREATED_AT DESC',
            ['u' => $user, 'now' => Reloj::aBd($now)],
        );
    }

    public function remove(string $user, int $id): bool
    {
        return 1 === $this->connection->executeStatement('DELETE FROM SYS_TRUSTED_DEVICE WHERE TDV_ID = :id AND TDV_USER = :u', ['id' => $id, 'u' => $user]);
    }

    public function removeAll(string $user): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM SYS_TRUSTED_DEVICE WHERE TDV_USER = :u', ['u' => $user]);
    }
}
