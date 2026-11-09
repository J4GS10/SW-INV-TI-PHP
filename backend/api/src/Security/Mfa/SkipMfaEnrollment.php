<?php

declare(strict_types=1);

namespace App\Security\Mfa;

use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Usuario;
use App\Http\ErrorApi;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\SesionRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\PoliticaSesion;
use Doctrine\DBAL\Connection;

/**
 * "Skip for now": an account that must use MFA may skip setting it up once, in
 * its first sign-in. The current session goes on without the restriction; the
 * next sign-in requires MFA (only a system administrator's exemption avoids it).
 */
final readonly class SkipMfaEnrollment
{
    public function __construct(
        private Connection $connection,
        private PoliticaSesion $policy,
        private UsuarioRepositorio $users,
        private SesionRepositorio $sessions,
        private GobiernoRepositorio $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $payload claims of the current access token
     *
     * @return array{user: Usuario, claims: array<string, mixed>} claims for the current session
     */
    public function execute(Usuario $user, array $payload, ?string $ip, ?string $ua): array
    {
        if (!$this->policy->rolExigeMfa($user->rol) || $user->dosFactoresHabilitado || $this->policy->exento($user)) {
            throw new ErrorApi(409, 'MFA_SKIP_NOT_AVAILABLE');
        }
        if (null !== $user->mfaOmitidoEn) {
            throw new ErrorApi(409, 'MFA_SKIP_ALREADY_USED');
        }
        $sid = isset($payload['sid']) ? (string) $payload['sid'] : null;
        $session = null === $sid ? null : $this->sessions->obtener($sid);
        if (null === $session || !SesionRepositorio::activa($session) || $session['usuario'] !== $user->id) {
            throw new ErrorApi(401, 'TOKEN_REVOKED');
        }

        $this->connection->transactional(function () use ($user, $ip, $ua): void {
            // Only once, even with two clicks at the same time.
            $updated = $this->connection->executeStatement(
                'UPDATE "INV_USUARIO" SET "USU_MFA_Omitido_En" = :now WHERE "USU_Usuario" = :id AND "USU_MFA_Omitido_En" IS NULL',
                ['now' => Reloj::aBd(Reloj::ahora()), 'id' => $user->id],
            );
            if (1 !== $updated) {
                throw new ErrorApi(409, 'MFA_SKIP_ALREADY_USED');
            }
            $this->audit->registrarAuditoria('MFA_ENROLLMENT_SKIPPED', 'INV_USUARIO', ['username' => $user->username], $user->id, $ip, $ua);
        });

        $fresh = $this->users->porId($user->id) ?? throw new ErrorApi(401, 'COULD_NOT_VALIDATE_CREDENTIALS');
        $claims = [...$this->policy->claims($fresh, 'sso' === ($payload['amr'] ?? null), $session['creada']), 'sid' => Ident::aTexto($session['id'])];

        return ['user' => $fresh, 'claims' => $claims];
    }
}
