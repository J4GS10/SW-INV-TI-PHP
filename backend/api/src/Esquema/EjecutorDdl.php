<?php

declare(strict_types=1);

namespace App\Esquema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\OCI8\Exception\Error as ErrorOci8;

/**
 * DDL y PL/SQL directo sobre la sesión oci8 de DBAL. Sin pasar por el analizador
 * de parámetros de DBAL: en un disparador `:NEW` y `:OLD` no son parámetros.
 * Tras crear un objeto PL/SQL se comprueba USER_ERRORS: Oracle acepta el
 * CREATE aunque el código no compile (ORA-24344) y lo deja INVÁLIDO.
 */
final readonly class EjecutorDdl
{
    public function __construct(private Connection $conexion)
    {
    }

    public function ejecutar(string $ddl): void
    {
        $nativa = $this->conexion->getNativeConnection();
        $sentencia = oci_parse($nativa, $ddl);
        if (false === $sentencia) {
            throw new \RuntimeException('No se pudo preparar la sentencia DDL.');
        }
        try {
            if (!@oci_execute($sentencia, \OCI_COMMIT_ON_SUCCESS)) {
                throw $this->conexion->getDriver()->getExceptionConverter()->convert(ErrorOci8::new($sentencia), null);
            }
        } finally {
            oci_free_statement($sentencia);
        }
    }

    public function crearPlsql(ObjetoPlsql $objeto): void
    {
        $this->ejecutar($objeto->ddl);
        $errores = $this->conexion->fetchAllAssociative(
            'SELECT line, position, text FROM user_errors WHERE name = :n AND type = :t ORDER BY sequence',
            ['n' => $objeto->nombre, 't' => $objeto->tipo],
        );
        if ([] !== $errores) {
            $primero = $errores[0];
            throw new \RuntimeException(\sprintf('%s no compila (línea %s, columna %s): %s',
                $objeto->etiqueta(), $primero['LINE'], $primero['POSITION'], trim((string) $primero['TEXT'])));
        }
    }
}
