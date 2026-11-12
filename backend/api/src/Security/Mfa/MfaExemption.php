<?php

declare(strict_types=1);

namespace App\Security\Mfa;

use App\Config\Ajustes;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Dominio\Usuario;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\PoliticaSesion;
use App\Db\Transaccion;

/**
 * Temporary MFA exemption for one account (e.g. a person who cannot install an
 * authenticator yet). Only the system administrator grants it, always with an
 * end date (at most MFA_EXEMPTION_MAX_DAYS) and a reason, and it is audited.
 * When it ends, the account must set up MFA at its next sign-in.
 */
final readonly class MfaExemption
{
    public function __construct(
        private Transaccion $transaccion,
        private UsuarioRepositorio $users,
        private GobiernoRepositorio $audit,
        private PoliticaSesion $policy,
        private Ajustes $settings,
    ) {
    }

    /** @param string $until date (Y-m-d) in the organization's time zone; the exemption lasts until the end of that day */
    public function grant(string $targetHex, string $until, string $reason, Usuario $requester, ?string $ip): \DateTimeImmutable
    {
        $target = $this->target($targetHex, $requester);
        if ($target->dosFactoresHabilitado) {
            throw ErrorDominio::conflicto('MFA_ALREADY_ENABLED');
        }
        if (!$this->policy->rolExigeMfa($target->rol)) {
            throw ErrorDominio::conflicto('MFA_NOT_REQUIRED_FOR_ROLE');
        }
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $until.' 23:59:59', new \DateTimeZone($this->settings->zonaHoraria))
            ?: throw ErrorDominio::noProcesable('INVALID_PARAMETER_VALUE');
        $end = $end->setTimezone(new \DateTimeZone('UTC'));
        $now = Reloj::ahora();
        if ($end <= $now) {
            throw ErrorDominio::noProcesable('MFA_EXEMPTION_IN_PAST');
        }
        if ($end > $now->modify('+'.($this->settings->mfaExencionMaxDias + 1).' days')) {
            throw ErrorDominio::noProcesable('MFA_EXEMPTION_TOO_LONG');
        }

        $this->transaccion->ejecutar(function () use ($target, $end, $reason, $requester, $ip): void {
            $this->users->guardarExencionMfa($target->id, $end, $reason);
            $this->audit->registrarAuditoria('MFA_EXEMPTION_GRANTED', 'INV_USUARIO',
                ['target_username' => $target->username, 'until' => Reloj::iso($end), 'reason' => $reason], $requester->id, $ip);
        });

        return $end;
    }

    public function revoke(string $targetHex, Usuario $requester, ?string $ip): void
    {
        $target = $this->target($targetHex, $requester);
        $this->transaccion->ejecutar(function () use ($target, $requester, $ip): void {
            $this->users->guardarExencionMfa($target->id, null, null);
            $this->audit->registrarAuditoria('MFA_EXEMPTION_REVOKED', 'INV_USUARIO', ['target_username' => $target->username], $requester->id, $ip);
        });
    }

    private function target(string $targetHex, Usuario $requester): Usuario
    {
        if (Roles::SUPER_ADMIN !== $requester->rol) {
            throw ErrorDominio::prohibido('NOT_ENOUGH_PERMISSIONS');
        }

        return $this->users->porId($targetHex) ?? throw ErrorDominio::noEncontrado('USER_NOT_FOUND');
    }
}
