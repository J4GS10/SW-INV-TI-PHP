<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Domain;

use App\Dominio\ErrorDominio;
use App\Validacion\ErrorValidacion;

/** How a goods receipt request is read: text, integers, identifiers and the lines Purchasing declares. */
final class ReceiptInput
{
    public const array LINE_TYPES = ['ASSET', 'CONSUMABLE', 'LICENSE', 'OTHER'];
    public const array CURRENCIES = ['GTQ', 'USD', 'CHF'];

    /** @return list<array{item: string, quantity: int, cost: string, warranty: ?int}> */
    public static function lines(mixed $lines): array
    {
        if (!\is_array($lines) || [] === $lines) {
            throw ErrorDominio::invalido('GOODS_RECEIPT_REQUIRES_LINES');
        }
        $valid = [];
        foreach (array_values($lines) as $line) {
            if (!\is_array($line)) {
                throw ErrorDominio::invalido('GOODS_RECEIPT_REQUIRES_LINES');
            }
            $quantity = self::integer($line['quantity'] ?? null);
            $cost = $line['unit_cost'] ?? '';
            $cost = \is_int($cost) ? (string) $cost : $cost;
            $warranty = $line['warranty_months'] ?? null;
            if (null === $quantity || $quantity <= 0) {
                throw ErrorDominio::invalido('GOODS_RECEIPT_INVALID_QUANTITY');
            }
            if (!\is_string($cost) || 1 !== preg_match('/^\d{1,12}(\.\d{1,2})?$/', $cost)) {
                throw ErrorDominio::invalido('GOODS_RECEIPT_INVALID_COST');
            }
            if (null !== $warranty && '' !== $warranty && (null === ($warranty = self::integer($warranty)) || $warranty > 600)) {
                throw ErrorDominio::invalido('GOODS_RECEIPT_INVALID_WARRANTY');
            }
            $warranty = '' === $warranty ? null : $warranty;
            $valid[] = ['item' => self::requiredText($line['item_text'] ?? null, 255, 'GOODS_RECEIPT_LINE_REQUIRES_ITEM'), 'quantity' => $quantity, 'cost' => $cost, 'warranty' => null === $warranty ? null : (int) $warranty];
        }

        return $valid;
    }

    public static function intOrNull(mixed $value): ?int
    {
        return null === $value ? null : (int) $value;
    }

    public static function requiredText(mixed $value, int $max, string $code): string
    {
        $text = \is_string($value) ? trim($value) : '';
        if ('' === $text || mb_strlen($text) > $max) {
            throw ErrorDominio::invalido($code);
        }

        return $text;
    }

    /** Texto opcional: null o vacío = sin valor; otro tipo o más largo que `$max` = 422 (nunca se recorta en silencio). */
    public static function optionalText(mixed $value, int $max, string $field = 'text'): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || mb_strlen(trim($value)) > $max) {
            throw self::invalid($field, $value);
        }
        $text = trim($value);

        return '' === $text ? null : $text;
    }

    /** Entero no negativo (número entero o texto de dígitos); cualquier otra cosa = null. */
    public static function integer(mixed $value): ?int
    {
        return match (true) {
            \is_int($value) => $value >= 0 ? $value : null,
            \is_string($value) && 1 === preg_match('/^\d{1,18}$/', trim($value)) => (int) trim($value),
            default => null,
        };
    }

    /** Identificador opcional: null = sin valor; si viene, entero positivo o 422. */
    public static function optionalId(mixed $value, string $field): ?int
    {
        if (null === $value) {
            return null;
        }
        $id = self::integer($value);

        return null === $id || 0 === $id ? throw self::invalid($field, $value) : $id;
    }

    public static function invalid(string $field, mixed $value): ErrorValidacion
    {
        return new ErrorValidacion([['type' => 'value_error', 'loc' => ['body', ...explode('.', $field)], 'msg' => 'Value error, INVALID_PARAMETER_VALUE',
            'input' => \is_string($value) && \strlen($value) > 100 ? mb_substr($value, 0, 100).'…' : $value, 'ctx' => ['error' => 'INVALID_PARAMETER_VALUE']]]);
    }
}
