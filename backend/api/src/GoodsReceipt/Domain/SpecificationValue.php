<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Domain;

/** Validates one atomic specification value against its data type, range and allowed values. */
final class SpecificationValue
{
    /**
     * One value per entry: «SSD, NVMe; HDD» becomes three. A comma between digits is a decimal («2,5») and stays.
     *
     * @return list<string>
     */
    public static function splitList(string $text): array
    {
        $parts = preg_split('/[;|\r\n]+|(?<!\d),|,(?!\d)/u', $text) ?: [];

        return array_values(array_filter(array_map(trim(...), $parts), static fn (string $part): bool => '' !== $part));
    }

    /**
     * @param list<string>|null $allowed values from the catalog; null or empty means free
     *
     * @return string the normalized value
     *
     * @throws \InvalidArgumentException with the error code as message
     */
    public static function normalize(string $dataType, ?float $min, ?float $max, ?array $allowed, string $value): string
    {
        $value = trim($value);
        if ('' === $value) {
            throw new \InvalidArgumentException('SPECIFICATION_VALUE_REQUIRED');
        }
        if (null !== $allowed && [] !== $allowed) {
            foreach ($allowed as $option) {
                if (0 === strcasecmp($option, $value)) {
                    return $option;
                }
            }

            throw new \InvalidArgumentException('SPECIFICATION_VALUE_NOT_ALLOWED');
        }

        return match ($dataType) {
            'NUMBER' => self::number($min, $max, $value),
            'BOOLEAN' => self::boolean($value),
            'LIST' => throw new \InvalidArgumentException('SPECIFICATION_VALUE_NOT_ALLOWED'),
            default => mb_substr($value, 0, 255),
        };
    }

    private static function number(?float $min, ?float $max, string $value): string
    {
        // Atomic: «16GB DDR4» is not a number; the unit and the technology are kept apart.
        if (1 !== preg_match('/^\d+(\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException('SPECIFICATION_VALUE_NOT_A_NUMBER');
        }
        $number = (float) $value;
        if ((null !== $min && $number < $min) || (null !== $max && $number > $max)) {
            throw new \InvalidArgumentException('SPECIFICATION_VALUE_OUT_OF_RANGE');
        }

        return $value;
    }

    private static function boolean(string $value): string
    {
        return match (mb_strtolower($value)) {
            'sí', 'si', 'yes', 'true', '1' => 'Sí',
            'no', 'false', '0' => 'No',
            default => throw new \InvalidArgumentException('SPECIFICATION_VALUE_NOT_A_BOOLEAN'),
        };
    }
}
