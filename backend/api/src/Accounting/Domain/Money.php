<?php

declare(strict_types=1);

namespace App\Accounting\Domain;

use App\Dominio\Decimal;

/** Exact amounts for accounting (bcmath, never floating point), rounded half up to cents. */
final class Money
{
    public const int SCALE = 2;

    /** @return numeric-string */
    public static function of(mixed $value): string
    {
        return self::round(Decimal::de($value));
    }

    /**
     * Half up, also for negatives (bcmath only truncates).
     *
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        $half = Decimal::de((str_starts_with($value, '-') ? '-' : '').'0.'.str_repeat('0', $scale).'5');

        return bcadd($value, $half, $scale);
    }

    /**
     * @param numeric-string $a
     * @param numeric-string $b
     *
     * @return numeric-string
     */
    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    /**
     * @param numeric-string $a
     * @param numeric-string $b
     *
     * @return numeric-string
     */
    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    /**
     * @param numeric-string $a
     * @param numeric-string $b
     *
     * @return numeric-string
     */
    public static function min(string $a, string $b): string
    {
        return bccomp($a, $b, self::SCALE) <= 0 ? $a : $b;
    }

    /**
     * The share `$percent` % of `$amount`, rounded to cents.
     *
     * @param numeric-string $amount
     * @param numeric-string $percent
     *
     * @return numeric-string
     */
    public static function percent(string $amount, string $percent, string $divisor = '100'): string
    {
        return self::round(bcdiv(bcmul($amount, $percent, 8), $divisor, 8));
    }

    /** @param numeric-string $value */
    public static function isPositive(string $value): bool
    {
        return 1 === bccomp($value, '0', self::SCALE);
    }
}
