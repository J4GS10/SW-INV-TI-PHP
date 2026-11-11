<?php

declare(strict_types=1);

namespace App\Organizacion;

use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Seguridad\Contrasenas;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Test accounts, one or two per role (docs/PRUEBAS_POR_ROL.md), with the places they need:
 * the country Guatemala, «Sede Prueba Norte» and «Sede Prueba Sur», the department «Pruebas»
 * and the position «Colaborador de prueba». Idempotent: an existing account keeps its id and
 * history and only gets the shared password again (not temporary).
 *
 * The password comes from TEST_ACCOUNTS_PASSWORD (never an argument: it would stay in the
 * shell history). Run as the schema owner, like the migrator:
 *   docker compose … run --rm -e TEST_ACCOUNTS_PASSWORD migrador php bin/console app:test-accounts
 * Before real use these accounts are deactivated (docs/PENDIENTES.md).
 */
#[AsCommand('app:test-accounts', 'Crea o restablece las cuentas de prueba por rol (clave en TEST_ACCOUNTS_PASSWORD).')]
final readonly class TestAccountsCommand
{
    /** username => [role, first name, last name, site ('' = all sites)] */
    private const array ACCOUNTS = [
        'sistema2' => [Roles::SUPER_ADMIN, 'Prueba', 'Sistema Dos', ''],
        'seguridad1' => [Roles::ADMIN_SEGURIDAD, 'Prueba', 'Seguridad Uno', ''],
        'adminti1' => [Roles::ADMIN_TI, 'Prueba', 'TI Uno', ''],
        'adminti2' => [Roles::ADMIN_TI, 'Prueba', 'TI Dos', ''],
        'tecnico1' => [Roles::TECNICO, 'Prueba', 'Técnico Norte', 'Sede Prueba Norte'],
        'tecnico2' => [Roles::TECNICO, 'Prueba', 'Técnico Sur', 'Sede Prueba Sur'],
        'compras1' => [Roles::COMPRAS, 'Prueba', 'Compras Uno', ''],
        'auditor1' => [Roles::AUDITOR, 'Prueba', 'Auditor Uno', ''],
        'consulta1' => [Roles::CONSULTA, 'Prueba', 'Consulta Norte', 'Sede Prueba Norte'],
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(OutputInterface $output): int
    {
        $password = getenv('TEST_ACCOUNTS_PASSWORD');
        if (!\is_string($password) || '' === $password) {
            $output->writeln('<error>Defina TEST_ACCOUNTS_PASSWORD con la clave compartida de las cuentas de prueba.</error>');

            return Command::FAILURE;
        }
        $hash = Contrasenas::hashear($password);

        $this->connection->transactional(function (Connection $c) use ($hash, $output): void {
            $country = $this->ensure($c, 'INV_PAIS', 'PAI_Pais', ['PAI_Nombre' => 'Guatemala'], ['PAI_Codigo_ISO' => 'GT']);
            $state = $this->ensure($c, 'INV_ESTADO', 'EST_Estado', ['EST_Nombre' => 'Guatemala', 'PAI_Pais' => $country]);
            $city = $this->ensure($c, 'INV_MUNICIPIO', 'MUN_Municipio', ['MUN_Nombre' => 'Guatemala', 'EST_Estado' => $state]);
            $sites = [];
            foreach (['Sede Prueba Norte', 'Sede Prueba Sur'] as $name) {
                $sites[$name] = $this->ensure($c, 'INV_SEDE', 'SED_Sede', ['SED_Nombre' => $name], ['MUN_Municipio' => $city]);
            }
            $department = $this->ensure($c, 'INV_DEPARTAMENTO', 'DEP_Departamento', ['DEP_Nombre' => 'Pruebas'],
                ['DEP_Codigo_Costos' => 'PRU-001', 'DEP_Descripcion' => 'Cuentas de prueba por rol', 'DEP_Activo' => 1]);
            $position = $this->ensure($c, 'INV_CARGO', 'CAR_Cargo', ['CAR_Nombre' => 'Colaborador de prueba'], ['CAR_Es_Jefatura' => 0]);

            foreach (self::ACCOUNTS as $username => [$role, $firstName, $lastName, $site]) {
                $siteId = $sites[$site] ?? null;
                $existing = $c->fetchOne('SELECT "USU_Usuario" FROM "INV_USUARIO" WHERE "USU_Username" = :u', ['u' => $username]);
                if (false !== $existing) {
                    $c->executeStatement('UPDATE "INV_USUARIO" SET "USU_Password_Hash" = :h, "USU_Debe_Cambiar_Password" = 0, "USU_Estado" = 1, '
                        .'"USU_Intentos_Fallidos" = 0, "USU_Bloqueado_Hasta" = NULL, "USU_Bloqueos_Consecutivos" = 0, '
                        .'"USU_Bloqueo_Admin_Motivo" = NULL, "USU_Bloqueo_Admin_Hasta" = NULL WHERE "USU_Usuario" = :id',
                        ['h' => $hash, 'id' => $existing]);
                    $output->writeln("  {$username} ({$role}): clave restablecida");
                    continue;
                }
                $person = Ident::nuevo();
                $c->executeStatement('INSERT INTO "INV_PERSONA" ("PER_Persona", "PER_Primer_Nombre", "PER_Primer_Apellido", "PER_Email_Corporativo", '
                    .'"PER_Estado", "DEP_Departamento", "CAR_Cargo", "SED_Sede") VALUES (:id, :n, :a, :e, 1, :d, :c, :s)',
                    ['id' => $person, 'n' => $firstName, 'a' => $lastName, 'e' => "{$username}@pruebas.local", 'd' => $department, 'c' => $position, 's' => $siteId]);
                $user = Ident::nuevo();
                $c->executeStatement(
                    'INSERT INTO "INV_USUARIO" ("USU_Usuario", "USU_Username", "USU_Password_Hash", "USU_Rol", "USU_Estado", "USU_SSO_Habilitado", '
                    .'"USU_Intentos_Fallidos", "USU_Bloqueos_Consecutivos", "USU_2FA_Habilitado", "USU_Alcance_Global", "USU_Debe_Cambiar_Password", '
                    .'"PER_Persona", "USU_Creado_En") VALUES (:id, :u, :h, :r, 1, 0, 0, 0, 0, :g, 0, :p, :ahora)',
                    ['id' => $user, 'u' => $username, 'h' => $hash, 'r' => $role, 'g' => null === $siteId ? 1 : 0, 'p' => $person, 'ahora' => Reloj::aBd(Reloj::ahora())],
                );
                $scope = ', todas las sedes';
                if (\is_int($siteId)) {
                    $c->executeStatement('INSERT INTO "INV_USUARIO_SEDE" ("USU_Usuario", "SED_Sede") VALUES (:u, :s)', ['u' => $user, 's' => $siteId]);
                    $scope = ", {$site}";
                }
                $output->writeln("  {$username} ({$role}): creada{$scope}");
            }
        });
        $output->writeln('Cuentas de prueba listas. La clave es la de TEST_ACCOUNTS_PASSWORD.');

        return Command::SUCCESS;
    }

    /**
     * Id of the row that matches `$key`, inserting it with `$extra` if there is none.
     *
     * @param array<string, int|string> $key
     * @param array<string, int|string> $extra
     */
    private function ensure(Connection $c, string $table, string $id, array $key, array $extra = []): int
    {
        $where = implode(' AND ', array_map(static fn (string $col): string => "\"{$col}\" = :{$col}", array_keys($key)));
        $found = $c->fetchOne("SELECT MAX(\"{$id}\") FROM \"{$table}\" WHERE {$where}", $key);
        if (null !== $found && false !== $found) {
            return (int) $found;
        }
        $row = [...$key, ...$extra];
        $c->executeStatement("INSERT INTO \"{$table}\" (\"".implode('", "', array_keys($row)).'") VALUES (:'.implode(', :', array_keys($row)).')', $row);

        return (int) $c->fetchOne("SELECT MAX(\"{$id}\") FROM \"{$table}\" WHERE {$where}", $key);
    }
}
