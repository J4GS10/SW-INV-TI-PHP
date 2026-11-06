<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Domain;

/** REGISTERED -> (PENDING_APPROVAL) -> CONFIRMED -> COMPLETED; only REGISTERED can be edited or cancelled. */
enum GoodsReceiptStatus: string
{
    case Registered = 'REGISTERED';
    case PendingApproval = 'PENDING_APPROVAL';
    case Confirmed = 'CONFIRMED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function isEditable(): bool
    {
        return self::Registered === $this;
    }

    public function hasEnteredInventory(): bool
    {
        return self::Confirmed === $this || self::Completed === $this;
    }
}
