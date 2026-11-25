<?php

declare(strict_types=1);

namespace App\Accounting\Application;

use App\Accounting\Domain\CurrencyTotals;
use App\Accounting\Infrastructure\ValuationStore;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;

/**
 * How much each thing is worth, on any date: an asset with every component installed in it, the whole
 * workstation of a person, and the summary of the books by category and by type. Amounts are summed per
 * currency, and each screen reads its assets in a single query.
 */
final readonly class AssetValuation
{
    public function __construct(private ValuationStore $store)
    {
    }

    /** @return array<string, mixed> the asset, its components nested, its yearly table and the totals of the equipment */
    public function forAsset(string $asset, \DateTimeImmutable $asOf): array
    {
        $rows = $this->store->assetWithComponents($asset);
        if ([] === $rows) {
            throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
        }
        [$items, $totals, $pending] = self::tree($rows, $asOf);

        return ['as_of' => $asOf->format('Y-m-d'), 'asset' => $items[0], 'schedule' => ValuationLines::schedule($rows[0]),
            'totals' => $totals->toList(), 'pending' => $pending];
    }

    /** @return array<string, mixed> the person, what they have in custody (with the components of each asset) and the totals */
    public function forPerson(string $person, \DateTimeImmutable $asOf): array
    {
        $who = $this->store->person($person) ?? throw ErrorDominio::noEncontrado('PERSON_NOT_FOUND');
        [$items, $totals, $pending] = self::tree($this->store->workstation($person), $asOf);

        return ['as_of' => $asOf->format('Y-m-d'),
            'person' => ['person_id' => Ident::aTexto((string) $who['PERSON_ID']), 'name' => preg_replace('/\s+/', ' ', (string) $who['NAME']),
                'email' => $who['EMAIL'], 'department' => $who['DEPARTMENT'], 'position' => $who['POSITION']],
            'items' => $items, 'totals' => $totals->toList(), 'pending' => $pending];
    }

    /**
     * What is on the books on the date, grouped by accounting category, asset type and site and, for whoever
     * reads people, also by person in custody and by department. Each group carries its per-currency totals.
     *
     * @return array<string, mixed>
     */
    public function summary(\DateTimeImmutable $asOf, ?int $site, bool $withPeople = false): array
    {
        $groups = ['by_category' => [], 'by_type' => [], 'by_site' => [], 'by_person' => [], 'by_department' => []];
        $totals = new CurrencyTotals();
        $pending = ['without_cost' => 0, 'without_category' => 0];
        $valued = [];
        foreach ($this->store->onTheBooks($asOf->format('Y-m-d'), $site) as $row) {
            [$line, $value] = ValuationLines::line($row, $asOf);
            self::count($pending, $line);
            if (null !== $value) {
                $valued[] = [$row, $line, $value];
                $totals->add((string) $line['currency'], $value);
            }
        }
        $people = $withPeople ? $this->store->people(array_values(array_filter(array_map(static fn (array $v): ?string => null === $v[0]['HOLDER_ID'] ? null : (string) $v[0]['HOLDER_ID'], $valued)))) : [];
        foreach ($valued as [$row, $line, $value]) {
            $currency = (string) $line['currency'];
            self::addTo($groups['by_category'], (string) ($line['category']['id'] ?? 0), ['category' => $line['category']], $currency, $value);
            self::addTo($groups['by_type'], (string) $line['type_id'], ['type_id' => $line['type_id'], 'type' => $line['type'], 'category' => $line['category']['name'] ?? null], $currency, $value);
            self::addTo($groups['by_site'], (string) ($row['SITE_ID'] ?? 0), ['site_id' => null === $row['SITE_ID'] ? null : (int) $row['SITE_ID'], 'site' => $row['SITE_NAME']], $currency, $value);
            if (!$withPeople) {
                continue;
            }
            $holder = null === $row['HOLDER_ID'] ? null : (string) $row['HOLDER_ID'];
            $who = null === $holder ? null : ($people[$holder] ?? null);
            // Lo que no está a cargo de nadie (en bodega, instalado en un área) queda en su propio grupo.
            self::addTo($groups['by_person'], $holder ?? '-', ['person_id' => null === $holder ? null : Ident::aTexto($holder), 'person' => $who['name'] ?? null,
                'department' => $who['department'] ?? null], $currency, $value);
            self::addTo($groups['by_department'], (string) ($who['department_id'] ?? 0), ['department_id' => $who['department_id'] ?? null, 'department' => $who['department'] ?? null], $currency, $value);
        }
        $list = static fn (array $g): array => array_values(array_map(static fn (array $x): array => [...$x, 'totals' => $x['totals']->toList()], $g));
        $out = ['as_of' => $asOf->format('Y-m-d'), 'site_id' => $site];
        foreach ($groups as $name => $g) {
            if ($withPeople || !\in_array($name, ['by_person', 'by_department'], true)) {
                $out[$name] = $list($g);
            }
        }

        return [...$out, 'totals' => $totals->toList(), 'pending' => $pending];
    }

    /**
     * @param array<string, array<string, mixed>> $groups
     * @param array<string, mixed>                $meta
     */
    private static function addTo(array &$groups, string $key, array $meta, string $currency, \App\Accounting\Domain\Valuation $value): void
    {
        $groups[$key] ??= [...$meta, 'totals' => new CurrencyTotals()];
        $groups[$key]['totals']->add($currency, $value);
    }

    /**
     * The fixed-asset register: one valued line per asset on the books, components included.
     *
     * @return list<array<string, mixed>>
     */
    public function register(\DateTimeImmutable $asOf, ?int $site): array
    {
        return array_map(static fn (array $row): array => ValuationLines::line($row, $asOf)[0], $this->store->onTheBooks($asOf->format('Y-m-d'), $site));
    }

    /**
     * Rows in tree order (each parent before its components) as nested lines: every line carries the
     * totals of itself plus everything installed in it.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{0: list<array<string, mixed>>, 1: CurrencyTotals, 2: array{without_cost: int, without_category: int}}
     */
    private static function tree(array $rows, \DateTimeImmutable $asOf): array
    {
        $nodes = [];
        $subtotals = [];
        $parents = [];
        $pending = ['without_cost' => 0, 'without_category' => 0];
        foreach ($rows as $row) {
            [$line, $value] = ValuationLines::line($row, $asOf);
            self::count($pending, $line);
            $id = (string) $row['ASSET_ID'];
            $nodes[$id] = [...$line, 'components' => []];
            $subtotals[$id] = new CurrencyTotals();
            if (null !== $value) {
                $subtotals[$id]->add((string) $line['currency'], $value);
            }
            $parent = null === $row['PARENT_ID'] ? null : (string) $row['PARENT_ID'];
            $parents[$id] = null !== $parent && isset($nodes[$parent]) ? $parent : null;
        }
        // Children before parents: each subtree is complete when its parent takes it.
        $roots = [];
        foreach (array_reverse(array_keys($nodes)) as $id) {
            $node = [...$nodes[$id], 'totals' => $subtotals[$id]->toList()];
            $parent = $parents[$id];
            if (null === $parent) {
                array_unshift($roots, $node);
                continue;
            }
            array_unshift($nodes[$parent]['components'], $node);
            $subtotals[$parent]->merge($subtotals[$id]);
        }
        $totals = new CurrencyTotals();
        foreach ($nodes as $id => $_) {
            if (null === $parents[$id]) {
                $totals->merge($subtotals[$id]);
            }
        }

        return [$roots, $totals, $pending];
    }

    /**
     * @param array{without_cost: int, without_category: int} $pending
     * @param array<string, mixed>                           $line
     */
    private static function count(array &$pending, array $line): void
    {
        if (ValuationLines::NO_COST === $line['status']) {
            ++$pending['without_cost'];
        } elseif (ValuationLines::NO_CATEGORY === $line['status']) {
            ++$pending['without_category'];
        }
    }
}
