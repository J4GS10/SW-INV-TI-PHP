<?php

declare(strict_types=1);

namespace App\Dominio;

/** Datos de la persona titular de una cuenta que usan los flujos de seguridad. */
final readonly class Persona
{
    public function __construct(
        public string $primerNombre,
        public ?string $segundoNombre,
        public string $primerApellido,
        public ?string $segundoApellido,
        public ?string $correo,
        public bool $activa = true,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            (string) $fila['PER_Primer_Nombre'],
            $fila['PER_Segundo_Nombre'] ?? null,
            (string) $fila['PER_Primer_Apellido'],
            $fila['PER_Segundo_Apellido'] ?? null,
            $fila['PER_Email_Corporativo'] ?? null,
            1 === (int) ($fila['PER_Estado'] ?? 1),
        );
    }

    public function nombreCorto(): string
    {
        return "{$this->primerNombre} {$this->primerApellido}";
    }

    /**
     * Datos que la contraseña no puede contener: nombres, apellidos y la parte
     * local del correo, también separada por punto, guion o guion bajo.
     *
     * @return list<string>
     */
    public function datosPersonales(): array
    {
        $local = explode('@', (string) $this->correo)[0];
        $partes = [$this->primerNombre, $this->segundoNombre, $this->primerApellido, $this->segundoApellido, $local];
        foreach (['.', '_', '-'] as $separador) {
            $partes = [...$partes, ...explode($separador, $local)];
        }

        return array_values(array_filter($partes, static fn (?string $p): bool => null !== $p && '' !== $p));
    }
}
