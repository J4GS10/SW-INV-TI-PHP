<?php

declare(strict_types=1);

namespace App\Accounting\Domain;

/** What an asset is worth on a date: what it cost, how much of it has been depreciated and what remains. */
final readonly class Valuation
{
    /**
     * @param numeric-string $cost
     * @param numeric-string $monthly
     * @param numeric-string $accumulated
     * @param numeric-string $bookValue
     * @param numeric-string $residualValue
     */
    public function __construct(
        public string $cost,
        public string $monthly,
        public string $accumulated,
        public string $bookValue,
        public string $residualValue,
        public int $monthsElapsed,
        public ?string $startsOn,
        public ?string $fullyDepreciatedOn,
    ) {
    }

    /** An asset that is not depreciated keeps its cost as book value. */
    public static function atCost(string $cost): self
    {
        $cost = Money::of($cost);

        return new self($cost, '0.00', '0.00', $cost, $cost, 0, null, null);
    }

    public function isFullyDepreciated(): bool
    {
        return null !== $this->fullyDepreciatedOn && 0 === bccomp($this->bookValue, $this->residualValue, Money::SCALE) && $this->monthsElapsed > 0;
    }
}
