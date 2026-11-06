<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\Ajustes;
use App\Db\ContextoRls;
use App\Http\ErrorApi;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Fase 7 (hallazgo F-01): con DB_RLS_ENABLED la segunda capa (VPD) no puede apagarse en silencio.
 * Antes, si la verificación fallaba una vez (p. ej. Oracle reiniciándose al arrancar un worker), el
 * proceso guardaba «no disponible» para siempre y atendía TODAS las peticiones autenticadas en modo
 * sistema (sin filtro de sede en la base).
 */
final class ContextoRlsTest extends TestCase
{
    private function ajustes(bool $rls = true): Ajustes
    {
        return Ajustes::desdeEntorno(['SECRET_KEY' => str_repeat('k', 40), 'DB_RLS_ENABLED' => $rls ? 'true' : 'false']);
    }

    /**
     * Conexión cuya verificación falla las primeras $fallos veces y luego encuentra todo instalado.
     *
     * @param-out int $fijados veces que se fijó el contexto
     */
    private function conexion(int $fallos, ?int &$fijados = null): Connection
    {
        $fijados = 0;
        $c = $this->createStub(Connection::class);
        $intentos = 0;
        $c->method('fetchOne')->willReturnCallback(static function () use (&$intentos, $fallos): string {
            if (++$intentos <= $fallos) {
                throw new \RuntimeException('ORA-12514: la base aún no acepta conexiones');
            }

            return 'VALID';
        });
        $c->method('fetchFirstColumn')->willReturn([]);
        $c->method('executeStatement')->willReturnCallback(static function () use (&$fijados): int {
            ++$fijados;

            return 0;
        });

        return $c;
    }

    public function testUnaFallaPasajeraNoApagaLaCapaParaSiempre(): void
    {
        $rls = new ContextoRls($this->conexion(1, $fijados), $this->ajustes(), new NullLogger());
        try {
            $rls->fijarUsuario(str_repeat('a', 32));
            self::fail('Con la capa no verificable, fijar el usuario debe fallar cerrado.');
        } catch (ErrorApi $e) {
            self::assertSame(503, $e->estado);
        }
        // La base ya responde: la siguiente petición verifica de nuevo y fija el contexto.
        $rls->fijarUsuario(str_repeat('a', 32));
        self::assertSame(1, $fijados);
        self::assertTrue($rls->capaActiva());
    }

    public function testSinCapaVpdUnaPeticionAutenticadaNoSigueEnModoSistema(): void
    {
        $rls = new ContextoRls($this->conexion(PHP_INT_MAX, $fijados), $this->ajustes(), new NullLogger());
        $this->expectException(ErrorApi::class);
        $rls->fijarUsuario(str_repeat('b', 32));
    }

    public function testConLaCapaDesactivadaPorConfiguracionNoSeExige(): void
    {
        $rls = new ContextoRls($this->conexion(PHP_INT_MAX, $fijados), $this->ajustes(false), new NullLogger());
        $rls->fijarUsuario(str_repeat('c', 32));
        self::assertSame(0, $fijados);
        self::assertFalse($rls->capaActiva());
    }
}
