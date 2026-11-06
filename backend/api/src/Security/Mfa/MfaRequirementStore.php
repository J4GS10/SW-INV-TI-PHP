<?php

declare(strict_types=1);

namespace App\Security\Mfa;

/** Whether roles configured for mandatory MFA must enroll. Absence of a stored value means required. */
interface MfaRequirementStore
{
    public function isRequired(): bool;

    public function setRequired(bool $required): void;
}
