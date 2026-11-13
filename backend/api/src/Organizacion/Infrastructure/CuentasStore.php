<?php

declare(strict_types=1);

namespace App\Organizacion\Infrastructure;

use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Organizacion\Entidades;
use Doctrine\DBAL\Connection;

/** User accounts as the administration screens read and write them (with person, sites and sessions): all that SQL lives here. */
final readonly class CuentasStore
{
    private const string CUENTA = 'SELECT u.*, f."UFO_Fecha_Modificacion" AS "FOTO_EN" FROM "INV_USUARIO" u LEFT JOIN "INV_USUARIO_FOTO" f ON f."USU_Usuario" = u."USU_Usuario"';

    public function __construct(private Connection $conexion)
    {
    }

    /** @return list<array<string, mixed>> every account with the date of its photo, oldest first */
    public function todas(): array
    {
        return $this->conexion->fetchAllAssociative(self::CUENTA.' ORDER BY u."USU_Creado_En", u."USU_Username"');
    }

    /** @return array<string, mixed>|false the account with the date of its photo */
    public function porId(string $usuario): array|false
    {
        return $this->conexion->fetchAssociative(self::CUENTA.' WHERE u."USU_Usuario" = :id', ['id' => $usuario]);
    }

    /** Locks the account row until the transaction ends. */
    public function bloquear(string $usuario): void
    {
        $this->conexion->fetchOne('SELECT 1 FROM "INV_USUARIO" WHERE "USU_Usuario" = :id FOR UPDATE', ['id' => $usuario]);
    }

    /** @return list<array<string, mixed>> the people who have an account, with the columns of the person entity */
    public function personasConCuenta(): array
    {
        return $this->conexion->fetchAllAssociative('SELECT '.Entidades::persona()->columnasSelect().' FROM "INV_PERSONA" t '
            .'WHERE t."PER_Persona" IN (SELECT "PER_Persona" FROM "INV_USUARIO")');
    }

    /** @return array<string, mixed>|false a person with the columns of the person entity */
    public function persona(mixed $persona): array|false
    {
        return $this->conexion->fetchAssociative('SELECT '.Entidades::persona()->columnasSelect().' FROM "INV_PERSONA" t WHERE t."PER_Persona" = :p', ['p' => $persona]);
    }

    /** @return array<string, mixed>|false the whole person row */
    public function personaCompleta(string $persona): array|false
    {
        return $this->conexion->fetchAssociative('SELECT * FROM "INV_PERSONA" WHERE "PER_Persona" = :p', ['p' => $persona]);
    }

    public function personaTieneCuenta(string $persona): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_USUARIO" WHERE "PER_Persona" = :p', ['p' => $persona]);
    }

    /** @return list<array<string, mixed>> USU_Usuario, SED_Sede and SED_Nombre of every site assigned to an account, by site name */
    public function sedesPorUsuario(): array
    {
        return $this->conexion->fetchAllAssociative('SELECT us."USU_Usuario", s."SED_Sede", s."SED_Nombre" FROM "INV_SEDE" s '
            .'JOIN "INV_USUARIO_SEDE" us ON us."SED_Sede" = s."SED_Sede" ORDER BY s."SED_Nombre"');
    }

    /** @return list<array<string, mixed>> SED_Sede and SED_Nombre of the sites of an account, by name */
    public function sedesDe(string $usuario): array
    {
        return $this->conexion->fetchAllAssociative(
            'SELECT s."SED_Sede", s."SED_Nombre" FROM "INV_SEDE" s JOIN "INV_USUARIO_SEDE" us ON us."SED_Sede" = s."SED_Sede" '
            .'WHERE us."USU_Usuario" = :id ORDER BY s."SED_Nombre"', ['id' => $usuario]);
    }

    public function existeSede(int $sede): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_SEDE" WHERE "SED_Sede" = :s', ['s' => $sede]);
    }

    /** @param list<int> $sedes */
    public function reemplazarSedes(string $usuario, array $sedes): void
    {
        $this->conexion->executeStatement('DELETE FROM "INV_USUARIO_SEDE" WHERE "USU_Usuario" = :u', ['u' => $usuario]);
        foreach ($sedes as $sede) {
            $this->conexion->executeStatement('INSERT INTO "INV_USUARIO_SEDE" ("USU_Usuario", "SED_Sede") VALUES (:u, :s)', ['u' => $usuario, 's' => $sede]);
        }
    }

    /**
     * Creates an active account without failed attempts or second factor.
     *
     * @param array{id: string, u: mixed, h: ?string, r: mixed, sso: int, prov: mixed, g: int, debe: int, p: string} $cuenta id, username, password hash, role,
     *                                                                                                                 SSO enabled, SSO provider, global scope, must change password, person
     */
    public function crear(array $cuenta, \DateTimeImmutable $ahora): void
    {
        $this->conexion->executeStatement(
            'INSERT INTO "INV_USUARIO" ("USU_Usuario", "USU_Username", "USU_Password_Hash", "USU_Rol", "USU_Estado", '
            .'"USU_SSO_Habilitado", "USU_SSO_Provider", "USU_Intentos_Fallidos", "USU_Bloqueos_Consecutivos", '
            .'"USU_2FA_Habilitado", "USU_Alcance_Global", "USU_Debe_Cambiar_Password", "PER_Persona", "USU_Creado_En") '
            .'VALUES (:id, :u, :h, :r, 1, :sso, :prov, 0, 0, 0, :g, :debe, :p, :ahora)',
            [...$cuenta, 'ahora' => Reloj::aBd($ahora)],
        );
    }

    /** @param non-empty-array<string, mixed> $cambios column => value; the column names come from the code, never from the request */
    public function actualizar(string $usuario, array $cambios): void
    {
        $sets = [];
        foreach (array_keys($cambios) as $columna) {
            $sets[] = "\"{$columna}\" = :{$columna}";
        }
        $this->conexion->executeStatement('UPDATE "INV_USUARIO" SET '.implode(', ', $sets).' WHERE "USU_Usuario" = :id', [...$cambios, 'id' => $usuario]);
    }

    public function superAdministradoresActivos(): int
    {
        return (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_USUARIO" WHERE "USU_Rol" = :r AND "USU_Estado" = 1', ['r' => Roles::SUPER_ADMIN]);
    }

    public function exigirCambioDeContrasena(string $usuario): void
    {
        $this->conexion->executeStatement('UPDATE "INV_USUARIO" SET "USU_Debe_Cambiar_Password" = 1 WHERE "USU_Usuario" = :id', ['id' => $usuario]);
    }

    /** @return list<array<string, mixed>> the open sessions with their account and person, most recently active first */
    public function sesionesAbiertas(\DateTimeImmutable $ahora): array
    {
        return $this->conexion->fetchAllAssociative(
            'SELECT s."SES_Sesion", u."USU_Username", u."USU_Rol", p."PER_Primer_Nombre", p."PER_Primer_Apellido" FROM "SYS_SESION" s '
            .'JOIN "INV_USUARIO" u ON u."USU_Usuario" = s."USU_Usuario" LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = u."PER_Persona" '
            .'WHERE s."SES_Cerrada_En" IS NULL AND s."SES_Expira" > :ahora ORDER BY s."SES_Ultima_Actividad" DESC',
            ['ahora' => Reloj::aBd($ahora)],
        );
    }

    public function cuantasSesionesAbiertas(\DateTimeImmutable $ahora): int
    {
        return (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "SYS_SESION" WHERE "SES_Cerrada_En" IS NULL AND "SES_Expira" > :a', ['a' => Reloj::aBd($ahora)]);
    }

    /** @return list<array<string, mixed>> what the security summary needs of every account: role, state, factors, locks and how many sites (SEDES) */
    public function resumenDeAcceso(): array
    {
        return $this->conexion->fetchAllAssociative(
            'SELECT u."USU_Usuario", u."USU_Rol", u."USU_Estado", u."USU_2FA_Habilitado", u."USU_SSO_Habilitado", u."USU_Alcance_Global", '
            .'u."USU_Bloqueado_Hasta", u."USU_Bloqueo_Admin_Motivo", u."USU_Bloqueo_Admin_Hasta", u."USU_Debe_Cambiar_Password", '
            .'(SELECT COUNT(*) FROM "INV_USUARIO_SEDE" us WHERE us."USU_Usuario" = u."USU_Usuario") AS "SEDES" '
            .'FROM "INV_USUARIO" u',
        );
    }
}
