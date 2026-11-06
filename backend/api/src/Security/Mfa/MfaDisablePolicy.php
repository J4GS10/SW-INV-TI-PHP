<?php

declare(strict_types=1);

namespace App\Security\Mfa;

interface MfaDisablePolicy
{
    public function allowsDisabling(): bool;
}
