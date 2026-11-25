<?php

declare(strict_types=1);

namespace App\Tests\Unit\Accounting;

use App\Accounting\Domain\CategoryDraft;
use App\Accounting\Domain\CurrencyTotals;
use App\Accounting\Domain\DepreciationMethod;
use App\Accounting\Domain\DepreciationPolicy;
use App\Accounting\Domain\Money;
use App\Accounting\Domain\StraightLineDepreciation as SL;
use App\Validacion\ErrorValidacion;
use PHPUnit\Framework\TestCase;

/** Straight-line depreciation in exact cents, the yearly table and the per-currency sums. */
final class DepreciationTest extends TestCase
{
    private static function computer(): DepreciationPolicy
    {
        return new DepreciationPolicy(DepreciationMethod::StraightLine, '33.33');
    }

    private static function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    public function testAComputerLosesAThirdAYearFromTheMonthAfterPurchase(): void
    {
        $v = SL::value('12000', self::computer(), self::d('2026-01-15'), null, self::d('2026-06-30'));

        self::assertSame(36, self::computer()->usefulLifeMonths());
        self::assertSame(['12000.00', '333.33', 5, '1666.65', '10333.35'], [$v->cost, $v->monthly, $v->monthsElapsed, $v->accumulated, $v->bookValue]);
        self::assertSame(['2026-02-01', '2029-01-31'], [$v->startsOn, $v->fullyDepreciatedOn]);
        // A month counts once it has closed.
        self::assertSame(4, SL::value('12000', self::computer(), self::d('2026-01-15'), null, self::d('2026-06-29'))->monthsElapsed);
        self::assertSame('12000.00', SL::value('12000', self::computer(), self::d('2026-01-15'), null, self::d('2026-01-31'))->bookValue);
    }

    public function testItEndsExactlyAtTheResidualValueAndNeverGoesBelow(): void
    {
        $end = SL::value('12000', self::computer(), self::d('2026-01-15'), null, self::d('2029-01-31'));
        self::assertSame(['12000.00', '0.00', 36], [$end->accumulated, $end->bookValue, $end->monthsElapsed]);
        self::assertTrue($end->isFullyDepreciated());
        self::assertSame('0.00', SL::value('12000', self::computer(), self::d('2026-01-15'), null, self::d('2040-12-31'))->bookValue);

        $residual = new DepreciationPolicy(DepreciationMethod::StraightLine, '20', '10');
        $v = SL::value('1000', $residual, self::d('2026-03-01'), null, self::d('2035-01-01'));
        self::assertSame(['15.00', '900.00', '100.00', '100.00'], [$v->monthly, $v->accumulated, $v->bookValue, $v->residualValue]);
    }

    public function testTheYearlyTableAddsUpToTheDepreciableBase(): void
    {
        $rows = SL::schedule('12000', self::computer(), self::d('2026-01-15'), null);

        self::assertSame([2026, 2027, 2028, 2029], array_column($rows, 'year'));
        self::assertSame([11, 12, 12, 1], array_column($rows, 'months'));
        self::assertSame(['3666.63', '3999.96', '3999.96', '333.45'], array_column($rows, 'depreciation'));
        self::assertSame('0.00', $rows[3]['book_value']);
        self::assertSame('12000.00', array_reduce($rows, static fn (string $s, array $r): string => Money::add($s, $r['depreciation']), '0.00'));
    }

    public function testStartingInTheSameMonthAndStoppingAtTheDisposal(): void
    {
        $sameMonth = new DepreciationPolicy(DepreciationMethod::StraightLine, '20', '0', false);
        self::assertSame(1, SL::value('600', $sameMonth, self::d('2026-01-20'), null, self::d('2026-01-31'))->monthsElapsed);

        $disposed = SL::value('12000', self::computer(), self::d('2026-01-15'), self::d('2026-04-10'), self::d('2027-12-31'));
        self::assertSame([3, '999.99', '11000.01'], [$disposed->monthsElapsed, $disposed->accumulated, $disposed->bookValue]);
        self::assertCount(1, SL::schedule('12000', self::computer(), self::d('2026-01-15'), self::d('2026-04-10')));
    }

    public function testWhatDoesNotDepreciateStaysAtCost(): void
    {
        $v = SL::value('500.5', DepreciationPolicy::none(), self::d('2020-01-01'), null, self::d('2030-01-01'));
        self::assertSame(['500.50', '0.00', '500.50', 0], [$v->cost, $v->accumulated, $v->bookValue, $v->monthsElapsed]);
        self::assertSame([], SL::schedule('500', DepreciationPolicy::none(), self::d('2020-01-01'), null));
        // A stored rule that cannot be used does not depreciate either.
        self::assertFalse(DepreciationPolicy::fromStored('STRAIGHT_LINE', '0', '0', 1)->depreciates());
        self::assertFalse(DepreciationPolicy::fromStored('OTHER', '20', '0', 1)->depreciates());
        self::assertSame(60, DepreciationPolicy::fromStored('STRAIGHT_LINE', '20.0000', '.5', 0)->usefulLifeMonths());
    }

    public function testRoundingIsHalfUpInBothDirections(): void
    {
        self::assertSame(['2.35', '-2.35', '2.34', '10.00'], [Money::round('2.345'), Money::round('-2.345'), Money::round('2.3449'), Money::of(10)]);
    }

    public function testCurrenciesAreNeverAddedTogether(): void
    {
        $a = new CurrencyTotals();
        $a->add('GTQ', SL::value('100', DepreciationPolicy::none(), self::d('2026-01-01'), null, self::d('2026-02-01')));
        $b = new CurrencyTotals();
        $b->add('USD', SL::value('40', DepreciationPolicy::none(), self::d('2026-01-01'), null, self::d('2026-02-01')));
        $b->add('GTQ', SL::value('50.25', DepreciationPolicy::none(), self::d('2026-01-01'), null, self::d('2026-02-01')));
        $a->merge($b);

        self::assertSame([
            ['currency' => 'GTQ', 'count' => 2, 'acquisition_cost' => '150.25', 'accumulated_depreciation' => '0.00', 'book_value' => '150.25'],
            ['currency' => 'USD', 'count' => 1, 'acquisition_cost' => '40.00', 'accumulated_depreciation' => '0.00', 'book_value' => '40.00'],
        ], $a->toList());
    }

    public function testTheCategoryFormIsCheckedFieldByField(): void
    {
        $ok = CategoryDraft::fromInput(['code' => 'CR_COMPUTO', 'name' => 'Equipo de cómputo', 'country' => 'CR', 'method' => 'STRAIGHT_LINE', 'annual_rate' => '20',
            'starts_next_month' => false], false);
        self::assertSame(['FAC_CODE' => 'CR_COMPUTO', 'FAC_NAME' => 'Equipo de cómputo', 'FAC_COUNTRY' => 'CR', 'FAC_METHOD' => 'STRAIGHT_LINE',
            'FAC_ANNUAL_RATE' => '20', 'FAC_STARTS_NEXT_MONTH' => 0], $ok->columns);
        self::assertSame(60, $ok->policyOver([])->usefulLifeMonths());

        // Without depreciation the rate is zero, whatever came.
        self::assertSame('0', CategoryDraft::fromInput(['code' => 'X', 'name' => 'Terreno', 'country' => 'GT', 'method' => 'NONE', 'annual_rate' => '5'], false)
            ->normalized()->columns['FAC_ANNUAL_RATE']);

        $fields = static function (array $input, bool $partial = false): array {
            try {
                CategoryDraft::fromInput($input, $partial);
            } catch (ErrorValidacion $e) {
                return array_map(static fn (array $x): string => (string) $x['loc'][1], $e->errores);
            }

            return [];
        };
        self::assertSame(['code', 'name', 'country', 'method', 'annual_rate'], $fields([]));
        self::assertSame(['code', 'country', 'annual_rate', 'active'], $fields(['code' => 'bad code', 'country' => 'gtm', 'annual_rate' => '101', 'active' => 'yes'], true));
        self::assertSame([], $fields(['name' => 'Otro nombre'], true));

        $this->expectException(ErrorValidacion::class);
        CategoryDraft::fromInput(['annual_rate' => '0'], true)->policyOver(['FAC_METHOD' => 'STRAIGHT_LINE']);
    }
}
