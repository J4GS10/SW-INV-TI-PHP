<?php

declare(strict_types=1);

namespace App\Db;

use App\Config\Ajustes;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Prepara cada sesión nueva de Oracle:
 * - TIME_ZONE UTC: CURRENT_TIMESTAMP de los valores por defecto en UTC.
 * - Formatos NLS fijos: fechas con microsegundos y punto decimal, sin depender
 *   del idioma del Instant Client.
 * - CURRENT_SCHEMA: con un usuario de ejecución (INVENTARIO_APP) los nombres
 *   sin calificar resuelven en el esquema del dueño.
 */
#[AsMiddleware]
final readonly class SesionOracleMiddleware implements Middleware
{
    public function __construct(private Ajustes $ajustes)
    {
    }

    /** @return list<string> */
    public static function sentencias(string $esquema, string $usuarioConexion): array
    {
        $sentencias = [
            "ALTER SESSION SET TIME_ZONE = 'UTC'",
            "ALTER SESSION SET NLS_TIMESTAMP_FORMAT = 'YYYY-MM-DD HH24:MI:SS.FF6'",
            "ALTER SESSION SET NLS_TIMESTAMP_TZ_FORMAT = 'YYYY-MM-DD HH24:MI:SS.FF6 TZH:TZM'",
            "ALTER SESSION SET NLS_DATE_FORMAT = 'YYYY-MM-DD HH24:MI:SS'",
            "ALTER SESSION SET NLS_NUMERIC_CHARACTERS = '.,'",
        ];
        if ('' !== $esquema && $esquema !== $usuarioConexion) {
            if (1 !== preg_match('/^[A-Z][A-Z0-9_$#]{0,127}$/', $esquema)) {
                throw new \InvalidArgumentException('ORACLE_SCHEMA_OWNER inválido.');
            }
            $sentencias[] = "ALTER SESSION SET CURRENT_SCHEMA = \"{$esquema}\"";
        }

        return $sentencias;
    }

    public function wrap(Driver $driver): Driver
    {
        $sentencias = self::sentencias($this->ajustes->esquema(), $this->ajustes->usuarioConexion());

        return new class($driver, $sentencias) extends AbstractDriverMiddleware {
            /** @param list<string> $sentencias */
            public function __construct(Driver $driver, private readonly array $sentencias)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                $conexion = parent::connect($params);
                foreach ($this->sentencias as $sql) {
                    $conexion->exec($sql);
                }

                return $conexion;
            }
        };
    }
}
