<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Config\Ajustes;
use App\Db\ContextoRls;
use App\Dominio\Usuario;
use App\Http\ErrorApi;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\SesionRepositorio;
use App\Repositorio\UsuarioRepositorio;

/**
 * Resuelve y valida el usuario de un access token, en el mismo orden que el
 * backend anterior:
 * 1. firma, vigencia y tipo del token; restricción de sesión pendiente;
 * 2. revocación individual del jti (logout), con caché corta en Redis;
 * 3. usuario existente y activo;
 * 4. revocación global (cambio de contraseña, offboarding): SIEMPRE contra la
 *    base, para que tenga efecto inmediato;
 * 5. sesión cerrada individualmente (claim `sid`);
 * 6. alcance de datos y contexto VPD de Oracle para el resto de la petición.
 */
final readonly class Autenticador
{
    public function __construct(
        private Tokens $tokens,
        private CacheAuth $cache,
        private UsuarioRepositorio $usuarios,
        private GobiernoRepositorio $gobierno,
        private SesionRepositorio $sesiones,
        private ContextoRls $rls,
        private UsuarioActual $actual,
        private Ajustes $ajustes,
    ) {
    }

    public function autenticar(string $token, bool $permitirRestringida): Usuario
    {
        $payload = $this->tokens->decodificar($token, 'access');
        $pendiente = PoliticaSesion::restriccion($payload);
        if (null !== $pendiente && !$permitirRestringida) {
            throw ErrorApi::prohibido($pendiente);
        }
        $jti = isset($payload['jti']) ? (string) $payload['jti'] : null;
        $claveCache = null !== $jti ? "auth:jti:{$jti}" : null;
        $cacheado = null !== $claveCache ? $this->cache->obtener($claveCache) : null;

        if (!empty($cacheado['revoked'])) {
            throw new ErrorApi(401, 'TOKEN_REVOKED');
        }
        if (null === $cacheado && null !== $jti && $this->gobierno->jtiRevocado($jti)) {
            $this->cache->guardar($claveCache, ['revoked' => true], $this->ajustes->cacheAuthSegundos);
            throw new ErrorApi(401, 'TOKEN_REVOKED');
        }

        $usuario = $this->usuarios->porUsername((string) ($cacheado['username'] ?? $payload['sub']));
        if (null === $usuario) {
            throw new ErrorApi(401, 'COULD_NOT_VALIDATE_CREDENTIALS');
        }
        if (!$usuario->activo) {
            throw ErrorApi::prohibido('INACTIVE_USER');
        }

        $emitido = Tokens::emitidoEn($payload);
        if (null !== $emitido && $this->gobierno->revocadoGlobalmente($usuario->id, $emitido)) {
            if (null !== $claveCache) {
                $this->cache->guardar($claveCache, ['revoked' => true], $this->ajustes->cacheAuthSegundos);
            }
            throw new ErrorApi(401, 'TOKEN_REVOKED');
        }

        $sid = isset($payload['sid']) ? (string) $payload['sid'] : null;
        if (null !== $sid && $this->sesionCerrada($sid)) {
            throw new ErrorApi(401, 'TOKEN_REVOKED');
        }

        if (null !== $claveCache && null === $cacheado) {
            $this->cache->guardar(
                $claveCache,
                ['revoked' => false, 'username' => $usuario->username],
                $this->ajustes->cacheAuthSegundos,
            );
        }

        $this->actual->establecer($usuario, $payload);
        $this->rls->fijarUsuario($usuario->id);

        return $usuario;
    }

    private function sesionCerrada(string $sid): bool
    {
        $clave = "auth:sid:{$sid}";
        $cacheado = $this->cache->obtener($clave);
        if (null !== $cacheado) {
            return !empty($cacheado['closed']);
        }
        $cerrada = true === $this->sesiones->estaCerrada($sid);
        $this->cache->guardar($clave, ['closed' => $cerrada], $this->ajustes->cacheAuthSegundos);

        return $cerrada;
    }
}
