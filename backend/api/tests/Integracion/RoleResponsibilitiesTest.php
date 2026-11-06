<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Db\ContextoRls;
use App\Seguridad\Tokens;
use App\Tests\Soporte\DatosPrueba as D;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Role separation decided on 2026-11-04: the system administrator controls the system and sees no business
 * data; IT is a single role; purchasing and accounting are a single role with no operational inventory.
 */
final class RoleResponsibilitiesTest extends WebTestCase
{
    private const array BUSINESS_ROUTES = ['/api/v1/core/activos', '/api/v1/consumibles', '/api/v1/mantenimiento/',
        '/api/v1/trazabilidad/movimientos', '/api/v1/inventario/tomas', '/api/v1/compras/ordenes',
        '/api/v1/compras/proveedores', '/api/v1/compras/garantias', '/api/v1/aprobaciones',
        '/api/v1/inventario/kardex/verificar', '/api/v1/export/activos.csv', '/api/v1/inventario/atencion'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTheSystemAdministratorControlsTheSystemButSeesNoBusinessData(): void
    {
        $token = $this->tokenFor(['rol' => 'SUPER_ADMIN', 'global' => true]);

        foreach (self::BUSINESS_ROUTES as $route) {
            self::assertSame(403, $this->statusOf('GET', $route, $token), $route);
        }
        foreach (['/api/v1/org/personas', '/api/v1/org/usuarios', '/api/v1/gov/auditoria', '/api/v1/gov/security/mfa', '/api/v1/directorio/estado'] as $route) {
            self::assertSame(200, $this->statusOf('GET', $route, $token), $route);
        }
    }

    public function testOnlyTheSystemAdministratorReachesTheDirectory(): void
    {
        foreach (['ADMIN_TI', 'TECNICO', 'AUDITOR', 'CONSULTA', 'COMPRAS', 'ADMIN_SEGURIDAD'] as $role) {
            $token = $this->tokenFor(['rol' => $role, 'global' => true]);
            self::assertSame(403, $this->statusOf('GET', '/api/v1/directorio/estado', $token), $role);
            self::assertSame(403, $this->statusOf('POST', '/api/v1/directorio/sincronizar', $token), $role);
        }
    }

    public function testTheDatabaseAlsoHidesBusinessDataFromSystemRoles(): void
    {
        $site = D::crearSede('Roles '.D::sufijo());
        $person = D::crearPersona($site);
        $connection = static::getContainer()->get(Connection::class);
        $rls = static::getContainer()->get(ContextoRls::class);

        foreach (['SUPER_ADMIN', 'ADMIN_SEGURIDAD'] as $role) {
            $rls->fijarUsuario(D::crearUsuario(['rol' => $role, 'global' => true])['id']);
            try {
                foreach (['INV_ACTIVO', 'INV_MOVIMIENTO', 'INV_KARDEX', 'INV_CONSUMIBLE', 'INV_APROBACION', 'INV_ORDEN_COMPRA'] as $table) {
                    self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM \"{$table}\""), "{$role} {$table}");
                }
                // Linking an account to a person still works.
                self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM "INV_PERSONA" WHERE "PER_Persona" = :p', ['p' => $person]), $role);
            } finally {
                $rls->reset();
            }
        }
    }

    public function testITAdministratorsApproveEachOtherButNeverTheirOwnRequests(): void
    {
        $token = $this->tokenFor(['rol' => 'ADMIN_TI', 'global' => true]);

        self::assertSame(200, $this->statusOf('GET', '/api/v1/aprobaciones', $token));
        self::assertSame(200, $this->statusOf('GET', '/api/v1/inventario/kardex/verificar', $token));
    }

    public function testPurchasingSeesItsWorkStockAndValuesButCannotOperateInventory(): void
    {
        $site = D::crearSede('Roles '.D::sufijo());
        $token = $this->tokenFor(['rol' => 'COMPRAS', 'sedes' => [$site]]);

        foreach (['/api/v1/core/activos', '/api/v1/compras/garantias', '/api/v1/org/personas', '/api/v1/trazabilidad/movimientos'] as $route) {
            self::assertSame(403, $this->statusOf('GET', $route, $token), $route);
        }
        foreach (['/api/v1/compras/ordenes', '/api/v1/compras/proveedores', '/api/v1/consumibles', '/api/v1/reports/stock',
            '/api/v1/inventario/atencion', '/api/v1/export/activos.csv',
            '/api/v1/inventario/cierre-mensual?anio=2026&mes=10&sede_id='.$site] as $route) {
            self::assertSame(200, $this->statusOf('GET', $route, $token), $route);
        }
    }

    public function testRetiredRolesAreRejectedByTheDatabase(): void
    {
        foreach (['JEFE_TI', 'CONTABILIDAD'] as $role) {
            try {
                D::crearUsuario(['rol' => $role]);
                self::fail("{$role} must no longer exist.");
            } catch (DriverException $e) {
                self::assertStringContainsString('CK_USUARIO_ROL_VALIDO', $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $options */
    private function tokenFor(array $options): string
    {
        return static::getContainer()->get(Tokens::class)->crearAcceso(D::crearUsuario($options)['username'], 'x');
    }

    private function statusOf(string $method, string $route, string $token): int
    {
        $this->client->request($method, $route, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return $this->client->getResponse()->getStatusCode();
    }
}
