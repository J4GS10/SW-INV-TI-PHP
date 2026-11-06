<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Alcance de datos por sede de la petición en curso: global o un conjunto de
 * sedes. Se aplica en dos capas: validaciones de la aplicación (este objeto)
 * y el VPD de Oracle (App\Db\ContextoRls), que filtra en la propia base.
 */
final readonly class AlcanceDatos
{
    /** @param list<int> $sedes */
    public function __construct(
        public string $usuarioId,
        public string $personaId,
        public string $rol,
        public bool $global,
        public array $sedes,
    ) {
    }

    public static function deUsuario(Usuario $usuario): self
    {
        return new self($usuario->id, $usuario->personaId, $usuario->rol, $usuario->esGlobal(), $usuario->idsSedes());
    }

    public function restringido(): bool
    {
        return !$this->global;
    }

    public function permite(?int $sedeId): bool
    {
        return $this->global || (null !== $sedeId && \in_array($sedeId, $this->sedes, true));
    }
}
