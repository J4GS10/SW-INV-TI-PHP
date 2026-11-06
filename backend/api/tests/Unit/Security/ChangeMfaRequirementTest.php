<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Config\Ajustes;
use App\Security\Mfa\ChangeMfaRequirement;
use App\Security\Mfa\MfaCannotBeDisabled;
use App\Security\Mfa\MfaDisablePolicy;
use App\Security\Mfa\MfaRequirementStore;
use App\Security\Mfa\SettingsMfaDisablePolicy;
use PHPUnit\Framework\TestCase;

/** Mandatory MFA can only be turned off where the deployment explicitly allows it. */
final class ChangeMfaRequirementTest extends TestCase
{
    public function testWhereAllowedItCanBeTurnedOffAndOn(): void
    {
        $store = $this->store(true);
        $useCase = new ChangeMfaRequirement($store, $this->policy(true));

        $useCase->execute(false);
        self::assertFalse($store->isRequired());

        $useCase->execute(true);
        self::assertTrue($store->isRequired());
    }

    public function testWhereNotAllowedTurningItOffIsRefusedAndNothingChanges(): void
    {
        $store = $this->store(true);
        $useCase = new ChangeMfaRequirement($store, $this->policy(false));

        try {
            $useCase->execute(false);
            self::fail('Turning mandatory MFA off must be refused.');
        } catch (MfaCannotBeDisabled) {
            self::assertTrue($store->isRequired());
        }
    }

    public function testWhereNotAllowedItCanStillBeTurnedOn(): void
    {
        $store = $this->store(false);

        (new ChangeMfaRequirement($store, $this->policy(false)))->execute(true);

        self::assertTrue($store->isRequired());
    }

    public function testADeploymentThatDoesNotOptInCannotDisableIt(): void
    {
        $secret = ['SECRET_KEY' => str_repeat('s', 40)];

        self::assertFalse((new SettingsMfaDisablePolicy(Ajustes::desdeEntorno($secret)))->allowsDisabling());
        self::assertTrue((new SettingsMfaDisablePolicy(Ajustes::desdeEntorno($secret + ['MFA_CAN_BE_DISABLED' => 'true'])))->allowsDisabling());
    }

    private function policy(bool $allowed): MfaDisablePolicy
    {
        return new class($allowed) implements MfaDisablePolicy {
            public function __construct(private bool $allowed)
            {
            }

            public function allowsDisabling(): bool
            {
                return $this->allowed;
            }
        };
    }

    private function store(bool $required): MfaRequirementStore
    {
        return new class($required) implements MfaRequirementStore {
            public function __construct(private bool $required)
            {
            }

            public function isRequired(): bool
            {
                return $this->required;
            }

            public function setRequired(bool $required): void
            {
                $this->required = $required;
            }
        };
    }
}
