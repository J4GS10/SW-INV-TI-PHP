<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Crud\Esquema;
use App\Db\ContextoRls;
use App\EventListener\OcultarCostosListener;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\Alcance;
use App\Seguridad\UsuarioActual;
use App\Tests\Soporte\DatosPrueba as D;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Piezas comunes a todos los módulos. */
final class TransversalesTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(ContextoRls::class)->reset();
        static::getContainer()->get(UsuarioActual::class)->reset();
        parent::tearDown();
    }

    public function testLaCapaUnoFiltraIgualQueElVpd(): void
    {
        $a = D::crearSede('A '.D::sufijo());
        $b = D::crearSede('B '.D::sufijo());
        $personas = [D::crearPersona($a), D::crearPersona($b), D::crearPersona(null)];
        $u = D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$a]]);
        $usuario = static::getContainer()->get(UsuarioRepositorio::class)->porId($u['id']);
        static::getContainer()->get(UsuarioActual::class)->establecer($usuario, []);
        $conexion = static::getContainer()->get(Connection::class);
        $marcas = ['p0' => $personas[0], 'p1' => $personas[1], 'p2' => $personas[2]];

        // Capa 1 sola (sin contexto VPD en la sesión).
        [$filtro, $parametros] = static::getContainer()->get(Alcance::class)->lectura('INV_PERSONA', 'p');
        $capaUno = $conexion->fetchFirstColumn(
            'SELECT p."PER_Persona" FROM "INV_PERSONA" p WHERE p."PER_Persona" IN (:p0, :p1, :p2) AND '.$filtro,
            [...$marcas, ...$parametros],
        );
        // Capa 2 sola (VPD con el usuario fijado).
        static::getContainer()->get(ContextoRls::class)->fijarUsuario($u['id']);
        $vpd = $conexion->fetchFirstColumn('SELECT "PER_Persona" FROM "INV_PERSONA" WHERE "PER_Persona" IN (:p0, :p1, :p2)', $marcas);

        self::assertSame([$personas[0]], $capaUno);
        self::assertSame($vpd, $capaUno);
    }

    public function testOcultarCostosRespetaLaEstructura(): void
    {
        $datos = json_decode('{"items":[{"ACT_Costo":"10.00","ACT_Codigo":"A1","extra":{}}],"saldo_valor":5,"meta":[]}');
        self::assertSame('{"items":[{"ACT_Codigo":"A1","extra":{}}],"meta":[]}', json_encode(OcultarCostosListener::quitarDinero($datos)));
        self::assertSame(['a' => 1], OcultarCostosListener::descartarCostos(['a' => 1, 'ACT_Costo' => '5'], 'TECNICO', 'ACT_Costo'));
        // Mantenimientos y órdenes también llevan dinero (hallazgo de la revisión de seguridad de la fase 8).
        $mantenimiento = json_decode('{"MAN_Costo_Total":"10.00","detalles":[{"DMA_Costo_Item":"10.00","DMA_Accion_Realizada":"x"}],'
            .'"OCO_Total":"5.00","lineas":[{"OCL_Subtotal":"5.00"}],"costo":"1","costo_total":"1","costo_por_moneda":{}}', false);
        self::assertSame('{"detalles":[{"DMA_Accion_Realizada":"x"}],"lineas":[{}]}', json_encode(OcultarCostosListener::quitarDinero($mantenimiento)));
        self::assertSame(['a' => 1, 'ACT_Costo' => '5'], OcultarCostosListener::descartarCostos(['a' => 1, 'ACT_Costo' => '5'], 'ADMIN_TI', 'ACT_Costo'));
    }

    public function testDecimalesConEscalaSinComaFlotante(): void
    {
        self::assertSame(['1500.00', '0.10', '-3.50', '0.00', '12345678901234.99', '7'],
            [Esquema::decimal('1500', 2), Esquema::decimal('.1', 2), Esquema::decimal('-3.5', 2), Esquema::decimal('-0', 2),
                Esquema::decimal('12345678901234.99', 2), Esquema::decimal('7', 0)]);
    }
}
