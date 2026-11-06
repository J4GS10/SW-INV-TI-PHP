<?php

declare(strict_types=1);

namespace App\Security\Mfa;

use App\Config\Ajustes;

/** Disabling is an explicit opt-in of the deployment (MFA_CAN_BE_DISABLED); the default refuses it. */
final readonly class SettingsMfaDisablePolicy implements MfaDisablePolicy
{
    public function __construct(private Ajustes $settings)
    {
    }

    public function allowsDisabling(): bool
    {
        return $this->settings->mfaPuedeDesactivarse;
    }
}
