<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\Ajustes;
use App\Db\SesionOracleMiddleware;
use App\Dominio\Ident;
use App\Dominio\Usuario;
use App\Http\Limitador;
use App\Repositorio\SesionRepositorio;
use App\Seguridad\BloqueoCuenta;
use PHPUnit\Framework\TestCase;

/** Reglas puras (sin base de datos), con los mismos valores esperados que el backend anterior. */
final class ReglasTest extends TestCase
{
    public function testBloqueoProgresivoDuplicaHastaElTope(): void
    {
        $b = new BloqueoCuenta($this->ajustes());
        self::assertSame([15, 30, 60, 120, 240, 480, 960, 1440, 1440], array_map($b->duracionMinutos(...), range(1, 9)));
    }

    public function testElQuintoFalloBloqueaYLaEscaladaCaducaTras24Horas(): void
    {
        $b = new BloqueoCuenta($this->ajustes());
        $u = $this->usuario();
        $t0 = new \DateTimeImmutable('2026-10-27 10:00:00', new \DateTimeZone('UTC'));

        foreach (range(1, 4) as $_) {
            self::assertFalse($b->registrarFallo($u, $t0));
        }
        self::assertTrue($b->registrarFallo($u, $t0));
        self::assertSame(1, $u->bloqueosConsecutivos);
        self::assertEquals($t0->modify('+15 minutes'), $u->bloqueadoHasta);
        self::assertTrue($b->estaBloqueada($u, $t0->modify('+14 minutes')));

        // Vencido: un solo fallo no vuelve a bloquear, pero el siguiente bloqueo dura el doble.
        $t1 = $t0->modify('+16 minutes');
        self::assertFalse($b->registrarFallo($u, $t1));
        self::assertSame(1, $u->intentosFallidos);
        foreach (range(1, 3) as $_) {
            $b->registrarFallo($u, $t1);
        }
        self::assertTrue($b->registrarFallo($u, $t1));
        self::assertSame(2, $u->bloqueosConsecutivos);
        self::assertEquals($t1->modify('+30 minutes'), $u->bloqueadoHasta);

        // 24 h después del último bloqueo la escalada vuelve a empezar.
        $t2 = $t1->modify('+25 hours');
        foreach (range(1, 5) as $_) {
            $b->registrarFallo($u, $t2);
        }
        self::assertSame(1, $u->bloqueosConsecutivos);
        self::assertEquals($t2->modify('+15 minutes'), $u->bloqueadoHasta);
    }

    public function testInterpretaLimitesConLaSintaxisDelBackendActual(): void
    {
        self::assertSame(['limit' => 5, 'interval' => '1 minute'], Limitador::interpretar('5/minute'));
        self::assertSame(['limit' => 100, 'interval' => '1 minute'], Limitador::interpretar('100/minute'));
        self::assertSame(['limit' => 30, 'interval' => '15 minute'], Limitador::interpretar('30 per 15 minutes'));
        $this->expectException(\InvalidArgumentException::class);
        Limitador::interpretar('mucho');
    }

    public function testIdentificadoresHexYTexto(): void
    {
        $hex = Ident::nuevo();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hex);
        self::assertSame($hex, Ident::aHex(Ident::aTexto($hex)));
        self::assertSame('0f0e0d0c-0b0a-4908-8706-050403020100', Ident::aTexto('0F0E0D0C0B0A49088706050403020100'));
        self::assertFalse(Ident::esValido("x' OR 1=1 --"));
    }

    public function testEtiquetaDeDispositivo(): void
    {
        $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
        $edge = $chrome.' Edg/130.0';
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1';
        self::assertSame('Chrome · Windows', SesionRepositorio::etiquetaDispositivo($chrome));
        self::assertSame('Edge · Windows', SesionRepositorio::etiquetaDispositivo($edge));
        self::assertSame('Safari · iOS', SesionRepositorio::etiquetaDispositivo($iphone));
        self::assertSame('Desconocido · Otro', SesionRepositorio::etiquetaDispositivo(null));
    }

    public function testConfiguracionDeSesionOracleYEsquema(): void
    {
        $a = $this->ajustes(['ORACLE_USER' => 'inventario_app', 'ORACLE_SCHEMA_OWNER' => 'inventario']);
        self::assertSame('INVENTARIO', $a->esquema());
        self::assertSame('INVENTARIO_APP', $a->usuarioConexion());
        $sentencias = SesionOracleMiddleware::sentencias($a->esquema(), $a->usuarioConexion());
        self::assertContains("ALTER SESSION SET TIME_ZONE = 'UTC'", $sentencias);
        self::assertContains('ALTER SESSION SET CURRENT_SCHEMA = "INVENTARIO"', $sentencias);
        self::assertNotContains(
            'ALTER SESSION SET CURRENT_SCHEMA = "INVENTARIO"',
            SesionOracleMiddleware::sentencias('INVENTARIO', 'INVENTARIO'),
        );
        $this->expectException(\InvalidArgumentException::class);
        SesionOracleMiddleware::sentencias('X"; DROP', 'APP');
    }

    public function testOrigenesCorsComoJsonOListaSeparadaPorComas(): void
    {
        self::assertSame(['https://a.com', 'https://b.com'], Ajustes::listaOrigenes('["https://a.com/", "https://b.com"]'));
        self::assertSame(['https://a.com', 'https://b.com'], Ajustes::listaOrigenes('https://a.com, https://b.com/'));
        self::assertSame([], Ajustes::listaOrigenes('[]'));
    }

    public function testRechazaUnaClaveSecretaCorta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ajustes::desdeEntorno(['SECRET_KEY' => 'corta']);
    }

    /** @param array<string, string> $extra */
    private function ajustes(array $extra = []): Ajustes
    {
        return Ajustes::desdeEntorno(['SECRET_KEY' => str_repeat('k', 40), ...$extra]);
    }

    private function usuario(): Usuario
    {
        return new Usuario(Ident::nuevo(), 'ana', null, 'TECNICO', true, false, Ident::nuevo(), false, false, null,
            0, null, 0, null, null);
    }
}
