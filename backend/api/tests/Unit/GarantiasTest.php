<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Inventario\Compras;
use PHPUnit\Framework\TestCase;

/** The warranty rule on its own: no database, no HTTP. */
final class GarantiasTest extends TestCase
{
    public function testAWarrantyIsMissingExpiredAboutToExpireOrInForce(): void
    {
        $today = new \DateTimeImmutable('2026-06-15', new \DateTimeZone('UTC'));
        $threshold = $today->modify('+30 days');
        $on = static fn (string $date): \DateTimeImmutable => new \DateTimeImmutable($date, new \DateTimeZone('UTC'));

        self::assertSame('sin_garantia', Compras::estadoGarantia(null, $today, $threshold));
        self::assertSame('vencida', Compras::estadoGarantia($on('2026-06-14'), $today, $threshold));
        self::assertSame('por_vencer', Compras::estadoGarantia($today, $today, $threshold), 'It ends today: still covered, about to expire.');
        self::assertSame('por_vencer', Compras::estadoGarantia($on('2026-07-15'), $today, $threshold), 'The last day of the threshold counts.');
        self::assertSame('vigente', Compras::estadoGarantia($on('2026-07-16'), $today, $threshold));
    }
}
