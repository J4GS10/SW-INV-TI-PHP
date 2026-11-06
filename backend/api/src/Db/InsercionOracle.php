<?php

declare(strict_types=1);

namespace App\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\OCI8\Exception\Error as ErrorOci8;

/**
 * INSERT ... RETURNING clave INTO :retorno sobre la misma sesión de DBAL (las
 * columnas identidad de Oracle no se leen de otro modo sin carreras). Respeta
 * la transacción en curso y traduce los errores ORA con el conversor de DBAL,
 * así ErroresListener los clasifica igual que cualquier otra sentencia.
 * Solo para filas sin CLOB (los CLOB van con UUID y DBAL normal).
 */
final class InsercionOracle
{
    /**
     * @param array<string, scalar|null> $datos columna => valor
     */
    public static function insertar(Connection $conexion, string $tabla, array $datos, string $clave): string
    {
        self::exigirIdentificador($tabla);
        self::exigirIdentificador($clave);
        $columnas = [];
        $marcas = [];
        $valores = [];
        $i = 0;
        foreach ($datos as $columna => $valor) {
            self::exigirIdentificador($columna);
            $columnas[] = "\"{$columna}\"";
            $marcas[] = ":p{$i}";
            $valores[":p{$i}"] = null === $valor ? null : (\is_bool($valor) ? ($valor ? '1' : '0') : (string) $valor);
            ++$i;
        }
        $sql = "INSERT INTO \"{$tabla}\" (".implode(', ', $columnas).') VALUES ('.implode(', ', $marcas)
            .") RETURNING \"{$clave}\" INTO :retorno";

        $nativa = $conexion->getNativeConnection();
        $sentencia = oci_parse($nativa, $sql);
        if (false === $sentencia) {
            throw new \RuntimeException('No se pudo preparar la inserción.');
        }
        foreach ($valores as $marca => &$valor) {
            oci_bind_by_name($sentencia, $marca, $valor, -1);
        }
        unset($valor);
        $retorno = '';
        oci_bind_by_name($sentencia, ':retorno', $retorno, 64);

        $modo = $conexion->isTransactionActive() ? \OCI_NO_AUTO_COMMIT : \OCI_COMMIT_ON_SUCCESS;
        if (!@oci_execute($sentencia, $modo)) {
            $error = ErrorOci8::new($sentencia);
            oci_free_statement($sentencia);
            throw $conexion->getDriver()->getExceptionConverter()->convert($error, null);
        }
        oci_free_statement($sentencia);

        return (string) $retorno;
    }

    private static function exigirIdentificador(string $nombre): void
    {
        if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/', $nombre)) {
            throw new \InvalidArgumentException("Identificador inválido: {$nombre}");
        }
    }
}
