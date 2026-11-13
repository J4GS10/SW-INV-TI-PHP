<?php

declare(strict_types=1);

namespace App\Organizacion;

use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Repositorio\ConfiguracionRepositorio;
use App\Repositorio\ConsultasComunes;
use App\Repositorio\GobiernoRepositorio;

/** The brand of the organization (name, logo, colors, default language): read by everyone, changed only by its administrator, always audited. */
final readonly class ConfiguracionGlobal
{
    public function __construct(
        private ConfiguracionRepositorio $configuracion,
        private ConsultasComunes $consultas,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
    ) {
    }

    /** @return array<string, mixed> */
    public function leer(): array
    {
        return $this->configuracion->leer();
    }

    /**
     * Partial change: only the given columns; an explicit null empties a field.
     *
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed> the configuration as it is now
     */
    public function cambiar(array $cambios, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($cambios, $usuario, $ip): array {
            $this->configuracion->guardar($cambios);
            $this->gobierno->registrarAuditoria('UPDATE', 'SYS_CONFIGURACION', ['cambios' => $cambios], $usuario, $ip);

            return $this->configuracion->leer();
        });
    }

    /**
     * @param string $png the logo, already checked to be an image and converted
     * @param string $url where the clients will fetch it from
     *
     * @return array<string, mixed> the configuration as it is now
     */
    public function cambiarLogo(string $png, string $url, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($png, $url, $usuario, $ip): array {
            $this->configuracion->guardarLogo($png, $url);
            $this->gobierno->registrarAuditoria('UPDATE', 'SYS_CONFIGURACION', ['cambios' => ['SYS_Logo_URL' => $url]], $usuario, $ip);

            return $this->configuracion->leer();
        });
    }

    /** @return string the logo image bytes */
    public function logo(): string
    {
        return $this->consultas->logoGuardado() ?? throw ErrorDominio::noEncontrado('LOGO_NOT_FOUND');
    }
}
