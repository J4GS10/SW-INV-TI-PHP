<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Seguridad\Tokens;
use App\Tests\Soporte\DatosPrueba as D;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** /gov contra Oracle real: configuración, purga, cola de correos y bitácora con alcance. */
final class GobiernoTest extends WebTestCase
{
    private KernelBrowser $cliente;
    private string $super;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->super = $this->token(D::crearUsuario(['rol' => 'SUPER_ADMIN', 'global' => true]));
    }

    public function testConfigPublicaYCambioParcialAuditado(): void
    {
        [$e, $config] = $this->pedir('GET', '/api/v1/gov/config', token: '');
        self::assertSame(200, $e);
        self::assertSame(1, $config['SYS_Configuracion']);

        $nombre = 'Empresa '.D::sufijo();
        [$e, $nueva] = $this->pedir('PUT', '/api/v1/gov/config', ['SYS_Nombre_Empresa' => $nombre, 'SYS_Color_Primario' => '#1a2B3c', 'SYS_Ciudad' => null]);
        self::assertSame(200, $e);
        self::assertSame([$nombre, '#1a2B3c', null], [$nueva['SYS_Nombre_Empresa'], $nueva['SYS_Color_Primario'], $nueva['SYS_Ciudad']]);
        self::assertSame($config['SYS_Logo_URL'], $nueva['SYS_Logo_URL'], 'Lo que no viene no cambia.');
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" WHERE "AUD_Entidad_Afectada" = \'SYS_CONFIGURACION\' '
            .'AND DBMS_LOB.INSTR("AUD_Snapshot_JSON", :n) > 0', ['n' => $nombre]));

        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('PUT', '/api/v1/gov/config', ['SYS_Color_Fondo' => 'rojo']));
        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('PUT', '/api/v1/gov/config', ['SYS_Nombre_Empresa' => 'X']));
        $admin = $this->token(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));
        self::assertSame([403, 'NOT_ENOUGH_PERMISSIONS'], $this->detalle('PUT', '/api/v1/gov/config', ['SYS_Ciudad' => 'Otra'], $admin));
        self::assertSame(401, $this->pedir('PUT', '/api/v1/gov/config', ['SYS_Ciudad' => 'Otra'], '')[0]);
    }

    public function testElLogoSeSubeComoImagenYSeSirveSinSesion(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $ruta = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($ruta, $png);
        $this->cliente->request('POST', '/api/v1/gov/config/logo', files: ['file' => new UploadedFile($ruta, 'logo.png', 'image/png', null, true)], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->super]);
        self::assertSame(200, $this->cliente->getResponse()->getStatusCode());
        $config = json_decode((string) $this->cliente->getResponse()->getContent(), true);
        self::assertStringStartsWith('/api/v1/gov/config/logo', $config['SYS_Logo_URL']);

        $this->cliente->request('GET', '/api/v1/gov/config/logo');
        self::assertSame(200, $this->cliente->getResponse()->getStatusCode());
        self::assertSame('image/png', $this->cliente->getResponse()->headers->get('Content-Type'));

        // Lo que no es una imagen se rechaza con un motivo claro.
        file_put_contents($ruta, '<html>no soy una imagen</html>');
        $this->cliente->request('POST', '/api/v1/gov/config/logo', files: ['file' => new UploadedFile($ruta, 'logo.png', 'image/png', null, true)], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->super]);
        self::assertSame(400, $this->cliente->getResponse()->getStatusCode());
        self::assertSame('LOGO_MUST_BE_AN_IMAGE', json_decode((string) $this->cliente->getResponse()->getContent(), true)['detail']);
        D::conexion()->executeStatement('UPDATE "SYS_CONFIGURACION" SET "SYS_Logo_URL" = NULL, "SYS_Logo_Image" = NULL');
    }

    public function testPurgaEliminaSoloLoCaducado(): void
    {
        $c = D::conexion();
        $u = D::crearUsuario();
        $vencido = 'qa-vencido-'.D::sufijo();
        $vigente = 'qa-vigente-'.D::sufijo();
        foreach ([$vencido => '-1 hour', $vigente => '+1 hour'] as $jti => $desfase) {
            $c->executeStatement('INSERT INTO "SYS_TOKEN_REVOCADO" ("TRV_Jti", "TRV_Tipo", "TRV_Fecha_Revocacion", "TRV_Expira", "USU_Usuario") '
                ."VALUES (:j, 'access', :a, :e, :u)", ['j' => $jti, 'a' => Reloj::aBd(Reloj::ahora()), 'e' => Reloj::aBd(Reloj::ahora()->modify($desfase)), 'u' => $u['id']]);
        }

        [$e, $r] = $this->pedir('POST', '/api/v1/gov/security/purge');
        self::assertSame(200, $e);
        self::assertSame(['tokens_revocados_eliminados', 'idempotency_keys_eliminadas', 'reset_tokens_eliminados', 'otp_2fa_eliminados',
            'correos_enviados_eliminados'], array_keys($r));
        self::assertGreaterThanOrEqual(1, $r['tokens_revocados_eliminados']);
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "SYS_TOKEN_REVOCADO" WHERE "TRV_Jti" = :j', ['j' => $vencido]));
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "SYS_TOKEN_REVOCADO" WHERE "TRV_Jti" = :j', ['j' => $vigente]));
    }

    public function testColaDeCorreosResumenYReintento(): void
    {
        $id = Ident::nuevo();
        D::conexion()->executeStatement(
            'INSERT INTO "SYS_EMAIL_OUTBOX" ("EOB_Id", "EOB_Plantilla", "EOB_Asunto", "EOB_Html", "EOB_Para", "EOB_Cc_Admins", "EOB_Resolver", '
            ."\"EOB_Estado\", \"EOB_Intentos\", \"EOB_Proximo_Intento\", \"EOB_Ultimo_Error\", \"EOB_Creado_En\") VALUES (:id, 'baja', 'Asunto QA', '<p>x</p>', "
            ."'[\"qa@pruebas.local\"]', 0, 0, 'FALLIDO', 7, :a, 'SMTP_TIMEOUT', :a)",
            ['id' => $id, 'a' => Reloj::aBd(Reloj::ahora())]);

        [$e, $cola] = $this->pedir('GET', '/api/v1/gov/email-outbox');
        self::assertSame(200, $e);
        self::assertGreaterThanOrEqual(1, $cola['fallidos']);
        $fila = array_values(array_filter($cola['ultimos_fallidos'], static fn (array $f): bool => $f['id'] === Ident::aTexto($id)))[0];
        self::assertSame(['baja', ['qa@pruebas.local'], 7, 'SMTP_TIMEOUT'], [$fila['plantilla'], $fila['destinatarios'], $fila['intentos'], $fila['error']]);

        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('POST', '/api/v1/gov/email-outbox/reintentar?outbox_id=nada'));
        self::assertSame([200, ['reencolados' => 1]], $this->pedir('POST', '/api/v1/gov/email-outbox/reintentar?outbox_id='.Ident::aTexto($id)));
        self::assertSame([200, ['reencolados' => 0]], $this->pedir('POST', '/api/v1/gov/email-outbox/reintentar?outbox_id='.Ident::aTexto($id)));
        self::assertSame(1, D::contar("SELECT COUNT(*) FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Id\" = :id AND \"EOB_Estado\" = 'PENDIENTE' AND \"EOB_Intentos\" = 0", ['id' => $id]));
        D::conexion()->executeStatement('DELETE FROM "SYS_EMAIL_OUTBOX" WHERE "EOB_Id" = :id', ['id' => $id]);
    }

    public function testBitacoraFiltradaPorElAlcanceDelAuditor(): void
    {
        $propia = D::crearSede('Sede A '.D::sufijo());
        $ajena = D::crearSede('Sede B '.D::sufijo());
        $auditor = D::crearUsuario(['rol' => 'AUDITOR', 'sedes' => [$propia]]);
        $entidad = 'QA_'.strtoupper(substr(D::sufijo(), 0, 10));
        $gobierno = static::getContainer()->get(\App\Repositorio\GobiernoRepositorio::class);
        $gobierno->registrarAuditoria('CREATE', $entidad, ['n' => 1], sedeId: $propia);
        $gobierno->registrarAuditoria('UPDATE', $entidad, ['n' => 2], sedeId: $ajena);
        $gobierno->registrarAuditoria('DELETE', $entidad, null, usuarioId: $auditor['id'], sedeId: $ajena);

        [$e, $todo] = $this->pedir('GET', "/api/v1/gov/auditoria?entidad={$entidad}");
        self::assertSame([200, 3], [$e, $todo['total']]);
        self::assertSame(['DELETE', 'UPDATE', 'CREATE'], array_column($todo['items'], 'AUD_Accion'));
        self::assertSame($auditor['username'], $todo['items'][0]['usuario_nombre']);
        self::assertSame(['n' => 1], $todo['items'][2]['AUD_Snapshot_JSON']);

        $token = $this->token($auditor);
        [, $visto] = $this->pedir('GET', "/api/v1/gov/auditoria?entidad={$entidad}", token: $token);
        self::assertSame(['DELETE', 'CREATE'], array_column($visto['items'], 'AUD_Accion'), 'Sus sedes y sus propias acciones.');
        [, $pagina] = $this->pedir('GET', "/api/v1/gov/auditoria?entidad={$entidad}&accion=CREATE&skip=0&limit=1");
        self::assertSame([1, 'CREATE'], [$pagina['total'], $pagina['items'][0]['AUD_Accion']]);

        [$e, $resumen] = $this->pedir('GET', '/api/v1/gov/auditoria/resumen?from_date=2000-01-01T00:00:00', token: $token);
        self::assertSame(200, $e);
        self::assertSame(2, $resumen['por_entidad'][$entidad], 'El resumen respeta el mismo alcance.');
        self::assertSame(['2000-01-01T00:00:00', null], [$resumen['from_date'], $resumen['to_date']]);

        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('GET', '/api/v1/gov/auditoria?limit=500'));
        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('GET', '/api/v1/gov/auditoria?from_date=ayer'));
        $tecnico = $this->token(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$propia]]));
        self::assertSame([403, 'NOT_ENOUGH_PERMISSIONS'], $this->detalle('GET', '/api/v1/gov/auditoria', token: $tecnico));
    }

    /** @param array{username: string} $u */
    private function token(array $u): string
    {
        return static::getContainer()->get(Tokens::class)->crearAcceso($u['username'], 'x');
    }

    /**
     * @param array<string, mixed>|null $cuerpo
     *
     * @return array{0: int, 1: mixed}
     */
    private function pedir(string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ('' !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.($token ?? $this->super);
        }
        $this->cliente->request($metodo, $ruta, server: $server, content: null === $cuerpo ? '' : json_encode($cuerpo));
        $r = $this->cliente->getResponse();

        return [$r->getStatusCode(), json_decode((string) $r->getContent(), true)];
    }

    /**
     * @param array<string, mixed>|null $cuerpo
     *
     * @return array{0: int, 1: mixed}
     */
    private function detalle(string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null): array
    {
        [$e, $r] = $this->pedir($metodo, $ruta, $cuerpo, $token);

        return [$e, $r['detail'] ?? null];
    }
}
