<?php

declare(strict_types=1);

namespace App\Esquema;

use Doctrine\DBAL\Connection;

/**
 * Permisos del usuario de ejecución de la API (mínimo privilegio). Puede:
 * - SELECT / INSERT / UPDATE / DELETE sobre las tablas del negocio;
 * - EXECUTE sobre INV_CTX (fijar su contexto) e INV_KARDEX_PKG (verificar la cadena);
 * - SELECT (solo lectura) sobre INV_VPD_PREDICADO: la primera capa del alcance
 *   (App\Seguridad\Alcance) y la verificación del VPD (App\Db\ContextoRls) leen
 *   los mismos predicados que aplica la base.
 * Nada más: no puede alterar políticas VPD, disparadores, predicados, el
 * centinela del kardex ni el registro de migraciones (una inyección SQL no
 * desactiva el alcance por sede ni la inmutabilidad de la bitácora).
 *
 * Convergente: concede lo que falta y revoca lo que sobra, así las tablas de
 * migraciones futuras quedan concedidas en el mismo despliegue.
 */
final readonly class PermisosEjecucion
{
    /** Tablas internas: el usuario de ejecución no las toca. */
    public const array TABLAS_PROTEGIDAS = ['INV_KARDEX_SELLO', 'SYS_MIGRACION_ESQUEMA', 'ALEMBIC_VERSION'];
    /** Tablas internas que se leen pero no se modifican. */
    public const array SOLO_LECTURA = ['INV_VPD_PREDICADO'];
    public const array PAQUETES = ['INV_CTX', 'INV_KARDEX_PKG'];
    private const array DML = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    public function __construct(private Connection $conexion)
    {
    }

    /** @return list<string> cambios (vacía si ya estaban) */
    public function conceder(string $usuario): array
    {
        $usuario = strtoupper($usuario);
        if (1 !== preg_match('/^[A-Z][A-Z0-9_$#]{0,127}$/', $usuario)) {
            throw new \InvalidArgumentException("Usuario de ejecución inválido: {$usuario}");
        }
        if (false === $this->conexion->fetchOne('SELECT 1 FROM all_users WHERE username = :u', ['u' => $usuario])) {
            throw new \RuntimeException("No existe el usuario de ejecución {$usuario}.");
        }
        $esperados = $this->esperados();
        $actuales = $this->actuales($usuario);
        $cambios = [];
        foreach ($esperados as $objeto => $privilegios) {
            $faltan = array_values(array_diff($privilegios, $actuales[$objeto] ?? []));
            if ([] !== $faltan) {
                $this->conexion->executeStatement('GRANT '.implode(', ', $faltan)." ON \"{$objeto}\" TO \"{$usuario}\"");
                $cambios[] = 'concedido '.implode(', ', $faltan)." sobre {$objeto}";
            }
        }
        foreach ($actuales as $objeto => $privilegios) {
            $sobran = array_values(array_diff($privilegios, $esperados[$objeto] ?? []));
            if ([] !== $sobran) {
                $this->conexion->executeStatement('REVOKE '.implode(', ', $sobran)." ON \"{$objeto}\" FROM \"{$usuario}\"");
                $cambios[] = 'revocado '.implode(', ', $sobran)." sobre {$objeto}";
            }
        }

        return $cambios;
    }

    /** @return array<string, list<string>> objeto => privilegios que debe tener */
    public function esperados(): array
    {
        $esperados = [];
        foreach ($this->conexion->fetchFirstColumn('SELECT table_name FROM user_tables ORDER BY table_name') as $tabla) {
            $tabla = (string) $tabla;
            // Tablas maestras de los trabajos de Data Pump (respaldos en curso): no son de la aplicación.
            if (\in_array($tabla, self::TABLAS_PROTEGIDAS, true) || 1 === preg_match('/^SYS_(EXPORT|IMPORT|SQL_FILE)_/', $tabla)) {
                continue;
            }
            $esperados[$tabla] = \in_array($tabla, self::SOLO_LECTURA, true) ? ['SELECT'] : self::DML;
        }
        foreach (self::PAQUETES as $paquete) {
            $esperados[$paquete] = ['EXECUTE'];
        }

        return $esperados;
    }

    /** @return array<string, list<string>> */
    private function actuales(string $usuario): array
    {
        $actuales = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT table_name, privilege FROM user_tab_privs_made WHERE grantee = :u', ['u' => $usuario]) as $f) {
            $tabla = (string) $f['TABLE_NAME'];
            if (1 === preg_match('/^SYS_(EXPORT|IMPORT|SQL_FILE)_/', $tabla)) {
                continue;
            }
            $actuales[$tabla][] = (string) $f['PRIVILEGE'];
        }

        return $actuales;
    }
}
