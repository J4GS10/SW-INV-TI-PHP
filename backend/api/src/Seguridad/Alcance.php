<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Config\Ajustes;
use App\Db\ContextoRls;
use App\Dominio\AlcanceDatos;
use App\Dominio\ErrorDominio;
use Doctrine\DBAL\Connection;

/**
 * Alcance de datos por sede de la petición (capa 1, en la aplicación; la capa 2
 * es el VPD de Oracle). Sin usuario autenticado = modo sistema, sin filtros.
 *
 * Los filtros de lectura usan los MISMOS predicados que el VPD
 * (INV_VPD_PREDICADO, fuente única): las dos capas no pueden divergir.
 */
final class Alcance
{
    /** @var array<string, string>|null tabla|operación => predicado */
    private ?array $predicados = null;

    public function __construct(
        private readonly UsuarioActual $actual,
        private readonly ContextoRls $rls,
        private readonly Connection $conexion,
        private readonly Ajustes $ajustes,
    ) {
    }

    public function actual(): ?AlcanceDatos
    {
        return $this->actual->alcance();
    }

    /**
     * Sedes del alcance; null = todas (global o modo sistema).
     *
     * @return list<int>|null
     */
    public function sedesVisibles(): ?array
    {
        $a = $this->actual();

        return null === $a || $a->global ? null : $a->sedes;
    }

    public function permite(?int $sede): bool
    {
        $a = $this->actual();

        return null === $a || $a->permite($sede);
    }

    /** Jerarquía compartida (país, estado, municipio, sede): solo alcance global. */
    public function exigirGlobal(): void
    {
        if ($this->actual()?->restringido()) {
            throw ErrorDominio::prohibido('GLOBAL_SCOPE_REQUIRED');
        }
    }

    /** Modificar un registro existente exige que su sede esté en el alcance. */
    public function exigirPropio(?int $sede): void
    {
        if (!$this->permite($sede)) {
            throw ErrorDominio::prohibido('SEDE_OUT_OF_SCOPE');
        }
    }

    /** La única sede del alcance, si el usuario tiene exactamente una. */
    public function sedePorDefecto(): ?int
    {
        $sedes = $this->sedesVisibles();

        return null !== $sedes && 1 === \count($sedes) ? $sedes[0] : null;
    }

    /**
     * Valida la sede de un registro nuevo o modificado: sin sede se usa la única
     * del alcance; si no, 400 SEDE_REQUIRED (salvo `requerida` false para
     * usuarios globales); fuera del alcance, 403 SEDE_OUT_OF_SCOPE.
     */
    public function exigirSede(?int $sede, bool $requerida = true): ?int
    {
        $a = $this->actual();
        if (null === $a) {
            return $sede;
        }
        $sede ??= $this->sedePorDefecto();
        if (null === $sede) {
            if ($a->restringido() || $requerida) {
                throw ErrorDominio::invalido('SEDE_REQUIRED');
            }

            return null;
        }
        $this->exigirPropio($sede);

        return $sede;
    }

    /**
     * Filtro estricto por sede de una columna: [sql, parámetros]. "1 = 1" sin
     * restricción; "1 = 0" si el usuario no tiene sedes.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public function filtroSede(string $columna, string $prefijo = 'sede'): array
    {
        $sedes = $this->sedesVisibles();
        if (null === $sedes) {
            return ['1 = 1', []];
        }
        if ([] === $sedes) {
            return ['1 = 0', []];
        }
        $parametros = [];
        foreach ($sedes as $i => $sede) {
            $parametros["{$prefijo}{$i}"] = $sede;
        }

        return [$columna.' IN (:'.implode(', :', array_keys($parametros)).')', $parametros];
    }

    /**
     * Predicado de lectura de la capa 1 para una tabla con política VPD:
     * [sql, parámetros]. La tabla se referencia con `$alias`.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function lectura(string $tabla, string $alias): array
    {
        $a = $this->actual();
        if (null === $a || $a->global) {
            return ['1 = 1', []];
        }
        $predicado = $this->predicados()["{$tabla}|LECTURA"] ?? null;
        if (null === $predicado) {
            throw new \LogicException("La tabla {$tabla} no tiene predicado de alcance.");
        }
        $contexto = "SYS_CONTEXT('".$this->ajustes->esquema()."_CTX', 'USUARIO')";
        $sql = str_replace([$contexto, "\"{$tabla}\"."], [':alcance_usuario', "{$alias}."], $predicado);

        return ["({$sql})", ['alcance_usuario' => $a->usuarioId]];
    }

    /**
     * Comprobación del sistema dentro de una petición de usuario, sin alcance
     * (unicidad global, completitud): SOLO para leer y decidir; lo leído no se
     * devuelve al usuario. En la base se vacía el contexto VPD durante el bloque.
     *
     * @template T
     *
     * @param \Closure(): T $bloque
     *
     * @return T
     */
    public function elevado(\Closure $bloque): mixed
    {
        $a = $this->actual();
        if (null === $a) {
            return $bloque();
        }
        $this->rls->limpiar();
        try {
            return $bloque();
        } finally {
            $this->rls->fijarUsuario($a->usuarioId);
        }
    }

    /** @return array<string, string> */
    private function predicados(): array
    {
        if (null === $this->predicados) {
            $this->predicados = [];
            foreach ($this->conexion->fetchAllAssociative('SELECT "VPD_Tabla", "VPD_Operacion", "VPD_Predicado" FROM "INV_VPD_PREDICADO"') as $f) {
                $this->predicados[$f['VPD_Tabla'].'|'.$f['VPD_Operacion']] = (string) $f['VPD_Predicado'];
            }
        }

        return $this->predicados;
    }
}
