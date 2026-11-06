<?php

declare(strict_types=1);

namespace App\Autenticacion;

use App\Config\Ajustes;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\SesionRepositorio;
use App\Seguridad\CacheAuth;

/** Cierre de sesiones: la sesión, su refresh vigente y la caché de autenticación. */
final readonly class Sesiones
{
    public function __construct(
        private SesionRepositorio $repositorio,
        private GobiernoRepositorio $gobierno,
        private CacheAuth $cache,
        private Ajustes $ajustes,
    ) {
    }

    /** Cierra una sesión y revoca su refresh vigente. false si ya estaba cerrada. */
    public function cerrar(string $sesionHex, string $usuarioHex, string $motivo): bool
    {
        $refresh = $this->repositorio->cerrar($sesionHex, $motivo);
        if (false === $refresh) {
            return false;
        }
        if (null !== $refresh) {
            $this->gobierno->revocarJtiUnaVez($refresh, 'refresh',
                Reloj::ahora()->modify('+'.($this->ajustes->refreshDias + 1).' days'), $usuarioHex);
        }
        // El cierre surte efecto de inmediato aunque la caché tuviera la sesión como abierta.
        $this->cache->guardar('auth:sid:'.Ident::aTexto($sesionHex), ['closed' => true], $this->ajustes->cacheAuthSegundos);

        return true;
    }
}
