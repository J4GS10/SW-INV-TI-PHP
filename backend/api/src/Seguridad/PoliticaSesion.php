<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Config\Ajustes;
use App\Dominio\Reloj;
use App\Dominio\Usuario;
use App\Security\Mfa\MfaRequirementStore;

/**
 * Restricciones de sesión (claims del JWT) que obligan a completar un paso de
 * seguridad antes de usar el sistema:
 * - pwd_change: contraseña temporal asignada por un administrador.
 * - mfa_enroll: el rol exige MFA y la cuenta no lo tiene (mfa_can_skip si aún
 *   no usó la única omisión, la de su primer ingreso).
 * Sin restringir: mfa_due (fin de la excepción del administrador del sistema) y
 * mfa_skipped (la sesión en que se omitió; el siguiente ingreso lo exige).
 * Con cualquiera, solo se admiten los endpoints de autoservicio (/me…).
 */
final readonly class PoliticaSesion
{
    public const string CAMBIO_PASSWORD = 'PASSWORD_CHANGE_REQUIRED';
    public const string ENROLAR_MFA = 'MFA_ENROLLMENT_REQUIRED';

    public function __construct(private Ajustes $ajustes, private MfaRequirementStore $mfaRequirement)
    {
    }

    public function rolExigeMfa(string $rol): bool
    {
        return \in_array($rol, $this->ajustes->rolesConMfa, true) && $this->mfaRequirement->isRequired();
    }

    /**
     * Restricciones recalculadas desde la base (en cada login y refresh): se
     * levantan en cuanto el usuario completa el paso. En una sesión SSO la
     * contraseña la gestiona el proveedor (no aplica pwd_change) y el MFA local
     * se exige salvo SSO_MFA_LOCAL=false.
     *
     * @param ?\DateTimeImmutable $sesionCreada inicio de la sesión que se renueva (null = sesión nueva)
     *
     * @return array<string, mixed>
     */
    public function claims(Usuario $u, bool $viaSso = false, ?\DateTimeImmutable $sesionCreada = null): array
    {
        if ($viaSso && !$this->ajustes->ssoMfaLocal) {
            return ['amr' => 'sso'];
        }
        $claims = $viaSso ? ['amr' => 'sso'] : [];
        if (!$viaSso && $u->debeCambiarPassword) {
            $claims['pwd_change'] = true;
        }
        if ($this->rolExigeMfa($u->rol) && !$u->dosFactoresHabilitado) {
            if ($this->exento($u)) {
                $claims['mfa_due'] = $u->mfaExentoHasta?->getTimestamp();
            } elseif ($this->esSesionOmitida($u, $sesionCreada)) {
                $claims['mfa_skipped'] = true;
            } else {
                $claims['mfa_enroll'] = true;
                if (null === $u->mfaOmitidoEn) {
                    $claims['mfa_can_skip'] = true;
                }
            }
        }

        return $claims;
    }

    /** Excepción temporal vigente, concedida por el administrador del sistema. */
    public function exento(Usuario $u): bool
    {
        return null !== $u->mfaExentoHasta && $u->mfaExentoHasta > Reloj::ahora();
    }

    /** La sesión empezó antes de que se omitiera el MFA: es la del primer ingreso en que se omitió. */
    private function esSesionOmitida(Usuario $u, ?\DateTimeImmutable $sesionCreada): bool
    {
        return null !== $u->mfaOmitidoEn && null !== $sesionCreada && $sesionCreada <= $u->mfaOmitidoEn;
    }

    /**
     * Código del paso pendiente (la contraseña va primero), o null.
     *
     * @param array<string, mixed> $payload
     */
    public static function restriccion(array $payload): ?string
    {
        return match (true) {
            !empty($payload['pwd_change']) => self::CAMBIO_PASSWORD,
            !empty($payload['mfa_enroll']) => self::ENROLAR_MFA,
            default => null,
        };
    }
}
