<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Dominio\Ident;
use App\Seguridad\CodigosSeguridad;
use App\Seguridad\Tokens;
use App\Seguridad\Totp;
use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Mailpit;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fase 1: refresh con rotación y detección de reuso, cierre de sesión,
 * segundo factor (TOTP, correo, recuperación), restablecimiento y cambio de
 * contraseña, sesiones y preferencias, contra Oracle real y Mailpit.
 */
final class AutenticacionTest extends WebTestCase
{
    private const string SECRETO = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
    }

    // ---- Refresh -------------------------------------------------------

    public function testElRefreshRotaYUnReusoFueraDeLaGraciaRevocaTodo(): void
    {
        $u = D::crearUsuario();
        $this->login($u['username']);
        $refresh1 = $this->cookieRefresh();

        $r = $this->post('/api/v1/login/refresh');
        self::assertSame(200, $r['estado']);
        $refresh2 = $this->cookieRefresh();
        self::assertNotSame($this->jti($refresh1), $this->jti($refresh2), 'El refresh se rota.');
        self::assertSame($this->sid($refresh1), $this->sid($refresh2), 'Misma sesión.');
        self::assertSame($this->jti($refresh2), D::conexion()->fetchOne('SELECT "SES_Refresh_Jti" FROM "SYS_SESION" WHERE "SES_Sesion" = :s',
            ['s' => Ident::aHex($this->sid($refresh2))]));

        // Reuso dentro de la gracia (pestañas simultáneas): 401 sin castigo.
        $r = $this->post('/api/v1/login/refresh', ['refresh_token' => $refresh1]);
        self::assertSame([401, 'TOKEN_REVOKED'], [$r['estado'], $r['json']['detail']]);
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "SYS_TOKEN_REVOCADO" WHERE "TRV_Jti" = :j', ['j' => 'USR-ALL-'.Ident::aTexto($u['id'])]));

        // Fuera de la gracia: robo del token → se revoca todo.
        D::conexion()->executeStatement(
            'UPDATE "SYS_TOKEN_REVOCADO" SET "TRV_Fecha_Revocacion" = "TRV_Fecha_Revocacion" - INTERVAL \'2\' MINUTE WHERE "TRV_Jti" = :j',
            ['j' => $this->jti($refresh1)],
        );
        $r = $this->post('/api/v1/login/refresh', ['refresh_token' => $refresh1]);
        self::assertSame([401, 'TOKEN_REVOKED'], [$r['estado'], $r['json']['detail']]);
        self::assertSame(1, $this->auditorias($u['id'], 'REFRESH_TOKEN_REUSE'));
        $r = $this->post('/api/v1/login/refresh', ['refresh_token' => $refresh2]);
        self::assertSame(401, $r['estado'], 'El refresh legítimo también deja de valer.');
    }

    public function testErroresDelRefresh(): void
    {
        $this->cliente->getCookieJar()->clear();
        $r = $this->post('/api/v1/login/refresh');
        self::assertSame([401, 'MISSING_REFRESH_TOKEN'], [$r['estado'], $r['json']['detail']]);

        $u = D::crearUsuario();
        $acceso = $this->login($u['username'])['json']['access_token'];
        $r = $this->post('/api/v1/login/refresh', ['refresh_token' => $acceso]);
        self::assertSame([401, 'INVALID_TOKEN_TYPE'], [$r['estado'], $r['json']['detail']]);

        // Sesión cerrada: su refresh deja de valer.
        $refresh = $this->cookieRefresh();
        D::conexion()->executeStatement('UPDATE "SYS_SESION" SET "SES_Cerrada_En" = SYSTIMESTAMP WHERE "SES_Sesion" = :s',
            ['s' => Ident::aHex($this->sid($refresh))]);
        $r = $this->post('/api/v1/login/refresh', ['refresh_token' => $refresh]);
        self::assertSame([401, 'TOKEN_REVOKED'], [$r['estado'], $r['json']['detail']]);
    }

    public function testElRefreshLevantaLaRestriccionAlEnrolarMfa(): void
    {
        $u = D::crearUsuario(['rol' => 'ADMIN_TI']);
        $acceso = $this->login($u['username'])['json']['access_token'];
        self::assertSame('MFA_ENROLLMENT_REQUIRED', $this->restriccion($acceso));

        $setup = $this->post('/api/v1/me/2fa/totp/setup', [], $acceso);
        self::assertSame(200, $setup['estado']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $setup['json']['qr_data_uri']);
        self::assertStringStartsWith('otpauth://totp/', $setup['json']['otpauth_uri']);
        $activar = $this->post('/api/v1/me/2fa/totp/activate', ['code' => Totp::codigoEn($setup['json']['secret'], time())], $acceso);
        self::assertSame(200, $activar['estado']);
        self::assertCount(8, $activar['json']['recovery_codes']);

        $r = $this->post('/api/v1/login/refresh');
        self::assertSame(200, $r['estado']);
        self::assertNull($r['json']['restriction']);
        self::assertNull($this->restriccion($r['json']['access_token']));
    }

    // ---- Cierre de sesión ----------------------------------------------

    public function testLogoutRevocaAmbosTokensCierraLaSesionYBorraLaCookie(): void
    {
        $u = D::crearUsuario();
        $acceso = $this->login($u['username'])['json']['access_token'];
        $refresh = $this->cookieRefresh();

        $this->cliente->request('POST', '/api/v1/login/logout', server: ['HTTP_AUTHORIZATION' => "Bearer {$acceso}"]);
        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->cliente->getCookieJar()->get('rtk', '/api/v1/login'), 'Cookie borrada.');
        self::assertSame(1, $this->auditorias($u['id'], 'LOGOUT'));
        self::assertSame('logout', D::conexion()->fetchOne('SELECT "SES_Motivo_Cierre" FROM "SYS_SESION" WHERE "SES_Sesion" = :s',
            ['s' => Ident::aHex($this->sid($refresh))]));

        $this->get('/api/v1/me', $acceso);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(401, $this->post('/api/v1/login/refresh', ['refresh_token' => $refresh])['estado']);

        // Sin tokens o con tokens falsos: igual 204.
        $this->cliente->request('POST', '/api/v1/login/logout', server: ['HTTP_AUTHORIZATION' => 'Bearer x.y.z']);
        self::assertResponseStatusCodeSame(204);
    }

    public function testLogoutAllCierraTodasLasSesiones(): void
    {
        $u = D::crearUsuario();
        $a1 = $this->login($u['username'])['json']['access_token'];
        $a2 = $this->login($u['username'])['json']['access_token'];

        $this->cliente->request('POST', '/api/v1/login/logout-all', server: ['HTTP_AUTHORIZATION' => "Bearer {$a1}"]);
        self::assertResponseStatusCodeSame(204);
        foreach ([$a1, $a2] as $acceso) {
            $this->get('/api/v1/me', $acceso);
            self::assertResponseStatusCodeSame(401);
        }
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "SYS_SESION" WHERE "USU_Usuario" = :u AND "SES_Cerrada_En" IS NULL', ['u' => $u['id']]));
    }

    // ---- Segundo factor en el login ------------------------------------

    public function testLoginConTotpYProteccionesDelDesafio(): void
    {
        $u = D::crearUsuario(['metodo_2fa' => 'TOTP', 'secreto_totp' => self::SECRETO]);
        $desafio = $this->login($u['username'])['json']['challenge_token'];

        $r = $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $desafio, 'code' => '000000']);
        self::assertSame([400, 'INVALID_2FA_CODE'], [$r['estado'], $r['json']['detail']]);
        self::assertSame(1, (int) D::conexion()->fetchOne('SELECT "USU_Intentos_Fallidos" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $u['id']]));

        $codigo = Totp::codigoEn(self::SECRETO, time());
        $r = $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $desafio, 'code' => $codigo]);
        self::assertSame(200, $r['estado']);
        self::assertSame('2fa', D::conexion()->fetchOne('SELECT "SES_Metodo" FROM "SYS_SESION" WHERE "SES_Sesion" = :s',
            ['s' => Ident::aHex($this->sid($r['json']['access_token']))]));
        self::assertSame(0, (int) D::conexion()->fetchOne('SELECT "USU_Intentos_Fallidos" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $u['id']]));

        // El desafío sirve una sola vez.
        $r = $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $desafio, 'code' => $codigo]);
        self::assertSame([401, 'INVALID_OR_EXPIRED_2FA_CHALLENGE'], [$r['estado'], $r['json']['detail']]);
        // Un código ya usado se informa sin sumar al bloqueo.
        $otro = $this->login($u['username'])['json']['challenge_token'];
        $r = $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $otro, 'code' => $codigo]);
        self::assertSame([400, 'TOTP_ALREADY_USED'], [$r['estado'], $r['json']['detail']]);
        // Desafío manipulado.
        $r = $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $otro.'x', 'code' => $codigo]);
        self::assertSame(401, $r['estado']);
    }

    public function testCodigoDeRecuperacionDeUnSoloUsoYDesafioLigadoALaContrasena(): void
    {
        $u = D::crearUsuario(['metodo_2fa' => 'TOTP', 'secreto_totp' => self::SECRETO]);
        $codigos = static::getContainer()->get(CodigosSeguridad::class);
        D::conexion()->executeStatement(
            'INSERT INTO "SYS_2FA_RECOVERY" ("TRC_Id", "TRC_Code_Hash", "TRC_Usado", "TRC_Creado_En", "USU_Usuario") '
            .'VALUES (:id, :h, 0, SYSTIMESTAMP, :u)',
            ['id' => Ident::nuevo(), 'h' => $codigos->hash('ABCD-EFGH'), 'u' => $u['id']],
        );
        $desafio = $this->login($u['username'])['json']['challenge_token'];
        self::assertSame(200, $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $desafio, 'code' => 'abcd-efgh'])['estado']);

        $desafio = $this->login($u['username'])['json']['challenge_token'];
        self::assertSame(400, $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $desafio, 'code' => 'ABCD-EFGH'])['estado']);

        // Si la contraseña cambia, un desafío emitido antes deja de valer.
        D::conexion()->executeStatement('UPDATE "INV_USUARIO" SET "USU_Password_Hash" = :h WHERE "USU_Usuario" = :u',
            ['h' => \App\Seguridad\Contrasenas::hashear('Otra.Clave#2026'), 'u' => $u['id']]);
        $r = $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $desafio, 'code' => Totp::codigoEn(self::SECRETO, time() + 30)]);
        self::assertSame([401, 'INVALID_OR_EXPIRED_2FA_CHALLENGE'], [$r['estado'], $r['json']['detail']]);
    }

    public function testLoginConCodigoPorCorreo(): void
    {
        $u = D::crearUsuario(['metodo_2fa' => 'EMAIL']);
        $r = $this->login($u['username']);
        self::assertSame(['requires_2fa' => true, 'method' => 'EMAIL'], array_intersect_key($r['json'], ['requires_2fa' => 0, 'method' => 0]));

        $codigo = $this->codigoDelCorreo($u['correo']);
        $mal = $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $r['json']['challenge_token'], 'code' => '99999'.((int) $codigo[5] + 1) % 10]);
        self::assertSame(400, $mal['estado']);
        $ok = $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $r['json']['challenge_token'], 'code' => $codigo]);
        self::assertSame(200, $ok['estado']);
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "SYS_2FA_CODE" WHERE "USU_Usuario" = :u AND "TFC_Usado" = 0', ['u' => $u['id']]));
    }

    public function testElCodigoPorCorreoSeInvalidaTrasDemasiadosIntentos(): void
    {
        $u = D::crearUsuario(['metodo_2fa' => 'EMAIL', 'rol' => 'CONSULTA']);
        $desafio = $this->login($u['username'])['json']['challenge_token'];
        $codigo = $this->codigoDelCorreo($u['correo']);
        D::conexion()->executeStatement('UPDATE "SYS_2FA_CODE" SET "TFC_Intentos" = 5 WHERE "USU_Usuario" = :u', ['u' => $u['id']]);

        self::assertSame(400, $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $desafio, 'code' => $codigo])['estado']);
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "SYS_2FA_CODE" WHERE "USU_Usuario" = :u AND "TFC_Usado" = 0', ['u' => $u['id']]));
    }

    // ---- Enrolamiento y gestión del segundo factor ---------------------

    public function testEnrolamientoPorCorreoDesactivacionYRegeneracion(): void
    {
        $u = D::crearUsuario(['rol' => 'TECNICO']);
        $acceso = $this->login($u['username'])['json']['access_token'];
        self::assertSame(['habilitado' => false, 'metodo' => null, 'requerido' => false], $this->get('/api/v1/me/2fa', $acceso));

        self::assertSame(202, $this->post('/api/v1/me/2fa/email/setup', [], $acceso)['estado']);
        $r = $this->post('/api/v1/me/2fa/email/activate', ['code' => $this->codigoDelCorreo($u['correo'])], $acceso);
        self::assertSame(200, $r['estado']);
        $primeros = $r['json']['recovery_codes'];
        self::assertSame(['habilitado' => true, 'metodo' => 'EMAIL', 'requerido' => false], $this->get('/api/v1/me/2fa', $acceso));
        self::assertSame([409, '2FA_ALREADY_ENABLED'], $this->estadoYDetalle($this->post('/api/v1/me/2fa/totp/setup', [], $acceso)));

        // Fase 8: regenerar exige volver a autenticarse (un token robado no obtiene códigos nuevos).
        self::assertSame(422, $this->post('/api/v1/me/2fa/recovery-codes/regenerate', [], $acceso)['estado']);
        self::assertSame([400, 'INCORRECT_CURRENT_PASSWORD'],
            $this->estadoYDetalle($this->post('/api/v1/me/2fa/recovery-codes/regenerate', ['password' => 'mala'], $acceso)));
        $nuevos = $this->post('/api/v1/me/2fa/recovery-codes/regenerate', ['password' => D::PASSWORD], $acceso)['json']['recovery_codes'];
        self::assertCount(8, $nuevos);
        self::assertSame([], array_intersect($primeros, $nuevos));
        self::assertSame(8, D::contar('SELECT COUNT(*) FROM "SYS_2FA_RECOVERY" WHERE "USU_Usuario" = :u', ['u' => $u['id']]));

        self::assertSame([400, 'INCORRECT_CURRENT_PASSWORD'], $this->estadoYDetalle($this->post('/api/v1/me/2fa/disable', ['password' => 'mala'], $acceso)));
        self::assertSame(2, (int) D::conexion()->fetchOne('SELECT "USU_Intentos_Fallidos" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $u['id']]));
        self::assertSame(204, $this->post('/api/v1/me/2fa/disable', ['password' => D::PASSWORD], $acceso)['estado']);
        self::assertSame(['habilitado' => false, 'metodo' => null, 'requerido' => false], $this->get('/api/v1/me/2fa', $acceso));
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "SYS_2FA_RECOVERY" WHERE "USU_Usuario" = :u', ['u' => $u['id']]));
        self::assertSame(1, $this->auditorias($u['id'], '2FA_DISABLED'));
    }

    public function testElSistemaPermiteApagarYEncenderElMfaObligatorio(): void
    {
        $super = static::getContainer()->get(\App\Seguridad\Tokens::class)
            ->crearAcceso(D::crearUsuario(['rol' => 'SUPER_ADMIN', 'global' => true])['username'], 'x');
        $admin = D::crearUsuario(['rol' => 'ADMIN_TI']);
        $tokenAdmin = static::getContainer()->get(\App\Seguridad\Tokens::class)->crearAcceso($admin['username'], 'x');

        try {
            // Solo el administrador del sistema lo cambia; no es parte de la configuración pública.
            self::assertArrayNotHasKey('SYS_Mfa_Required', $this->pedirConfig());
            self::assertSame(403, $this->cambiarMfa(false, $tokenAdmin)['estado']);
            self::assertSame(422, $this->post('/api/v1/gov/security/mfa', ['required' => 'no'], $super)['estado']);

            $apagado = $this->cambiarMfa(false, $super);
            self::assertSame([200, false], [$apagado['estado'], $apagado['json']['required']]);
            self::assertNull($this->restriccion($this->login($admin['username'])['json']['access_token']));
            self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" WHERE "AUD_Accion" = \'MFA_REQUIREMENT_CHANGED\' '
                .'AND DBMS_LOB.INSTR("AUD_Snapshot_JSON", \'"required":false\') > 0 AND "AUD_Fecha_Hora" > SYSTIMESTAMP - INTERVAL \'1\' MINUTE'));

            $encendido = $this->cambiarMfa(true, $super);
            self::assertSame([200, true], [$encendido['estado'], $encendido['json']['required']]);
            self::assertSame('MFA_ENROLLMENT_REQUIRED', $this->restriccion($this->login($admin['username'])['json']['access_token']));
        } finally {
            D::conexion()->executeStatement('UPDATE "SYS_CONFIGURACION" SET "SYS_Mfa_Required" = 1');
        }
    }

    /** @return array<string, mixed> */
    private function pedirConfig(): array
    {
        $this->cliente->request('GET', '/api/v1/gov/config');

        return (array) json_decode((string) $this->cliente->getResponse()->getContent(), true);
    }

    /** @return array{estado: int, json: array<string, mixed>} */
    private function cambiarMfa(bool $requerido, string $acceso): array
    {
        return $this->post('/api/v1/gov/security/mfa', ['required' => $requerido], $acceso);
    }

    public function testUnRolQueExigeMfaNoPuedeDesactivarlo(): void
    {
        $u = D::crearUsuario(['rol' => 'ADMIN_TI', 'metodo_2fa' => 'TOTP', 'secreto_totp' => self::SECRETO]);
        $desafio = $this->login($u['username'])['json']['challenge_token'];
        $acceso = $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $desafio, 'code' => Totp::codigoEn(self::SECRETO, time())])['json']['access_token'];

        self::assertSame([403, '2FA_REQUIRED_FOR_THIS_ROLE'], $this->estadoYDetalle($this->post('/api/v1/me/2fa/disable', ['password' => D::PASSWORD], $acceso)));
        self::assertSame(422, $this->post('/api/v1/me/2fa/totp/activate', ['code' => '12'], $acceso)['estado']);
    }

    // ---- Restablecimiento y cambio de contraseña -----------------------

    public function testRestablecimientoCompletoPorCorreo(): void
    {
        $u = D::crearUsuario();
        $previo = $this->login($u['username'])['json']['access_token'];

        $generica = ['message' => 'Si la cuenta existe, se enviaron instrucciones al correo registrado.'];
        $r = $this->post('/api/v1/login/password-reset/request', ['identifier' => $u['username']]);
        self::assertSame([202, $generica], [$r['estado'], $r['json']]);
        self::assertSame([202, $generica], [$this->post('/api/v1/login/password-reset/request', ['identifier' => 'no_existe_'.D::sufijo()])['estado'], $generica]);
        // Enfriamiento por cuenta: una segunda solicitud inmediata no envía otro correo.
        $this->post('/api/v1/login/password-reset/request', ['identifier' => strtoupper($u['correo'])]);
        $correos = Mailpit::para($u['correo']);
        self::assertCount(1, $correos);
        self::assertSame(1, preg_match('~/reset-password#token=([A-Za-z0-9_-]{43})~', (string) $correos[0]['HTML'], $m));
        $token = $m[1];

        $debil = $this->post('/api/v1/login/password-reset/confirm', ['token' => $token, 'new_password' => 'corta']);
        self::assertSame([400, 'PASSWORD_POLICY_VIOLATION:min_length:10,require_upper,require_digit'], $this->estadoYDetalle($debil));
        $ok = $this->post('/api/v1/login/password-reset/confirm', ['token' => $token, 'new_password' => 'Zx9!kLm2Qw7r']);
        self::assertSame(204, $ok['estado'], 'Una contraseña rechazada no gasta el enlace.');
        self::assertSame([400, 'INVALID_OR_EXPIRED_RESET_TOKEN'],
            $this->estadoYDetalle($this->post('/api/v1/login/password-reset/confirm', ['token' => $token, 'new_password' => 'Otra.Zx9!kLm2'])));

        $this->get('/api/v1/me', $previo);
        self::assertResponseStatusCodeSame(401, 'Todos los tokens previos quedan revocados.');
        self::assertSame(200, $this->login($u['username'], 'Zx9!kLm2Qw7r')['estado']);
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "SYS_EMAIL_OUTBOX" WHERE "EOB_Plantilla" = \'password_changed\' AND DBMS_LOB.INSTR("EOB_Para", :c) > 0', ['c' => $u['correo']]));
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "SYS_PASSWORD_HISTORIAL" WHERE "USU_Usuario" = :u', ['u' => $u['id']]));
    }

    public function testUnaCuentaSoloSsoNoObtieneContrasenaPorRestablecimiento(): void
    {
        // Quien controle el buzón no debe poder convertir una cuenta del proveedor de identidad en una con contraseña.
        $u = D::crearUsuario(['sso' => 'microsoft']);
        D::conexion()->executeStatement('UPDATE "INV_USUARIO" SET "USU_Password_Hash" = NULL WHERE "USU_Usuario" = :u', ['u' => $u['id']]);
        $r = $this->post('/api/v1/login/password-reset/request', ['identifier' => $u['username']]);
        self::assertSame(202, $r['estado'], 'Misma respuesta genérica: no revela el tipo de cuenta.');
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "SYS_PASSWORD_RESET" WHERE "USU_Usuario" = :u', ['u' => $u['id']]));
        self::assertCount(0, Mailpit::para($u['correo']));
    }

    public function testCambioDeContrasenaConPoliticaHistorialYBloqueo(): void
    {
        $u = D::crearUsuario(['debe_cambiar' => true]);
        $acceso = $this->login($u['username'])['json']['access_token'];

        self::assertSame([400, 'INCORRECT_CURRENT_PASSWORD'],
            $this->estadoYDetalle($this->post('/api/v1/me/password', ['current_password' => 'mala', 'new_password' => 'Zx9!kLm2Qw7r'], $acceso)));
        self::assertSame(1, (int) D::conexion()->fetchOne('SELECT "USU_Intentos_Fallidos" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $u['id']]));
        // (Límite: 5 cambios por minuto por usuario; esta prueba usa exactamente 5.)
        self::assertSame(204, $this->post('/api/v1/me/password', ['current_password' => D::PASSWORD, 'new_password' => 'Zx9!kLm2Qw7r'], $acceso)['estado']);
        self::assertSame(0, (int) D::conexion()->fetchOne('SELECT "USU_Debe_Cambiar_Password" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $u['id']]));
        $this->get('/api/v1/me', $acceso);
        self::assertResponseStatusCodeSame(401, 'El cambio revoca todos los tokens.');

        $nuevo = $this->login($u['username'], 'Zx9!kLm2Qw7r')['json']['access_token'];
        self::assertNull($this->restriccion($nuevo), 'La contraseña temporal quedó reemplazada.');
        self::assertSame([400, 'PASSWORD_SAME_AS_OLD'],
            $this->estadoYDetalle($this->post('/api/v1/me/password', ['current_password' => 'Zx9!kLm2Qw7r', 'new_password' => 'Zx9!kLm2Qw7r'], $nuevo)));
        self::assertSame(204, $this->post('/api/v1/me/password', ['current_password' => 'Zx9!kLm2Qw7r', 'new_password' => 'Hq4#vRt8Mn2w'], $nuevo)['estado']);
        $tercero = $this->login($u['username'], 'Hq4#vRt8Mn2w')['json']['access_token'];
        self::assertSame([400, 'PASSWORD_REUSED:3'],
            $this->estadoYDetalle($this->post('/api/v1/me/password', ['current_password' => 'Hq4#vRt8Mn2w', 'new_password' => 'Zx9!kLm2Qw7r'], $tercero)));
    }

    public function testElLimiteDePeticionesEsPorUsuario(): void
    {
        $u = D::crearUsuario();
        $acceso = $this->login($u['username'])['json']['access_token'];
        foreach (range(1, 5) as $_) {
            self::assertSame(400, $this->post('/api/v1/me/password', ['current_password' => 'x', 'new_password' => 'y'], $acceso)['estado']);
        }
        self::assertSame([429, 'RATE_LIMIT_EXCEEDED'],
            $this->estadoYDetalle($this->post('/api/v1/me/password', ['current_password' => 'x', 'new_password' => 'y'], $acceso)));
        // Otro usuario no comparte el cupo.
        $otro = $this->login(D::crearUsuario()['username'])['json']['access_token'];
        self::assertSame(400, $this->post('/api/v1/me/password', ['current_password' => 'x', 'new_password' => 'y'], $otro)['estado']);
    }

    // ---- Sesiones, dispositivo nuevo y preferencias --------------------

    public function testSesionesActivasCierreIndividualYAvisoDeDispositivoNuevo(): void
    {
        $u = D::crearUsuario();
        $windows = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0';
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile Safari/604.1';
        $a1 = $this->login($u['username'], ua: $windows)['json']['access_token'];
        self::assertSame(0, $this->avisosDispositivo($u['correo']), 'Primera sesión: no hay contra qué comparar.');
        $a2 = $this->login($u['username'], ua: $iphone)['json']['access_token'];
        self::assertSame(1, $this->avisosDispositivo($u['correo']));
        $this->login($u['username'], ua: $iphone);
        self::assertSame(1, $this->avisosDispositivo($u['correo']), 'Dispositivo ya conocido.');

        $sesiones = $this->get('/api/v1/me/sessions', $a1);
        self::assertCount(3, $sesiones);
        $actual = array_values(array_filter($sesiones, static fn (array $s): bool => $s['actual']));
        self::assertSame([$this->sid($a1)], array_column($actual, 'id'));
        self::assertSame(['id', 'usuario_id', 'creada_en', 'ultima_actividad', 'expira', 'ip', 'dispositivo', 'metodo', 'actual'], array_keys($sesiones[0]));

        $this->cliente->request('DELETE', '/api/v1/me/sessions/'.$this->sid($a2), server: ['HTTP_AUTHORIZATION' => "Bearer {$a1}"]);
        self::assertResponseStatusCodeSame(204);
        $this->get('/api/v1/me', $a2);
        self::assertResponseStatusCodeSame(401);

        $ajena = $this->login(D::crearUsuario()['username'])['json']['access_token'];
        $this->cliente->request('DELETE', '/api/v1/me/sessions/'.$this->sid($ajena), server: ['HTTP_AUTHORIZATION' => "Bearer {$a1}"]);
        self::assertResponseStatusCodeSame(404);
        $this->cliente->request('DELETE', "/api/v1/me/sessions/x'or'1'='1", server: ['HTTP_AUTHORIZATION' => "Bearer {$a1}"]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPreferencias(): void
    {
        $acceso = $this->login(D::crearUsuario()['username'])['json']['access_token'];
        self::assertSame(['tema' => 'sistema', 'guias_desactivadas' => false, 'guias_vistas' => []], $this->get('/api/v1/me/preferencias', $acceso));

        $this->cliente->request('PATCH', '/api/v1/me/preferencias', server: $this->json($acceso),
            content: json_encode(['tema' => 'oscuro', 'guias_vistas' => ['activos', 'activos', ' compras ']]));
        self::assertResponseIsSuccessful();
        $this->cliente->request('PATCH', '/api/v1/me/preferencias', server: $this->json($acceso), content: json_encode(['guias_desactivadas' => true]));
        self::assertSame(['tema' => 'oscuro', 'guias_desactivadas' => true, 'guias_vistas' => ['activos', 'compras']], $this->get('/api/v1/me/preferencias', $acceso));

        foreach ([['tema' => 'rosa'], ['guias_vistas' => ['<script>']], ['guias_desactivadas' => 'si'], ['guias_vistas' => array_fill(0, 51, 'a')]] as $malo) {
            $this->cliente->request('PATCH', '/api/v1/me/preferencias', server: $this->json($acceso), content: json_encode($malo));
            self::assertResponseStatusCodeSame(422);
        }
    }

    // ---- Apoyo ---------------------------------------------------------

    /** @return array{estado: int, json: array<string, mixed>} */
    private function login(string $username, string $password = D::PASSWORD, ?string $ua = null): array
    {
        $this->cliente->request('POST', '/api/v1/login/access-token', ['username' => $username, 'password' => $password],
            server: null === $ua ? [] : ['HTTP_USER_AGENT' => $ua]);

        return $this->respuesta();
    }

    /**
     * @param array<string, mixed> $cuerpo
     *
     * @return array{estado: int, json: array<string, mixed>}
     */
    private function post(string $ruta, array $cuerpo = [], ?string $acceso = null): array
    {
        $this->cliente->request('POST', $ruta, server: $this->json($acceso), content: [] === $cuerpo ? '' : json_encode($cuerpo));

        return $this->respuesta();
    }

    /** @return array<mixed> */
    private function get(string $ruta, string $acceso): array
    {
        $this->cliente->request('GET', $ruta, server: ['HTTP_AUTHORIZATION' => "Bearer {$acceso}"]);

        return $this->respuesta()['json'];
    }

    /** @return array<string, string> */
    private function json(?string $acceso): array
    {
        return ['CONTENT_TYPE' => 'application/json', ...(null === $acceso ? [] : ['HTTP_AUTHORIZATION' => "Bearer {$acceso}"])];
    }

    /** @return array{estado: int, json: array<mixed>} */
    private function respuesta(): array
    {
        $r = $this->cliente->getResponse();

        return ['estado' => $r->getStatusCode(), 'json' => (array) json_decode((string) $r->getContent(), true)];
    }

    /**
     * @param array{estado: int, json: array<mixed>} $r
     *
     * @return array{0: int, 1: mixed}
     */
    private function estadoYDetalle(array $r): array
    {
        return [$r['estado'], $r['json']['detail'] ?? null];
    }

    private function cookieRefresh(): string
    {
        return (string) $this->cliente->getCookieJar()->get('rtk', '/api/v1/login')?->getValue();
    }

    /** @return array<string, mixed> */
    private function payload(string $token): array
    {
        return (array) JWT::decode($token, new Key($_SERVER['SECRET_KEY'], 'HS256'));
    }

    private function jti(string $token): string
    {
        return (string) $this->payload($token)['jti'];
    }

    private function sid(string $token): string
    {
        return (string) $this->payload($token)['sid'];
    }

    private function restriccion(string $acceso): ?string
    {
        return \App\Seguridad\PoliticaSesion::restriccion($this->payload($acceso));
    }

    private function codigoDelCorreo(string $correo): string
    {
        $mensajes = Mailpit::para($correo);
        self::assertNotEmpty($mensajes, 'No llegó el código por correo.');
        self::assertSame(1, preg_match('/\b(\d{6})\b/', (string) $mensajes[0]['Text'], $m));

        return $m[1];
    }

    private function avisosDispositivo(string $correo): int
    {
        return D::contar('SELECT COUNT(*) FROM "SYS_EMAIL_OUTBOX" WHERE "EOB_Plantilla" = \'nuevo_dispositivo\' AND DBMS_LOB.INSTR("EOB_Para", :c) > 0', ['c' => $correo]);
    }

    private function auditorias(string $usuario, string $accion): int
    {
        return D::contar('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" WHERE "USU_Usuario" = :u AND "AUD_Accion" = :a', ['u' => $usuario, 'a' => $accion]);
    }
}
