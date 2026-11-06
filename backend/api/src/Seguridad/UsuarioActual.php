<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Dominio\AlcanceDatos;
use App\Dominio\Usuario;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Usuario y alcance de la petición en curso. Se vacía al terminar cada
 * petición (ResetInterface), imprescindible con FrankenPHP en modo worker.
 */
final class UsuarioActual implements ResetInterface
{
    private ?Usuario $usuario = null;
    private ?AlcanceDatos $alcance = null;
    /** @var array<string, mixed> */
    private array $payload = [];

    /** @param array<string, mixed> $payload */
    public function establecer(Usuario $usuario, array $payload): void
    {
        $this->usuario = $usuario;
        $this->alcance = AlcanceDatos::deUsuario($usuario);
        $this->payload = $payload;
    }

    public function usuario(): Usuario
    {
        return $this->usuario ?? throw new \LogicException('La acción no declaró #[Autenticado].');
    }

    /** null = modo sistema (sin petición autenticada): sin filtros por sede. */
    public function alcance(): ?AlcanceDatos
    {
        return $this->alcance;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function reset(): void
    {
        $this->usuario = null;
        $this->alcance = null;
        $this->payload = [];
    }
}
