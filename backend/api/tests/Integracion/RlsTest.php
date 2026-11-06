<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Db\ContextoRls;
use App\Tests\Soporte\DatosPrueba as D;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Segunda capa del alcance por sede: el VPD de Oracle filtra en la base según
 * el usuario fijado por App\Db\ContextoRls (políticas DBMS_RLS reales).
 */
final class RlsTest extends KernelTestCase
{
    private ContextoRls $rls;
    private Connection $conexion;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rls = static::getContainer()->get(ContextoRls::class);
        $this->conexion = static::getContainer()->get(Connection::class);
    }

    protected function tearDown(): void
    {
        $this->rls->reset();
        parent::tearDown();
    }

    public function testLaCapaVpdEstaInstaladaYActiva(): void
    {
        self::assertSame([true, 'ok'], $this->rls->verificar());
        self::assertTrue($this->rls->capaActiva());
    }

    public function testUnUsuarioPorSedeSoloVeLasPersonasDeSuSede(): void
    {
        $a = D::crearSede('Sede A '.D::sufijo());
        $b = D::crearSede('Sede B '.D::sufijo());
        $enA = D::crearPersona($a);
        $enB = D::crearPersona($b);
        $tecnico = D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$a]]);
        $global = D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true, 'sede_persona' => $a]);

        self::assertSame(2, $this->visibles($enA, $enB), 'Modo sistema: sin filtros.');

        $this->rls->fijarUsuario($tecnico['id']);
        self::assertSame($tecnico['id'], $this->conexion->fetchOne("SELECT SYS_CONTEXT('INVENTARIO_CTX', 'USUARIO') FROM DUAL"));
        self::assertSame(1, $this->visibles($enA, $enB));
        self::assertSame(0, $this->conexion->executeStatement(
            'UPDATE "INV_PERSONA" SET "PER_Primer_Apellido" = \'X\' WHERE "PER_Persona" = :p',
            ['p' => $enB],
        ), 'Una persona de otra sede no se puede modificar.');

        $this->rls->fijarUsuario($global['id']);
        self::assertSame(2, $this->visibles($enA, $enB));

        $this->rls->limpiar();
        self::assertNull($this->conexion->fetchOne("SELECT SYS_CONTEXT('INVENTARIO_CTX', 'USUARIO') FROM DUAL"));
        self::assertSame(2, $this->visibles($enA, $enB));
    }

    public function testElResetDeFinDePeticionLimpiaElContexto(): void
    {
        $u = D::crearUsuario(['sedes' => [D::crearSede('Sede '.D::sufijo())]]);
        $this->rls->fijarUsuario($u['id']);
        $this->rls->reset();

        self::assertNull($this->conexion->fetchOne("SELECT SYS_CONTEXT('INVENTARIO_CTX', 'USUARIO') FROM DUAL"));
    }

    private function visibles(string ...$personas): int
    {
        return (int) $this->conexion->fetchOne(
            'SELECT COUNT(*) FROM "INV_PERSONA" WHERE "PER_Persona" IN (:a, :b)',
            ['a' => $personas[0], 'b' => $personas[1]],
        );
    }
}
