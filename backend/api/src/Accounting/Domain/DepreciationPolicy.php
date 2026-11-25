<?php

declare(strict_types=1);

namespace App\Accounting\Domain;

use App\Dominio\Decimal;

/**
 * The depreciation rule of an asset category: method, annual rate over the cost less the residual
 * value, and whether it starts in the month of purchase or the next one.
 */
final readonly class DepreciationPolicy
{
    /** @var numeric-string */
    public string $annualRate;
    /** @var numeric-string */
    public string $residualPercent;

    public function __construct(
        public DepreciationMethod $method,
        mixed $annualRate,
        mixed $residualPercent = '0',
        public bool $startsNextMonth = true,
    ) {
        $rate = Decimal::de($annualRate);
        $residual = Decimal::de($residualPercent);
        if (bccomp($rate, '0', 4) < 0 || bccomp($rate, '100', 4) > 0) {
            throw new \InvalidArgumentException('ACCOUNTING_CATEGORY_INVALID_RATE');
        }
        if (bccomp($residual, '0', 2) < 0 || bccomp($residual, '100', 2) > 0) {
            throw new \InvalidArgumentException('ACCOUNTING_CATEGORY_INVALID_RESIDUAL');
        }
        if (DepreciationMethod::StraightLine === $method && 0 === bccomp($rate, '0', 4)) {
            throw new \InvalidArgumentException('ACCOUNTING_CATEGORY_RATE_REQUIRED');
        }
        $this->annualRate = $rate;
        $this->residualPercent = $residual;
    }

    public static function none(): self
    {
        return new self(DepreciationMethod::None, '0');
    }

    /**
     * The policy as the database keeps it. A category without a usable rule (no category, unknown
     * method, rate of zero) does not depreciate: the asset stays at cost and the screen says why.
     */
    public static function fromStored(mixed $method, mixed $annualRate, mixed $residualPercent, mixed $startsNextMonth): self
    {
        $kind = \is_string($method) ? DepreciationMethod::tryFrom($method) : null;
        if (DepreciationMethod::StraightLine !== $kind) {
            return self::none();
        }
        try {
            return new self($kind, $annualRate ?? '0', $residualPercent ?? '0', 0 !== (int) ($startsNextMonth ?? 1));
        } catch (\InvalidArgumentException) {
            return self::none();
        }
    }

    public function depreciates(): bool
    {
        return DepreciationMethod::StraightLine === $this->method;
    }

    /**
     * Useful life implied by the rate, in whole months: 33.33 % a year is 36 months, 15 % is 80.
     * The rate is the legal maximum expressed as a percentage; the life is what the schedule follows.
     */
    public function usefulLifeMonths(): ?int
    {
        return $this->depreciates() ? max(1, (int) round(1200 / (float) $this->annualRate)) : null;
    }
}
