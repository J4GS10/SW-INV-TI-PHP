<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Inventario\Reclasificacion;
use App\Inventario\Solicitudes;
use App\Operations\OperationalAlerts;
use PHPUnit\Framework\TestCase;

/** Business rules that became testable on their own once the SQL left the services: no database, no HTTP. */
final class ReglasPurasTest extends TestCase
{
    public function testAReclassifiedEntryIsValuedOnlyWhenEveryAssetHasACostInOneCurrency(): void
    {
        $asset = static fn (?string $cost, string $currency = 'GTQ'): array => ['ACT_Costo' => $cost, 'ACT_Moneda' => $currency];

        [$price, $currency] = Reclasificacion::valorDeEntrada([$asset('100.00'), $asset('200.00')], null);
        self::assertSame([150.0, 'GTQ'], [(float) $price, $currency], 'Average cost.');
        self::assertSame('GTQ', Reclasificacion::valorDeEntrada([$asset('100.00'), $asset('200.00')], 'GTQ')[1]);
        self::assertSame([null, null], Reclasificacion::valorDeEntrada([$asset('100.00'), $asset(null)], null), 'One without a cost: not valued.');
        self::assertSame([null, null], Reclasificacion::valorDeEntrada([$asset('100.00'), $asset('200.00', 'USD')], null), 'Two currencies: not valued.');
        self::assertSame([null, null], Reclasificacion::valorDeEntrada([$asset('100.00', 'USD')], 'GTQ'), 'Another currency than the consumable: never converted.');
        self::assertSame('USD', Reclasificacion::valorDeEntrada([$asset('100.00', 'USD')], '')[1], 'A consumable without a currency takes the assets\'.');
    }

    public function testTheSummaryOfAnAdjustmentSaysWhichWayAndHowMuch(): void
    {
        self::assertSame('Ajuste de Tóner: 10 → 7 (−3 unidad)', Solicitudes::resumenDeAjuste('Tóner', 10, 7, 'unidad'));
        self::assertSame('Ajuste de Cable: 2 → 9 (+7 )', Solicitudes::resumenDeAjuste('Cable', 2, 9, null));
    }

    public function testAnAlertWaitsBeforeTheFirstNoticeAndRemindsOnceADay(): void
    {
        $now = new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('UTC'));
        $ago = static fn (string $interval): \DateTimeImmutable => $now->modify("-{$interval}");

        self::assertTrue(OperationalAlerts::shouldNotify(OperationalAlerts::BACKUP_STALE, $now, null, $now), 'No waiting time: at once.');
        self::assertFalse(OperationalAlerts::shouldNotify(OperationalAlerts::IT_ADMIN_SINGLE, $ago('59 minutes'), null, $now), 'It waits an hour.');
        self::assertTrue(OperationalAlerts::shouldNotify(OperationalAlerts::IT_ADMIN_SINGLE, $ago('60 minutes'), null, $now));
        self::assertFalse(OperationalAlerts::shouldNotify(OperationalAlerts::BACKUP_STALE, $ago('3 days'), $ago('23 hours'), $now), 'Already told today.');
        self::assertTrue(OperationalAlerts::shouldNotify(OperationalAlerts::BACKUP_STALE, $ago('3 days'), $ago('24 hours'), $now), 'A day later it reminds.');
    }
}
