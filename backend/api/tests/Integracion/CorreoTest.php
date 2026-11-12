<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Correo\ConfigCorreo;
use App\Correo\Cortacircuitos;
use App\Correo\EntregaSmtpGraph;
use App\Correo\ErrorEnvioCorreo;
use App\Correo\MetricasCorreo;
use App\Correo\Notificador;
use App\Correo\Plantillas;
use App\Http\TareasPosteriores;
use App\Seguridad\CacheAuth;
use App\Seguridad\CifradoCampos;
use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Mailpit;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Correo: plantillas, cola persistente (SYS_EMAIL_OUTBOX), entrega SMTP real
 * contra Mailpit, Microsoft Graph simulado y cortacircuitos.
 */
final class CorreoTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        Mailpit::vaciar();
        D::conexion()->executeStatement('DELETE FROM "SYS_INTEGRACION_CORREO"');
    }

    public function testPlantillasEscapanDatosYMuestranFechasEnLaZonaDeLaOrganizacion(): void
    {
        [$asunto, $html] = $this->servicio(Plantillas::class)->renderizar('nuevo_dispositivo', [
            'persona_nombre' => '<script>alert(1)</script>Ana',
            'username' => 'ana',
            'dispositivo' => 'Chrome · Windows',
            'fecha' => '2026-10-28T02:30:00',
            'ip' => '10.0.0.1',
        ]);

        self::assertSame('[Inventario] Nuevo inicio de sesión en su cuenta', $asunto);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Ana', $html);
        self::assertStringContainsString('27/10/2026 20:30', $html, 'UTC → America/Guatemala (UTC-6).');
        self::assertStringContainsString('<style>', $html);
        $texto = Plantillas::textoPlano($html);
        self::assertStringContainsString('Dispositivo: Chrome · Windows', $texto);
        self::assertStringNotContainsString('font-family', $texto);

        [$asunto] = $this->servicio(Plantillas::class)->renderizar('offboarding', ['persona_nombre' => "Ana\r\nBcc: x@y.z"]);
        self::assertSame('[Inventario] Salida de empleado: Ana Bcc: x@y.z', $asunto, 'Sin saltos de línea en la cabecera.');
    }

    public function testUnCorreoEfimeroSeEntregaPorSmtpTrasLaRespuestaYNoSeEncola(): void
    {
        $antes = $this->filasCola();
        $this->servicio(Notificador::class)->enviar('password_reset', [
            'persona_nombre' => 'Ana Prueba', 'username' => 'ana', 'token' => 'TOKEN-SECRETO', 'reset_url' => 'https://x/reset?token=TOKEN-SECRETO', 'minutos' => 30,
        ], ['ana@pruebas.local'], false);

        $tareas = $this->servicio(TareasPosteriores::class);
        self::assertSame(['correo:password_reset'], $tareas->pendientes());
        self::assertSame([], Mailpit::mensajes(), 'Nada se envía antes de terminar la petición.');
        $tareas->ejecutar();

        $mensajes = Mailpit::mensajes();
        self::assertCount(1, $mensajes);
        self::assertSame('[Inventario] Restablecimiento de contraseña', $mensajes[0]['Subject']);
        self::assertSame(['ana@pruebas.local'], Mailpit::destinatarios($mensajes[0]), 'Nunca en copia a administradores.');
        $completo = Mailpit::mensaje($mensajes[0]['ID']);
        self::assertStringContainsString('TOKEN-SECRETO', (string) $completo['HTML']);
        self::assertStringContainsString('Restablecer contraseña', (string) $completo['Text']);
        self::assertSame($antes, $this->filasCola(), 'Un secreto de un solo uso nunca se guarda en la cola.');
    }

    public function testUnAvisoDeSeguridadSeEncolaConElFormatoDelWorker(): void
    {
        $marca = 'cola_'.D::sufijo().'@pruebas.local';
        $this->servicio(Notificador::class)->contrasenaCambiada('Ana', 'ana', $marca, 'Autoservicio', '10.1.1.1');

        $fila = D::conexion()->fetchAssociative(
            'SELECT "EOB_Plantilla", "EOB_Asunto", "EOB_Para", "EOB_Afectados", "EOB_Cc_Admins", "EOB_Resolver", '
            .'"EOB_Estado", "EOB_Intentos" FROM "SYS_EMAIL_OUTBOX" WHERE DBMS_LOB.INSTR("EOB_Para", :m) > 0',
            ['m' => $marca],
        );
        self::assertSame([
            'EOB_Plantilla' => 'password_changed',
            'EOB_Asunto' => '[Inventario] Su contraseña fue cambiada',
            'EOB_Para' => json_encode([$marca]),
            'EOB_Afectados' => null,
            'EOB_Cc_Admins' => '0',
            'EOB_Resolver' => '0',
            'EOB_Estado' => 'PENDIENTE',
            'EOB_Intentos' => '0',
        ], $fila);
        self::assertSame([], $this->servicio(TareasPosteriores::class)->pendientes());
    }

    public function testUnEventoNoConfigurableConCopiaIncluyeAdministradores(): void
    {
        $marca = 'cc_'.D::sufijo().'@pruebas.local';
        $this->servicio(Notificador::class)->enviar('cuenta_desactivada_inactividad',
            ['persona_nombre' => 'Ana', 'username' => 'ana', 'dias' => 90], [$marca, $marca, ' ']);

        $para = D::conexion()->fetchOne(
            'SELECT "EOB_Para" FROM "SYS_EMAIL_OUTBOX" WHERE DBMS_LOB.INSTR("EOB_Para", :m) > 0', ['m' => $marca],
        );
        self::assertSame([$marca, 'admin@pruebas.local'], json_decode((string) $para, true));
    }

    public function testConElCorreoDesactivadoEnLaInterfazNoSeEnviaNada(): void
    {
        D::conexion()->executeStatement(
            'INSERT INTO "SYS_INTEGRACION_CORREO" ("INT_Id", "INT_Modo", "INT_Proveedor", "INT_SMTP_Puerto", "INT_SMTP_Seguridad", '
            .'"INT_AD_Start_TLS", "INT_AD_Verificar_Cert", "INT_AD_Intervalo_Min", "INT_AD_Desactivar", "INT_AD_Usar_Cuenta_Servicio") '
            ."VALUES (1, 'DESACTIVADO', 'SMTP', 587, 'STARTTLS', 0, 1, 0, 1, 1)",
        );
        $antes = $this->filasCola();
        $notificador = $this->servicio(Notificador::class);
        $notificador->contrasenaCambiada('Ana', 'ana', 'ana@pruebas.local', 'x', null);
        $notificador->enviar('2fa_code', ['code' => '123456'], ['ana@pruebas.local'], false);

        self::assertSame($antes, $this->filasCola());
        self::assertSame([], $this->servicio(TareasPosteriores::class)->pendientes());
    }

    public function testLaConfiguracionDeLaInterfazDescifraElSecretoYTienePrioridad(): void
    {
        $cifrado = $this->servicio(CifradoCampos::class)->cifrar('clave-de-la-cuenta');
        D::conexion()->executeStatement(
            'INSERT INTO "SYS_INTEGRACION_CORREO" ("INT_Id", "INT_Modo", "INT_Proveedor", "INT_Cuenta_Email", "INT_Cuenta_Password_Enc", '
            .'"INT_Nombre_Remitente", "INT_SMTP_Host", "INT_SMTP_Puerto", "INT_SMTP_Seguridad", "INT_AD_Start_TLS", "INT_AD_Verificar_Cert", '
            .'"INT_AD_Intervalo_Min", "INT_AD_Desactivar", "INT_AD_Usar_Cuenta_Servicio") '
            ."VALUES (1, 'CORREO', 'SMTP', 'servicio@pruebas.local', :p, 'Inventario', 'mailpit', 1025, 'NINGUNA', 0, 1, 0, 1, 1)",
            ['p' => $cifrado],
        );
        $config = $this->servicio(\App\Integracion\FuenteIntegracion::class)->obtener()->correo;

        self::assertSame('interfaz', $config->fuente);
        self::assertSame('clave-de-la-cuenta', $config->password);
        self::assertSame('servicio@pruebas.local', $config->usuario);
        self::assertTrue($config->lista());
    }

    public function testErroresDeEntregaYCortacircuitos(): void
    {
        $cortacircuitos = new Cortacircuitos(new CacheAuth(\App\Config\Ajustes::desdeEntorno(['SECRET_KEY' => str_repeat('k', 40)]), new NullLogger()), new NullLogger());
        $graph = new MockHttpClient([
            new MockResponse('{"access_token":"tok","expires_in":3600}', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 202]),
            new MockResponse('{"error":{"code":"ErrorAccessDenied"}}', ['http_code' => 403]),
        ]);
        $metricas = new MetricasCorreo();
        $entrega = new EntregaSmtpGraph($graph, $cortacircuitos, $metricas, new NullLogger());
        $config = new ConfigCorreo(true, 'GRAPH', remitente: 'buzon@pruebas.local', graphTenant: 't', graphCliente: 'c', graphSecreto: 's');

        $entrega->entregar($config, ['a@pruebas.local'], 'Asunto', '<p>x</p>');
        self::assertSame(2, $graph->getRequestsCount(), 'Token + envío.');
        $this->esperarError('AUTH_FAILED', fn () => $entrega->entregar($config, ['a@pruebas.local'], 'Asunto', '<p>x</p>'));
        self::assertNotNull($cortacircuitos->pausadoHasta('smtp'), 'Credenciales rechazadas: se pausan los intentos.');
        $this->esperarError('AUTH_PAUSED', fn () => $entrega->entregar($config, ['a@pruebas.local'], 'Asunto', '<p>x</p>'));
        self::assertSame(['configured' => true, 'status' => 'down', 'sent_total' => 1, 'failed_total' => 1,
            'last_delivery_ok' => false, 'last_error_type' => 'AUTH_FAILED'], $metricas->resumen(true));

        $cortacircuitos->reiniciar();
        $inalcanzable = new ConfigCorreo(true, host: 'no-existe.invalid', puerto: 2525, seguridad: 'NINGUNA', remitente: 'x@pruebas.local', timeout: 3);
        $this->esperarError('CONNECTION_FAILED', fn () => $entrega->entregar($inalcanzable, ['a@pruebas.local'], 'A', '<p>x</p>'));
        $this->esperarError('NOT_CONFIGURED', fn () => $entrega->entregar(new ConfigCorreo(false), ['a@pruebas.local'], 'A', 'x'));
        // STARTTLS obligatorio: un servidor sin TLS no recibe el correo en claro.
        $sinTls = new ConfigCorreo(true, host: 'mailpit', puerto: 1025, seguridad: 'STARTTLS', remitente: 'x@pruebas.local', timeout: 3);
        $this->esperarError('TLS_FAILED', fn () => $entrega->entregar($sinTls, ['a@pruebas.local'], 'A', '<p>x</p>'));
        self::assertSame([], Mailpit::mensajes());
    }

    /**
     * Fase 8: un correo que mata al proceso al entregarse (p. ej. un HTML enorme) vuelve a PENDIENTE al
     * vencer el arriendo. Agotados los intentos no se reclama otra vez: queda FALLIDO (reintentable desde la API).
     */
    public function testUnCorreoQueAgotoLosIntentosNoSeReclamaSinFin(): void
    {
        $id = \App\Dominio\Ident::nuevo();
        $antes = \App\Dominio\Reloj::aBd(\App\Dominio\Reloj::ahora()->modify('-1 minute'));
        D::conexion()->executeStatement('INSERT INTO "SYS_EMAIL_OUTBOX" ("EOB_Id", "EOB_Plantilla", "EOB_Asunto", "EOB_Html", "EOB_Para", "EOB_Cc_Admins", '
            .'"EOB_Resolver", "EOB_Estado", "EOB_Intentos", "EOB_Proximo_Intento", "EOB_Creado_En") '
            ."VALUES (:id, 'prueba', 'x', 'x', '[]', 0, 0, 'PENDIENTE', 3, :antes, :antes)", ['id' => $id, 'antes' => $antes]);
        $reclamados = $this->servicio(\App\Correo\Infrastructure\ColaCorreos::class)->reclamar(20, 300, 3);
        self::assertNotContains($id, $reclamados);
        self::assertSame('FALLIDO', D::conexion()->fetchOne('SELECT "EOB_Estado" FROM "SYS_EMAIL_OUTBOX" WHERE "EOB_Id" = :id', ['id' => $id]));
        D::conexion()->executeStatement('DELETE FROM "SYS_EMAIL_OUTBOX" WHERE "EOB_Id" = :id', ['id' => $id]);
    }

    private function esperarError(string $codigo, \Closure $accion): void
    {
        try {
            $accion();
            self::fail("Se esperaba {$codigo}");
        } catch (ErrorEnvioCorreo $e) {
            self::assertSame($codigo, $e->codigo, $e->getMessage());
        }
    }

    private function filasCola(): int
    {
        return D::contar('SELECT COUNT(*) FROM "SYS_EMAIL_OUTBOX"');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $clase
     *
     * @return T
     */
    private function servicio(string $clase): object
    {
        return static::getContainer()->get($clase);
    }
}
