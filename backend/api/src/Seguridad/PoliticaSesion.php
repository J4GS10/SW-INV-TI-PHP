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
 * - mfa_enroll: el rol exige MFA, la cuenta no lo tiene y ya no le queda plazo.
 * - mfa_due (sin restringir): fecha límite de la gracia o de la excepción.
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
     * @return array<string, mixed>
     */
    public function claims(Usuario $u, bool $viaSso = false): array
    {
        if ($viaSso && !$this->ajustes->ssoMfaLocal) {
            return ['amr' => 'sso'];
        }
        $claims = $viaSso ? ['amr' => 'sso'] : [];
        if (!$viaSso && $u->debeCambiarPassword) {
            $claims['pwd_change'] = true;
        }
        if ($this->rolExigeMfa($u->rol) && !$u->dosFactoresHabilitado) {
            // Con gracia o excepción vigente no se bloquea: se avisa con la fecha límite (Unix).
            $plazo = $this->plazoMfa($u);
            if (null === $plazo) {
                $claims['mfa_enroll'] = true;
            } else {
                $claims['mfa_due'] = $plazo->getTimestamp();
            }
        }

        return $claims;
    }

    /**
     * Hasta cuándo puede la cuenta seguir sin MFA: la excepción del administrador
     * del sistema o, si no la hay, la gracia desde el alta. Null = ya es obligatorio.
     */
    public function plazoMfa(Usuario $u): ?\DateTimeImmutable
    {
        $ahora = Reloj::ahora();
        if (null !== $u->mfaExentoHasta && $u->mfaExentoHasta > $ahora) {
            return $u->mfaExentoHasta;
        }
        if ($this->ajustes->mfaGraciaDias > 0 && null !== $u->creadoEn) {
            $fin = $u->creadoEn->modify('+'.$this->ajustes->mfaGraciaDias.' days');
            if ($fin > $ahora) {
                return $fin;
            }
        }

        return null;
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
