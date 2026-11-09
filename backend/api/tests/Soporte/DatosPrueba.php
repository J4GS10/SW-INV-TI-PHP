<?php

declare(strict_types=1);

namespace App\Tests\Soporte;

use App\Db\SesionOracleMiddleware;
use App\Dominio\Ident;
use App\Seguridad\Contrasenas;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Datos de prueba en el Oracle desechable (herramientas/oracle_pruebas.sh).
 * Usa su propia conexión SIN contexto VPD (modo sistema) y nombres únicos por
 * prueba: la bitácora es de solo inserción, así que no se limpia entre pruebas.
 */
final class DatosPrueba
{
    public const string PASSWORD = 'Prueba.Segura#2026';

    private static ?Connection $conexion = null;

    public static function conexion(): Connection
    {
        if (null === self::$conexion) {
            $e = static fn (string $k, string $d = ''): string => (string) ($_SERVER[$k] ?? $_ENV[$k] ?? $d);
            self::$conexion = DriverManager::getConnection([
                'driver' => 'oci8',
                'host' => $e('ORACLE_HOST'),
                'port' => (int) $e('ORACLE_PORT', '1521'),
                'dbname' => $e('ORACLE_SERVICE_NAME', 'XEPDB1'),
                'servicename' => $e('ORACLE_SERVICE_NAME', 'XEPDB1'),
                'service' => true,
                'user' => $e('ORACLE_USER'),
                'password' => $e('ORACLE_PASSWORD'),
                'charset' => 'AL32UTF8',
                // Sesión propia: sin esto compartiría la de la aplicación y su contexto VPD.
                'driverOptions' => ['exclusive' => true],
            ]);
            foreach (SesionOracleMiddleware::sentencias('', '') as $sql) {
                self::$conexion->executeStatement($sql);
            }
        }

        return self::$conexion;
    }

    public static function sufijo(): string
    {
        return bin2hex(random_bytes(4));
    }

    public static function crearSede(string $nombre): int
    {
        $c = self::conexion();
        $municipio = self::municipio();
        $c->executeStatement(
            'INSERT INTO "INV_SEDE" ("SED_Nombre", "MUN_Municipio") VALUES (:n, :m)',
            ['n' => $nombre, 'm' => $municipio],
        );

        return (int) $c->fetchOne('SELECT MAX("SED_Sede") FROM "INV_SEDE" WHERE "SED_Nombre" = :n', ['n' => $nombre]);
    }

    public static function crearPersona(?int $sede, string $nombre = 'Persona'): string
    {
        $c = self::conexion();
        $id = Ident::nuevo();
        $c->executeStatement(
            'INSERT INTO "INV_PERSONA" ("PER_Persona", "PER_Primer_Nombre", "PER_Primer_Apellido", '
            .'"PER_Email_Corporativo", "PER_Estado", "DEP_Departamento", "CAR_Cargo", "SED_Sede") '
            .'VALUES (:id, :nombre, :apellido, :correo, 1, :dep, :car, :sede)',
            [
                'id' => $id, 'nombre' => $nombre, 'apellido' => 'Prueba', 'correo' => "p{$id}@pruebas.local",
                'dep' => self::catalogo('INV_DEPARTAMENTO', 'DEP', ['DEP_Activo' => 1]),
                'car' => self::catalogo('INV_CARGO', 'CAR', ['CAR_Es_Jefatura' => 0]),
                'sede' => $sede,
            ],
        );

        return $id;
    }

    /**
     * @param array{rol?: string, hash?: string, activo?: bool, global?: bool, sedes?: list<int>,
     *     debe_cambiar?: bool, metodo_2fa?: string, secreto_totp?: string, sede_persona?: int, sso?: string} $o
     *
     * @return array{id: string, username: string, persona: string, correo: string}
     */
    public static function crearUsuario(array $o = []): array
    {
        $c = self::conexion();
        $persona = self::crearPersona($o['sede_persona'] ?? ($o['sedes'][0] ?? null));
        $id = Ident::nuevo();
        $username = 'qa_'.self::sufijo();
        $c->executeStatement(
            'INSERT INTO "INV_USUARIO" ("USU_Usuario", "USU_Username", "USU_Password_Hash", "USU_Rol", "USU_Estado", '
            .'"USU_SSO_Habilitado", "USU_Intentos_Fallidos", "USU_Bloqueos_Consecutivos", "USU_Debe_Cambiar_Password", '
            .'"USU_2FA_Habilitado", "USU_2FA_Metodo", "USU_2FA_Secret", "USU_Alcance_Global", "PER_Persona", "USU_SSO_Provider", "USU_Creado_En") '
            // Sin fecha de alta: ni gracia del MFA ni inactividad por antigüedad (MfaFlexibleTest fija otra fecha).
            .'VALUES (:id, :username, :hash, :rol, :activo, :sso, 0, 0, :debe, :mfa, :metodo, :secreto, :global, :persona, :proveedor, NULL)',
            [
                'id' => $id,
                'username' => $username,
                'hash' => $o['hash'] ?? Contrasenas::hashear(self::PASSWORD),
                'rol' => $o['rol'] ?? 'TECNICO',
                'activo' => ($o['activo'] ?? true) ? 1 : 0,
                'debe' => ($o['debe_cambiar'] ?? false) ? 1 : 0,
                'mfa' => isset($o['metodo_2fa']) ? 1 : 0,
                'metodo' => $o['metodo_2fa'] ?? null,
                'secreto' => isset($o['secreto_totp']) ? self::fernet()->cifrar($o['secreto_totp']) : null,
                'global' => ($o['global'] ?? false) ? 1 : 0,
                'persona' => $persona,
                // 'cualquiera' = SSO habilitado sin proveedor fijo.
                'sso' => isset($o['sso']) ? 1 : 0,
                'proveedor' => isset($o['sso']) && 'cualquiera' !== $o['sso'] ? $o['sso'] : null,
            ],
        );
        foreach ($o['sedes'] ?? [] as $sede) {
            $c->executeStatement(
                'INSERT INTO "INV_USUARIO_SEDE" ("USU_Usuario", "SED_Sede") VALUES (:u, :s)',
                ['u' => $id, 's' => $sede],
            );
        }

        return ['id' => $id, 'username' => $username, 'persona' => $persona, 'correo' => "p{$persona}@pruebas.local"];
    }

    /** Fernet con la FIELD_ENCRYPTION_KEY de pruebas (secretos como los guarda la aplicación). */
    public static function fernet(): \App\Seguridad\Fernet
    {
        return new \App\Seguridad\Fernet(explode(',', (string) ($_SERVER['FIELD_ENCRYPTION_KEY'] ?? $_ENV['FIELD_ENCRYPTION_KEY'])));
    }

    /** @param array<string, mixed> $parametros */
    public static function contar(string $sql, array $parametros = []): int
    {
        return (int) self::conexion()->fetchOne($sql, $parametros);
    }

    private static function municipio(): int
    {
        $c = self::conexion();
        $pais = self::catalogo('INV_PAIS', 'PAI', ['PAI_Codigo_ISO' => 'ZQ'], 'País de pruebas');
        $estado = $c->fetchOne('SELECT MIN("EST_Estado") FROM "INV_ESTADO" WHERE "PAI_Pais" = :p', ['p' => $pais]);
        if (null === $estado) {
            $c->executeStatement('INSERT INTO "INV_ESTADO" ("EST_Nombre", "PAI_Pais") VALUES (:n, :p)', ['n' => 'Estado de pruebas', 'p' => $pais]);
            $estado = $c->fetchOne('SELECT MIN("EST_Estado") FROM "INV_ESTADO" WHERE "PAI_Pais" = :p', ['p' => $pais]);
        }
        $municipio = $c->fetchOne('SELECT MIN("MUN_Municipio") FROM "INV_MUNICIPIO" WHERE "EST_Estado" = :e', ['e' => $estado]);
        if (null === $municipio) {
            $c->executeStatement('INSERT INTO "INV_MUNICIPIO" ("MUN_Nombre", "EST_Estado") VALUES (:n, :e)', ['n' => 'Municipio de pruebas', 'e' => $estado]);
            $municipio = $c->fetchOne('SELECT MIN("MUN_Municipio") FROM "INV_MUNICIPIO" WHERE "EST_Estado" = :e', ['e' => $estado]);
        }

        return (int) $municipio;
    }

    /**
     * Fila única de un catálogo de prueba (la crea la primera vez).
     *
     * @param array<string, int|string> $extra
     */
    private static function catalogo(string $tabla, string $prefijo, array $extra, string $nombre = 'Catálogo de pruebas'): int
    {
        $c = self::conexion();
        $id = "\"{$prefijo}_".substr($tabla, 4, 1).strtolower(substr($tabla, 5)).'"';
        $columnaNombre = "\"{$prefijo}_Nombre\"";
        $buscar = "SELECT MIN({$id}) FROM \"{$tabla}\" WHERE {$columnaNombre} = :n";
        $valor = $c->fetchOne($buscar, ['n' => $nombre]);
        if (null === $valor) {
            $columnas = [$columnaNombre, ...array_map(static fn (string $k): string => "\"{$k}\"", array_keys($extra))];
            $marcas = [':n', ...array_map(static fn (int $i): string => ":x{$i}", array_keys(array_values($extra)))];
            $parametros = ['n' => $nombre];
            foreach (array_values($extra) as $i => $v) {
                $parametros["x{$i}"] = $v;
            }
            $c->executeStatement(
                "INSERT INTO \"{$tabla}\" (".implode(', ', $columnas).') VALUES ('.implode(', ', $marcas).')',
                $parametros,
            );
            $valor = $c->fetchOne($buscar, ['n' => $nombre]);
        }

        return (int) $valor;
    }
}
