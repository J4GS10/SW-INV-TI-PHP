<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Db\ErroresOracle;
use App\EventListener\ErroresListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ErroresOracleTest extends TestCase
{
    public function testLaConexionCortadaOLaBaseCaidaSonDeConexion(): void
    {
        foreach ([3113, 3114, 3135, 1033, 1034, 1089, 12514, 12541] as $codigo) {
            self::assertTrue(ErroresOracle::esDeConexion($codigo), "ORA-{$codigo}");
        }
    }

    public function testLosErroresDeDatosEIntegridadNoSonDeConexion(): void
    {
        foreach ([1, 1400, 2291, 12899, 28115, null] as $codigo) {
            self::assertFalse(ErroresOracle::esDeConexion($codigo), 'ORA-'.($codigo ?? 'nulo'));
        }
    }

    public function testUnTextoDeMasDe4000BytesEsErrorDeDatosY422(): void
    {
        foreach ([1460, 1489, 1704] as $codigo) {
            self::assertTrue(ErroresOracle::esDeDatos($codigo), "ORA-{$codigo}");
        }
        $delDriver = new class('ORA-01460: unimplemented or unreasonable conversion requested', null, 1460) extends AbstractException {
        };
        $evento = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/api/v1/login/access-token'),
            HttpKernelInterface::MAIN_REQUEST, new DriverException($delDriver, null));

        (new ErroresListener(new NullLogger(), new \App\Db\DiagnosticoBd($this->createStub(Connection::class))))($evento);

        $respuesta = $evento->getResponse();
        self::assertNotNull($respuesta);
        self::assertSame(422, $respuesta->getStatusCode());
        self::assertSame('{"detail":"INVALID_PARAMETER_VALUE"}', $respuesta->getContent());
    }

    public function testConLaConexionPerdidaSeDescartaYSeResponde503ConReintento(): void
    {
        $conexion = $this->createMock(Connection::class);
        $conexion->expects(self::once())->method('close');
        $delDriver = new class('ORA-03113: end-of-file on communication channel', null, 3113) extends AbstractException {
        };
        $evento = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/api/v1/core/activos'),
            HttpKernelInterface::MAIN_REQUEST, new DriverException($delDriver, null));

        (new ErroresListener(new NullLogger(), new \App\Db\DiagnosticoBd($conexion)))($evento);

        $respuesta = $evento->getResponse();
        self::assertNotNull($respuesta);
        self::assertSame(503, $respuesta->getStatusCode());
        self::assertSame('5', $respuesta->headers->get('Retry-After'));
        self::assertSame('{"detail":"DATABASE_UNAVAILABLE"}', $respuesta->getContent());
    }
}
