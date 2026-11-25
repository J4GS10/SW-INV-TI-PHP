<?php

declare(strict_types=1);

namespace App\Accounting\Domain;

/**
 * Sums of values kept apart by currency: quetzales and dollars are never added together (there is no
 * exchange rate in the system), so a workstation bought in two currencies shows two totals.
 */
final class CurrencyTotals
{
    /** @var array<string, array{count: int, cost: numeric-string, accumulated: numeric-string, book: numeric-string}> */
    private array $byCurrency = [];

    public function add(string $currency, Valuation $value): void
    {
        $t = $this->byCurrency[$currency] ?? ['count' => 0, 'cost' => '0.00', 'accumulated' => '0.00', 'book' => '0.00'];
        $this->byCurrency[$currency] = ['count' => $t['count'] + 1, 'cost' => Money::add($t['cost'], $value->cost),
            'accumulated' => Money::add($t['accumulated'], $value->accumulated), 'book' => Money::add($t['book'], $value->bookValue)];
    }

    public function merge(self $other): void
    {
        foreach ($other->byCurrency as $currency => $t) {
            $mine = $this->byCurrency[$currency] ?? ['count' => 0, 'cost' => '0.00', 'accumulated' => '0.00', 'book' => '0.00'];
            $this->byCurrency[$currency] = ['count' => $mine['count'] + $t['count'], 'cost' => Money::add($mine['cost'], $t['cost']),
                'accumulated' => Money::add($mine['accumulated'], $t['accumulated']), 'book' => Money::add($mine['book'], $t['book'])];
        }
    }

    public function isEmpty(): bool
    {
        return [] === $this->byCurrency;
    }

    /** @return list<array{currency: string, count: int, acquisition_cost: numeric-string, accumulated_depreciation: numeric-string, book_value: numeric-string}> */
    public function toList(): array
    {
        ksort($this->byCurrency);
        $rows = [];
        foreach ($this->byCurrency as $currency => $t) {
            $rows[] = ['currency' => $currency, 'count' => $t['count'], 'acquisition_cost' => $t['cost'],
                'accumulated_depreciation' => $t['accumulated'], 'book_value' => $t['book']];
        }

        return $rows;
    }
}
