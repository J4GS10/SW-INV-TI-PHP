<?php

declare(strict_types=1);

namespace App\Crud;

use App\Db\InsercionOracle;
use App\Dominio\Ident;
use Doctrine\DBAL\Connection;

/**
 * Operaciones genéricas sobre una Entidad con SQL parametrizado. Los nombres
 * de tabla y columna vienen de la definición (código), nunca de la petición.
 */
final readonly class RepositorioEntidad
{
    public function __construct(private Connection $conexion)
    {
    }

    /** @return array<string, mixed>|null fila en formato API */
    public function obtener(Entidad $e, int|string $id): ?array
    {
        $fila = $this->conexion->fetchAssociative(
            'SELECT '.$e->columnasSelect().' FROM "'.$e->tabla.'" t WHERE t."'.$e->clave.'" = :id',
            ['id' => $this->clave($e, $id)],
        );

        return false === $fila ? null : Esquema::aApi($e, $fila);
    }

    /**
     * @param string               $donde      condición SQL con parámetros con nombre (alias `t`)
     * @param array<string, mixed> $parametros
     *
     * @return list<array<string, mixed>>
     */
    public function listar(Entidad $e, string $donde = '1 = 1', array $parametros = [], ?string $orden = null, ?int $saltar = null, ?int $limite = null): array
    {
        $sql = 'SELECT '.$e->columnasSelect().' FROM "'.$e->tabla.'" t WHERE '.$donde
            .' ORDER BY '.($orden ?? $e->orden ?? 't."'.$e->clave.'"');
        if (null !== $limite) {
            $sql .= ' OFFSET '.max(0, (int) $saltar).' ROWS FETCH NEXT '.max(1, $limite).' ROWS ONLY';
        }

        return array_map(
            static fn (array $f): array => Esquema::aApi($e, $f),
            $this->conexion->fetchAllAssociative($sql, $parametros),
        );
    }

    /** @param array<string, mixed> $parametros */
    public function contar(Entidad $e, string $donde = '1 = 1', array $parametros = []): int
    {
        return (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "'.$e->tabla.'" t WHERE '.$donde, $parametros);
    }

    /**
     * @param array<string, mixed> $datos columna => valor (ya validado)
     *
     * @return int|string clave del registro creado
     */
    public function insertar(Entidad $e, array $datos): int|string
    {
        if ($e->claveUuid) {
            $id = $datos[$e->clave] ?? Ident::nuevo();
            $this->conexion->insert('"'.$e->tabla.'"', self::citar($e, [$e->clave => $id, ...$datos]));

            return $id;
        }

        $columnas = [];
        foreach ($datos as $nombre => $valor) {
            $columnas[$e->columna($nombre)] = $valor;
        }

        return (int) InsercionOracle::insertar($this->conexion, $e->tabla, $columnas, $e->clave);
    }

    /** @param array<string, mixed> $datos */
    public function actualizar(Entidad $e, int|string $id, array $datos): int
    {
        if ([] === $datos) {
            return 0;
        }

        return $this->conexion->update('"'.$e->tabla.'"', self::citar($e, $datos), ['"'.$e->clave.'"' => $this->clave($e, $id)]);
    }

    public function eliminar(Entidad $e, int|string $id): int
    {
        return $this->conexion->delete('"'.$e->tabla.'"', ['"'.$e->clave.'"' => $this->clave($e, $id)]);
    }

    /**
     * ¿Existe una fila con `$columna` = `$valor` (sin distinguir mayúsculas ni espacios)?
     *
     * @param array<string, mixed> $parametros
     */
    public function existeTexto(Entidad $e, string $columna, string $valor, int|string|null $excepto = null, string $extra = '', array $parametros = []): bool
    {
        $sql = 'SELECT 1 FROM "'.$e->tabla.'" t WHERE LOWER(TRIM(t."'.$columna.'")) = LOWER(TRIM(:valor))'.$extra;
        $parametros['valor'] = $valor;
        if (null !== $excepto) {
            $sql .= ' AND t."'.$e->clave.'" <> :excepto';
            $parametros['excepto'] = $this->clave($e, $excepto);
        }

        return false !== $this->conexion->fetchOne($sql.' FETCH FIRST 1 ROWS ONLY', $parametros);
    }

    private function clave(Entidad $e, int|string $id): int|string
    {
        return $e->claveUuid ? Ident::aHex((string) $id) : (int) $id;
    }

    /**
     * @param array<string, mixed> $datos
     *
     * @return array<string, mixed>
     */
    private static function citar(Entidad $e, array $datos): array
    {
        $citado = [];
        foreach ($datos as $nombre => $valor) {
            $citado['"'.$e->columna($nombre).'"'] = $valor;
        }

        return $citado;
    }
}
