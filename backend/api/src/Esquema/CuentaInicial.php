<?php

declare(strict_types=1);

namespace App\Esquema;

use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Seguridad\Contrasenas;
use Doctrine\DBAL\Connection;

/**
 * Cuenta raíz de una instalación nueva (idempotente): configuración base,
 * departamento y cargo «SysAdmin», la persona y el usuario SUPER_ADMIN. Si el
 * usuario ya existe no hace nada (nunca cambia su contraseña ni su rol).
 */
final readonly class CuentaInicial
{
    public function __construct(private Connection $conexion)
    {
    }

    /**
     * @return array{creada: bool, contrasena_generada: ?string} la contraseña
     *     generada solo se devuelve si no se indicó una (para mostrarla una vez)
     */
    public function asegurar(string $usuario, string $correo, ?string $contrasena, string $empresa): array
    {
        if (false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_USUARIO" WHERE "USU_Username" = :u', ['u' => $usuario])) {
            return ['creada' => false, 'contrasena_generada' => null];
        }
        $generada = null === $contrasena || '' === $contrasena ? rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=') : null;
        $clave = $generada ?? (string) $contrasena;

        $this->conexion->transactional(function (Connection $c) use ($usuario, $correo, $clave, $generada, $empresa): void {
            if (false === $c->fetchOne('SELECT 1 FROM "SYS_CONFIGURACION" WHERE "SYS_Configuracion" = 1')) {
                $c->executeStatement('INSERT INTO "SYS_CONFIGURACION" ("SYS_Configuracion", "SYS_Nombre_Empresa", "SYS_Color_Primario", '
                    .'"SYS_Color_Secundario", "SYS_Color_Fondo", "SYS_Idioma_Defecto") VALUES (1, :e, :p, :s, :f, :i)',
                    ['e' => $empresa, 'p' => '#4a7c9e', 's' => '#5a8e7a', 'f' => '#f5f3ef', 'i' => 'es']);
            }
            $departamento = $this->insertarConClave($c, 'INSERT INTO "INV_DEPARTAMENTO" ("DEP_Nombre", "DEP_Codigo_Costos", "DEP_Descripcion", "DEP_Activo") '
                .'VALUES (:n, :c, :d, 1)', ['n' => 'SysAdmin', 'c' => 'SYS-000', 'd' => 'Cuenta raíz del sistema'],
                'SELECT MAX("DEP_Departamento") FROM "INV_DEPARTAMENTO" WHERE "DEP_Nombre" = :n', ['n' => 'SysAdmin']);
            $cargo = $this->insertarConClave($c, 'INSERT INTO "INV_CARGO" ("CAR_Nombre", "CAR_Es_Jefatura") VALUES (:n, 1)', ['n' => 'SysAdmin'],
                'SELECT MAX("CAR_Cargo") FROM "INV_CARGO" WHERE "CAR_Nombre" = :n', ['n' => 'SysAdmin']);
            $persona = Ident::nuevo();
            $c->executeStatement('INSERT INTO "INV_PERSONA" ("PER_Persona", "PER_Primer_Nombre", "PER_Primer_Apellido", "PER_Email_Corporativo", '
                .'"PER_Estado", "DEP_Departamento", "CAR_Cargo") VALUES (:id, :n, :a, :c, 1, :d, :car)',
                ['id' => $persona, 'n' => 'System', 'a' => 'Administrator', 'c' => $correo, 'd' => $departamento, 'car' => $cargo]);
            $c->executeStatement(
                'INSERT INTO "INV_USUARIO" ("USU_Usuario", "USU_Username", "USU_Password_Hash", "USU_Rol", "USU_Estado", '
                .'"USU_SSO_Habilitado", "USU_Intentos_Fallidos", "USU_Bloqueos_Consecutivos", "USU_2FA_Habilitado", '
                .'"USU_Alcance_Global", "USU_Debe_Cambiar_Password", "PER_Persona", "USU_Creado_En") '
                .'VALUES (:id, :u, :h, :r, 1, 0, 0, 0, 0, 0, :debe, :p, :ahora)',
                ['id' => Ident::nuevo(), 'u' => $usuario, 'h' => Contrasenas::hashear($clave), 'r' => Roles::SUPER_ADMIN,
                    // Una contraseña generada es temporal: se cambia en el primer inicio de sesión.
                    'debe' => null !== $generada ? 1 : 0, 'p' => $persona, 'ahora' => Reloj::aBd(Reloj::ahora())],
            );
        });

        return ['creada' => true, 'contrasena_generada' => $generada];
    }

    /**
     * @param array<string, mixed> $parametros
     * @param array<string, mixed> $parametrosClave
     */
    private function insertarConClave(Connection $c, string $insercion, array $parametros, string $consulta, array $parametrosClave): int
    {
        // Puede existir ya: una cuenta raíz con otro nombre comparte departamento y cargo.
        $existente = $c->fetchOne($consulta, $parametrosClave);
        if (null !== $existente && false !== $existente) {
            return (int) $existente;
        }
        $c->executeStatement($insercion, $parametros);

        return (int) $c->fetchOne($consulta, $parametrosClave);
    }
}
