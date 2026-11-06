<?php

declare(strict_types=1);

namespace App\Adjuntos;

use App\Dominio\Ident;

/** El registro al que pertenece un adjunto: activo (UUID en hex) u orden/licencia (entero). */
final readonly class Dueno
{
    private function __construct(public TipoDueno $tipo, public string|int $id)
    {
    }

    public static function activo(string $hex): self
    {
        return new self(TipoDueno::Activo, $hex);
    }

    public static function orden(int $id): self
    {
        return new self(TipoDueno::Orden, $id);
    }

    public static function ingreso(int $id): self
    {
        return new self(TipoDueno::Ingreso, $id);
    }

    public static function licencia(int $id): self
    {
        return new self(TipoDueno::Licencia, $id);
    }

    /** Identificador como aparece en la clave del objeto («activos/<uuid>/…», «ordenes/12/…»). */
    public function idEnRuta(): string
    {
        return TipoDueno::Activo === $this->tipo ? Ident::aTexto((string) $this->id) : (string) $this->id;
    }
}
