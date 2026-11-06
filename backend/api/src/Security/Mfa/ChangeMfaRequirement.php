<?php

declare(strict_types=1);

namespace App\Security\Mfa;

/** Turns mandatory MFA on or off; turning it off requires the deployment's explicit opt-in. */
final readonly class ChangeMfaRequirement
{
    public function __construct(private MfaRequirementStore $store, private MfaDisablePolicy $disablePolicy)
    {
    }

    public function execute(bool $required): void
    {
        if (!$required && !$this->disablePolicy->allowsDisabling()) {
            throw new MfaCannotBeDisabled();
        }
        $this->store->setRequired($required);
    }
}
