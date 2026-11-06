<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Inventario\Kardex;
use PHPUnit\Framework\TestCase;

final class KardexDocumentoTest extends TestCase
{
    public function testNoMuestraIdentificadoresInternos(): void
    {
        self::assertSame('', Kardex::documentoLegible('MOVIMIENTO', 'a77bbc47-1a3a-4f5e-9b1c-0123456789ab'));
        self::assertSame('TICKET', Kardex::documentoLegible('TICKET', 'a77bbc471a3a4f5e9b1c0123456789ab'));
    }

    public function testConservaLasReferenciasLegibles(): void
    {
        self::assertSame('TOMA_INVENTARIO 15', Kardex::documentoLegible('TOMA_INVENTARIO', '15'));
        self::assertSame('INGRESO ING-00001 · F1', Kardex::documentoLegible('INGRESO', 'ING-00001 · F1'));
        self::assertSame('', Kardex::documentoLegible(null, null));
    }
}
