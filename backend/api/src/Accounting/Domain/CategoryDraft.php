<?php

declare(strict_types=1);

namespace App\Accounting\Domain;

use App\Validacion\ErrorValidacion;

/**
 * The fields of an accounting category as the form sends them, checked: what is wrong comes back as a
 * 422 per field. On an edit only the fields that came are read.
 */
final readonly class CategoryDraft
{
    /** @param array<string, scalar|null> $columns column => value, only the ones given */
    private function __construct(public array $columns)
    {
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input, bool $partial): self
    {
        $errors = [];
        $columns = [];
        $text = static function (string $field, string $column, int $max, bool $required, ?string $pattern = null) use ($input, $partial, &$errors, &$columns): void {
            if (!\array_key_exists($field, $input)) {
                if ($required && !$partial) {
                    $errors[] = self::error($field, 'missing', 'Field required', null);
                }

                return;
            }
            $value = $input[$field];
            $value = \is_string($value) ? trim($value) : $value;
            if (null === $value || '' === $value) {
                if ($required) {
                    $errors[] = self::error($field, 'missing', 'Field required', $input[$field]);
                } else {
                    $columns[$column] = null;
                }

                return;
            }
            if (!\is_string($value) || mb_strlen($value) > $max || (null !== $pattern && 1 !== preg_match($pattern, $value))) {
                $errors[] = self::error($field, 'value_error', 'Value error, INVALID_PARAMETER_VALUE', $input[$field]);

                return;
            }
            $columns[$column] = $value;
        };
        $text('code', 'FAC_CODE', 30, true, '/^[A-Z0-9][A-Z0-9_]*$/');
        $text('name', 'FAC_NAME', 120, true);
        $text('country', 'FAC_COUNTRY', 2, true, '/^[A-Z]{2}$/');
        $text('account_code', 'FAC_ACCOUNT_CODE', 30, false);
        $text('legal_basis', 'FAC_LEGAL_BASIS', 250, false);

        if (\array_key_exists('method', $input) || !$partial) {
            $method = \is_string($input['method'] ?? null) ? DepreciationMethod::tryFrom($input['method']) : null;
            if (null === $method) {
                $errors[] = self::error('method', 'literal_error', "Input should be 'STRAIGHT_LINE' or 'NONE'", $input['method'] ?? null);
            } else {
                $columns['FAC_METHOD'] = $method->value;
            }
        }
        foreach (['annual_rate' => ['FAC_ANNUAL_RATE', 4], 'residual_percent' => ['FAC_RESIDUAL_PERCENT', 2]] as $field => [$column, $decimals]) {
            if (!\array_key_exists($field, $input)) {
                if (!$partial && 'annual_rate' === $field) {
                    $errors[] = self::error($field, 'missing', 'Field required', null);
                }
                continue;
            }
            $value = $input[$field];
            $value = \is_int($value) || \is_float($value) ? (string) $value : $value;
            $value = \is_string($value) ? trim($value) : null;
            if (null === $value || 1 !== preg_match('/^\d{1,3}(\.\d{1,'.$decimals.'})?$/', $value) || bccomp(\App\Dominio\Decimal::de($value), '100', $decimals) > 0) {
                $errors[] = self::error($field, 'value_error', 'Value error, INVALID_PARAMETER_VALUE', $input[$field]);
                continue;
            }
            $columns[$column] = $value;
        }
        foreach (['starts_next_month' => 'FAC_STARTS_NEXT_MONTH', 'active' => 'FAC_ACTIVE'] as $field => $column) {
            if (!\array_key_exists($field, $input)) {
                continue;
            }
            if (\is_bool($input[$field])) {
                $columns[$column] = $input[$field] ? 1 : 0;
            } else {
                $errors[] = self::error($field, 'bool_type', 'Input should be a valid boolean', $input[$field]);
            }
        }
        if ([] !== $errors) {
            throw new ErrorValidacion($errors);
        }

        return new self($columns);
    }

    /**
     * The policy the category will have once the draft is applied over what it has now.
     *
     * @param array<string, mixed> $current FAC_ columns of the stored category ([] on create)
     */
    public function policyOver(array $current): DepreciationPolicy
    {
        $merged = [...$current, ...$this->columns];
        $method = DepreciationMethod::from((string) ($merged['FAC_METHOD'] ?? DepreciationMethod::StraightLine->value));

        try {
            return new DepreciationPolicy($method, DepreciationMethod::None === $method ? '0' : ($merged['FAC_ANNUAL_RATE'] ?? '0'),
                $merged['FAC_RESIDUAL_PERCENT'] ?? '0', 1 === (int) ($merged['FAC_STARTS_NEXT_MONTH'] ?? 1));
        } catch (\InvalidArgumentException $e) {
            throw new ErrorValidacion([self::error('annual_rate', 'value_error', 'Value error, '.$e->getMessage(), $merged['FAC_ANNUAL_RATE'] ?? null)]);
        }
    }

    /** A category without depreciation keeps a rate of zero, whatever the form sent. */
    public function normalized(): self
    {
        return ($this->columns['FAC_METHOD'] ?? null) === DepreciationMethod::None->value
            ? new self([...$this->columns, 'FAC_ANNUAL_RATE' => '0', 'FAC_RESIDUAL_PERCENT' => '0']) : $this;
    }

    /** @return array<string, mixed> */
    private static function error(string $field, string $type, string $message, mixed $value): array
    {
        return ['type' => $type, 'loc' => ['body', $field], 'msg' => $message, 'input' => $value,
            ...('value_error' === $type ? ['ctx' => ['error' => substr($message, 13)]] : [])];
    }
}
