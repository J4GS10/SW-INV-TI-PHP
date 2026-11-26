<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dominio\CodigoOperador;
use App\Dominio\Roles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodigoOperadorTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function codigos(): iterable
    {
        yield 'válido' => ['482916', null];
        yield 'otro válido' => ['730415', null];
        yield 'corto' => ['12345', 'OPERATOR_CODE_FORMAT'];
        yield 'largo' => ['1234567', 'OPERATOR_CODE_FORMAT'];
        yield 'con letras' => ['12a456', 'OPERATOR_CODE_FORMAT'];
        yield 'con espacio' => ['482 916', 'OPERATOR_CODE_FORMAT'];
        yield 'repetido' => ['777777', 'OPERATOR_CODE_TOO_WEAK'];
        yield 'ascendente' => ['345678', 'OPERATOR_CODE_TOO_WEAK'];
        yield 'descendente' => ['987654', 'OPERATOR_CODE_TOO_WEAK'];
        yield 'desde cero' => ['012345', 'OPERATOR_CODE_TOO_WEAK'];
        yield 'obvio' => ['123123', 'OPERATOR_CODE_TOO_WEAK'];
    }

    #[DataProvider('codigos')]
    public function testSoloSeisDigitosNoObvios(string $codigo, ?string $esperado): void
    {
        self::assertSame($esperado, CodigoOperador::problema($codigo));
    }

    public function testSoloTiYTecnicosTienenCodigo(): void
    {
        self::assertTrue(CodigoOperador::rolPuedeTener(Roles::ADMIN_TI));
        self::assertTrue(CodigoOperador::rolPuedeTener(Roles::TECNICO));
        foreach ([Roles::ESCANER, Roles::CONSULTA, Roles::AUDITOR, Roles::COMPRAS, Roles::SUPER_ADMIN] as $rol) {
            self::assertFalse(CodigoOperador::rolPuedeTener($rol), $rol);
        }
    }
}
