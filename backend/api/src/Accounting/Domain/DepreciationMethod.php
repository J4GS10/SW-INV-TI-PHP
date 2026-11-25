<?php

declare(strict_types=1);

namespace App\Accounting\Domain;

/** How an asset category loses value over time. */
enum DepreciationMethod: string
{
    /** The same amount every month until the cost (less the residual value) is consumed. */
    case StraightLine = 'STRAIGHT_LINE';
    /** Land, works of art and the like: the book value stays at cost. */
    case None = 'NONE';
}
