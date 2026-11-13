<?php

declare(strict_types=1);

namespace App\Autenticacion;

use App\Config\Ajustes;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Reloj;
use App\Dominio\Usuario;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\SesionRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\CacheAuth;
use App\Seguridad\Tokens;

/** Signing out: the current session, one of the user's own sessions, or all of them. */
final readonly class CierreDeSesion
{
    public function __construct(
        private Transaccion $transaccion,
        private Tokens $tokens,
        private UsuarioRepositorio $usuarios,
        private GobiernoRepositorio $gobierno,
        private SesionRepositorio $sesiones,
        private Sesiones $cierre,
        private CacheAuth $cache,
        private Ajustes $ajustes,
    ) {
    }

    /**
     * Revokes the tokens the client presents and closes their session. It does not ask for a valid access token
     * (the idle timeout signs out with the access already expired): each token is checked by signature before it
     * is revoked. Idempotent: unknown or foreign tokens are ignored.
     *
     * @param list<array{0: string, 1: 'access'|'refresh'}> $tokens raw token and its kind
     */
    public function actual(array $tokens, ?string $ip, ?string $ua): void
    {
        $revocados = $this->transaccion->ejecutar(function () use ($tokens, $ip, $ua): array {
            $usuario = null;
            $revocados = [];
            foreach ($tokens as [$crudo, $tipo]) {
                $payload = $this->tokens->decodificarSinExpiracion($crudo, $tipo);
                if (null === $payload) {
                    continue;
                }
                $usuario ??= $this->usuarios->porUsername((string) $payload['sub']);
                if (null === $usuario || $usuario->username !== $payload['sub']) {
                    continue; // tokens of different users: the second one is ignored
                }
                $expira = Tokens::expira($payload);
                if ($expira > Reloj::ahora() && $this->gobierno->revocarJtiUnaVez((string) $payload['jti'], $tipo, $expira, $usuario->id)) {
                    $revocados[] = (string) $payload['jti'];
                }
                $sesion = isset($payload['sid']) ? $this->sesiones->obtener((string) $payload['sid']) : null;
                if (null !== $sesion && $sesion['usuario'] === $usuario->id) {
                    $this->cierre->cerrar($sesion['id'], $usuario->id, 'logout');
                }
            }
            if (null !== $usuario) {
                $this->gobierno->registrarAuditoria('LOGOUT', 'INV_USUARIO',
                    ['username' => $usuario->username, 'tokens_revocados' => \count($revocados)], $usuario->id, $ip, $ua);
            }

            return $revocados;
        });
        $this->cache->borrar(...array_map(static fn (string $j): string => "auth:jti:{$j}", $revocados));
    }

    /** Closes every session of the user on every device. */
    public function todas(Usuario $u, ?string $ip, ?string $ua): void
    {
        $this->transaccion->ejecutar(function () use ($u, $ip, $ua): void {
            $this->gobierno->revocarTodo($u->id, Reloj::ahora()->modify('+'.($this->ajustes->refreshDias + 1).' days'));
            $this->gobierno->registrarAuditoria('LOGOUT_ALL', 'INV_USUARIO', ['username' => $u->username], $u->id, $ip, $ua);
        });
    }

    /** Closes one of the user's own sessions (a lost or unknown device). */
    public function propia(Usuario $u, string $sesionId, ?string $ip, ?string $ua): void
    {
        $sesion = $this->sesiones->obtener($sesionId);
        if (null === $sesion || $sesion['usuario'] !== $u->id) {
            throw ErrorDominio::noEncontrado('SESSION_NOT_FOUND');
        }
        $this->transaccion->ejecutar(function () use ($sesion, $u, $sesionId, $ip, $ua): void {
            if ($this->cierre->cerrar($sesion['id'], $u->id, 'cerrada_por_titular')) {
                $this->gobierno->registrarAuditoria('SESSION_CLOSED', 'SYS_SESION', [
                    'username' => $u->username, 'sesion' => $sesionId, 'dispositivo' => $sesion['dispositivo'], 'por' => 'titular',
                ], $u->id, $ip, $ua);
            }
        });
    }
}
