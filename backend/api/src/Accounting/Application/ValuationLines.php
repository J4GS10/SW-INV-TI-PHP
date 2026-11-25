<?php

declare(strict_types=1);

namespace App\Accounting\Application;

use App\Accounting\Domain\DepreciationPolicy;
use App\Accounting\Domain\Money;
use App\Accounting\Domain\StraightLineDepreciation;
use App\Accounting\Domain\Valuation;
use App\Dominio\Decimal;
use App\Dominio\Ident;

/** One asset row (ValuationStore) as a valued line of the API, the same for every screen and for the file. */
final class ValuationLines
{
    public const string NO_COST = 'NO_COST';
    public const string NO_CATEGORY = 'NO_CATEGORY';
    public const string NOT_DEPRECIABLE = 'NOT_DEPRECIABLE';
    public const string DEPRECIATING = 'DEPRECIATING';
    public const string FULLY_DEPRECIATED = 'FULLY_DEPRECIATED';
    public const string DISPOSED = 'DISPOSED';

    /**
     * @param array<string, mixed> $row
     *
     * @return array{0: array<string, mixed>, 1: ?Valuation} the line and its value (null when the asset has no cost)
     */
    public static function line(array $row, \DateTimeImmutable $asOf): array
    {
        $policy = self::policy($row);
        $acquired = new \DateTimeImmutable((string) $row['ACQUIRED_ON']);
        $disposed = null === $row['DISPOSED_ON'] ? null : new \DateTimeImmutable((string) $row['DISPOSED_ON']);
        $value = null === $row['COST'] ? null : StraightLineDepreciation::value((string) $row['COST'], $policy, $acquired, $disposed, $asOf);

        $line = [
            'asset_id' => Ident::aTexto((string) $row['ASSET_ID']), 'code' => $row['CODE'], 'serial' => $row['SERIAL'], 'product' => $row['PRODUCT'] ?: null,
            'type_id' => (int) $row['TYPE_ID'], 'type' => $row['TYPE_NAME'],
            'parent_id' => null === $row['PARENT_ID'] ? null : Ident::aTexto((string) $row['PARENT_ID']),
            'category' => null === $row['CATEGORY_ID'] ? null : ['id' => (int) $row['CATEGORY_ID'], 'code' => $row['CATEGORY_CODE'], 'name' => $row['CATEGORY_NAME'],
                'annual_rate' => self::number($row['ANNUAL_RATE']), 'account_code' => $row['ACCOUNT_CODE']],
            'site' => $row['SITE_NAME'], 'currency' => $row['CURRENCY'], 'invoice' => $row['INVOICE'], 'acquired_on' => $row['ACQUIRED_ON'], 'disposed_on' => $row['DISPOSED_ON'],
            'useful_life_months' => $policy->usefulLifeMonths(),
            'acquisition_cost' => $value?->cost, 'monthly_depreciation' => $value?->monthly, 'months_elapsed' => null === $value ? 0 : $value->monthsElapsed,
            'accumulated_depreciation' => $value?->accumulated, 'book_value' => $value?->bookValue, 'residual_value' => $value?->residualValue,
            'depreciation_starts_on' => $value?->startsOn, 'fully_depreciated_on' => $value?->fullyDepreciatedOn,
            'status' => self::status($row, $policy, $value),
        ];

        return [$line, $value];
    }

    /**
     * The asset's yearly depreciation table; empty when it does not depreciate or has no cost.
     *
     * @param array<string, mixed> $row
     *
     * @return list<array<string, mixed>>
     */
    public static function schedule(array $row): array
    {
        if (null === $row['COST']) {
            return [];
        }

        return StraightLineDepreciation::schedule((string) $row['COST'], self::policy($row), new \DateTimeImmutable((string) $row['ACQUIRED_ON']),
            null === $row['DISPOSED_ON'] ? null : new \DateTimeImmutable((string) $row['DISPOSED_ON']));
    }

    /** Oracle's NUMBER as plain text: «.5» → «0.5», «33.3300» → «33.33». */
    public static function number(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $exact = bcadd(Decimal::de($value), '0', 4);

        return rtrim(rtrim($exact, '0'), '.');
    }

    /** @param array<string, mixed> $row */
    private static function policy(array $row): DepreciationPolicy
    {
        // A type marked as not depreciable keeps its assets at cost, whatever its category says.
        return 0 === (int) $row['TYPE_DEPRECIATES'] ? DepreciationPolicy::none()
            : DepreciationPolicy::fromStored($row['METHOD'], $row['ANNUAL_RATE'], $row['RESIDUAL_PERCENT'], $row['STARTS_NEXT_MONTH']);
    }

    /** @param array<string, mixed> $row */
    private static function status(array $row, DepreciationPolicy $policy, ?Valuation $value): string
    {
        return match (true) {
            null === $value => self::NO_COST,
            null !== $row['DISPOSED_ON'] => self::DISPOSED,
            null === $row['CATEGORY_ID'] && 0 !== (int) $row['TYPE_DEPRECIATES'] => self::NO_CATEGORY,
            !$policy->depreciates() => self::NOT_DEPRECIABLE,
            0 === bccomp($value->bookValue, $value->residualValue, Money::SCALE) && $value->monthsElapsed > 0 => self::FULLY_DEPRECIATED,
            default => self::DEPRECIATING,
        };
    }
}
