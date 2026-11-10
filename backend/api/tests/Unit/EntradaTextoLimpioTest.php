<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Entrada;
use App\Http\ErrorValidacion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Textos con caracteres de control o UTF-8 inválido: 422 en cualquier campo y profundidad. */
final class EntradaTextoLimpioTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function sucios(): iterable
    {
        yield 'byte nulo' => ["a\u{0000}b"];
        yield 'escape' => ["a\u{001B}[31m"];
        yield 'borrar' => ["a\u{007F}"];
    }

    #[DataProvider('sucios')]
    public function testRechazaTextoSucioEnElCuerpoYLaConsulta(string $sucio): void
    {
        $cuerpo = (string) json_encode(['ok' => 'normal', 'lineas' => [['nota' => $sucio]]]);
        $this->assertRechazado(static fn () => Entrada::json(Request::create('/x', 'POST', content: $cuerpo)), ['body', 'lineas', 0, 'nota']);
        $this->assertRechazado(static fn () => Entrada::consulta(Request::create('/x?q='.rawurlencode($sucio))), ['query', 'q']);
    }

    public function testRechazaUtf8InvalidoEnLaConsulta(): void
    {
        $this->assertRechazado(static fn () => Entrada::consulta(Request::create('/x?q='.rawurlencode("a\xC3\x28"))), ['query', 'q']);
    }

    public function testAceptaTabuladorSaltosDeLineaYEmoji(): void
    {
        $e = Entrada::json(Request::create('/x', 'POST', content: (string) json_encode(['nota' => "línea 1\nlínea 2\r\n\tfin 😀"])));

        self::assertSame("línea 1\nlínea 2\r\n\tfin 😀", $e->texto('nota'));
    }

    /** @param list<string|int> $loc */
    private function assertRechazado(\Closure $leer, array $loc): void
    {
        try {
            $leer();
            self::fail('Se aceptó un texto con caracteres de control.');
        } catch (ErrorValidacion $e) {
            self::assertSame($loc, $e->errores[0]['loc']);
            self::assertSame('string_pattern_mismatch', $e->errores[0]['type']);
        }
    }
}
