<?php

declare(strict_types=1);

namespace App\Accounting\Domain;

/**
 * Straight-line depreciation by whole months, as the books record it at each month end:
 *
 * - depreciable base = cost − residual value (residual % of the cost);
 * - useful life = 12 / annual rate, in months; the monthly charge is base / life in cents, and the
 *   last month takes the rounding difference, so the asset ends exactly at its residual value;
 * - it starts in the month of purchase, or the next one when the category says so;
 * - a month counts once it has closed (the date is its last day or later);
 * - a disposed asset is depreciated up to and including the month of its disposal.
 */
final class StraightLineDepreciation
{
    public static function value(string $cost, DepreciationPolicy $policy, \DateTimeImmutable $acquired, ?\DateTimeImmutable $disposed, \DateTimeImmutable $asOf): Valuation
    {
        $cost = Money::of($cost);
        $life = $policy->usefulLifeMonths();
        if (null === $life || !Money::isPositive($cost)) {
            return Valuation::atCost($cost);
        }
        $residual = Money::percent($cost, $policy->residualPercent);
        $base = Money::sub($cost, $residual);
        $monthly = Money::round(bcdiv($base, (string) $life, 8));
        $start = self::month($acquired) + ($policy->startsNextMonth ? 1 : 0);
        $end = self::lastClosedMonth($asOf);
        if (null !== $disposed) {
            $end = min($end, self::month($disposed));
        }
        $elapsed = max(0, min($life, $end - $start + 1));
        $accumulated = self::accumulated($base, $monthly, $life, $elapsed);

        return new Valuation($cost, $monthly, $accumulated, Money::sub($cost, $accumulated), $residual, $elapsed,
            self::firstDay($start), self::lastDay($start + $life - 1));
    }

    /**
     * Year by year (calendar year, the fiscal year in Guatemala) until the asset is fully depreciated or disposed.
     *
     * @return list<array{year: int, months: int, depreciation: numeric-string, accumulated: numeric-string, book_value: numeric-string}>
     */
    public static function schedule(string $cost, DepreciationPolicy $policy, \DateTimeImmutable $acquired, ?\DateTimeImmutable $disposed): array
    {
        $cost = Money::of($cost);
        $life = $policy->usefulLifeMonths();
        if (null === $life || !Money::isPositive($cost)) {
            return [];
        }
        $base = Money::sub($cost, Money::percent($cost, $policy->residualPercent));
        $monthly = Money::round(bcdiv($base, (string) $life, 8));
        $start = self::month($acquired) + ($policy->startsNextMonth ? 1 : 0);
        $last = $start + $life - 1;
        if (null !== $disposed) {
            $last = min($last, self::month($disposed));
        }
        $rows = [];
        $before = '0.00';
        for ($year = intdiv($start, 12); $year <= intdiv($last, 12); ++$year) {
            $through = min($last, $year * 12 + 11);
            $months = $through - max($start, $year * 12) + 1;
            $accumulated = self::accumulated($base, $monthly, $life, $through - $start + 1);
            $rows[] = ['year' => $year, 'months' => $months, 'depreciation' => Money::sub($accumulated, $before),
                'accumulated' => $accumulated, 'book_value' => Money::sub($cost, $accumulated)];
            $before = $accumulated;
        }

        return $rows;
    }

    /**
     * @param numeric-string $base
     * @param numeric-string $monthly
     *
     * @return numeric-string
     */
    private static function accumulated(string $base, string $monthly, int $life, int $months): string
    {
        if ($months <= 0) {
            return '0.00';
        }

        return $months >= $life ? $base : Money::min(Money::round(bcmul($monthly, (string) $months, 8)), $base);
    }

    /** Months since year 0, so that consecutive months differ by one. */
    private static function month(\DateTimeImmutable $date): int
    {
        return (int) $date->format('Y') * 12 + (int) $date->format('n') - 1;
    }

    private static function lastClosedMonth(\DateTimeImmutable $date): int
    {
        return self::month($date) - ($date->format('j') === $date->format('t') ? 0 : 1);
    }

    private static function firstDay(int $month): string
    {
        return \sprintf('%04d-%02d-01', intdiv($month, 12), $month % 12 + 1);
    }

    private static function lastDay(int $month): string
    {
        return (new \DateTimeImmutable(self::firstDay($month)))->format('Y-m-t');
    }
}
