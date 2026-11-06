<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Inventario\EstadosActivo;
use PHPUnit\Framework\TestCase;

final class EstadosActivoTest extends TestCase
{
    public function testElNombreAntiguoSeEntiendeComoEnBodega(): void
    {
        self::assertSame(EstadosActivo::BODEGA, EstadosActivo::canonico('Disponible'));
        self::assertSame(EstadosActivo::BODEGA, EstadosActivo::canonico('en bodega'));
    }

    public function testEnBodegaConservaLasOperacionesDeUnActivoLibre(): void
    {
        foreach ([EstadosActivo::ASIGNADO, EstadosActivo::INSTALADO, EstadosActivo::REPARACION, EstadosActivo::BAJA, EstadosActivo::TRANSITO] as $destino) {
            self::assertTrue(EstadosActivo::permitida(EstadosActivo::BODEGA, $destino), $destino);
        }
    }

    public function testTodoLoQueVuelveAlAlmacenVuelveAEnBodega(): void
    {
        foreach ([EstadosActivo::ASIGNADO, EstadosActivo::INSTALADO, EstadosActivo::REPARACION, EstadosActivo::TRANSITO, EstadosActivo::BAJA] as $origen) {
            self::assertTrue(EstadosActivo::permitida($origen, EstadosActivo::BODEGA), $origen);
        }
    }
}
