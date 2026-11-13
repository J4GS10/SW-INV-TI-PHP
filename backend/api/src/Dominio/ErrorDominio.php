<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * A business operation was refused. Carries the reason (MotivoError) and the stable code the
 * clients translate (`ASSET_ALREADY_ASSIGNED_USE_TRANSFER`); it knows nothing about HTTP, so the
 * same rules serve the API, a console command or a background task.
 */
final class ErrorDominio extends \RuntimeException
{
    /** @param array<string, mixed>|null $cuerpo structured detail (a state transition: current and target state) */
    public function __construct(
        public readonly MotivoError $motivo,
        public readonly string $detalle,
        public readonly ?array $cuerpo = null,
    ) {
        parent::__construct($detalle);
    }

    /** @param array<string, mixed>|null $cuerpo */
    public static function invalido(string $detalle, ?array $cuerpo = null): self
    {
        return new self(MotivoError::Invalido, $detalle, $cuerpo);
    }

    /** @param array<string, mixed>|null $cuerpo */
    public static function noEncontrado(string $detalle, ?array $cuerpo = null): self
    {
        return new self(MotivoError::NoEncontrado, $detalle, $cuerpo);
    }

    /** @param array<string, mixed>|null $cuerpo */
    public static function conflicto(string $detalle, ?array $cuerpo = null): self
    {
        return new self(MotivoError::Conflicto, $detalle, $cuerpo);
    }

    /** @param array<string, mixed>|null $cuerpo */
    public static function noProcesable(string $detalle, ?array $cuerpo = null): self
    {
        return new self(MotivoError::NoProcesable, $detalle, $cuerpo);
    }

    public static function prohibido(string $detalle = 'NOT_ENOUGH_PERMISSIONS'): self
    {
        return new self(MotivoError::Prohibido, $detalle);
    }

    public static function noAutenticado(string $detalle = 'COULD_NOT_VALIDATE_CREDENTIALS'): self
    {
        return new self(MotivoError::NoAutenticado, $detalle);
    }

    public static function demasiadosIntentos(string $detalle): self
    {
        return new self(MotivoError::DemasiadosIntentos, $detalle);
    }

    public static function yaNoExiste(string $detalle): self
    {
        return new self(MotivoError::YaNoExiste, $detalle);
    }

    public static function demasiadoGrande(string $detalle): self
    {
        return new self(MotivoError::DemasiadoGrande, $detalle);
    }

    public static function interno(string $detalle): self
    {
        return new self(MotivoError::Interno, $detalle);
    }

    public static function servicioExterno(string $detalle): self
    {
        return new self(MotivoError::ServicioExterno, $detalle);
    }

    public static function noDisponible(string $detalle): self
    {
        return new self(MotivoError::NoDisponible, $detalle);
    }
}
