<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Security\Mfa\TrustedDevices;
use App\Seguridad\Tokens;
use App\Seguridad\Totp;
use App\Tests\Soporte\DatosPrueba as D;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** MFA flexible: omitirlo solo en el primer ingreso, excepción del administrador del sistema y equipos recordados. */
final class MfaFlexibleTest extends WebTestCase
{
    private const string SECRETO = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
    }

    public function testElMfaSeOmiteSoloEnElPrimerIngresoYElSiguienteLoExige(): void
    {
        $admin = D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]);
        $r = $this->login($admin['username'])['json'];
        self::assertSame('MFA_ENROLLMENT_REQUIRED', $r['restriction'], 'Se pide configurarlo desde el primer ingreso.');
        self::assertTrue($this->claims($r['access_token'])['mfa_can_skip'] ?? false, 'Y se ofrece omitirlo esta vez.');

        $omitido = $this->post('/api/v1/me/2fa/skip', [], $r['access_token']);
        self::assertSame(200, $omitido['estado']);
        self::assertNull($omitido['json']['restriction']);
        self::assertTrue($this->claims($omitido['json']['access_token'])['mfa_skipped'] ?? false);
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" WHERE "AUD_Accion" = \'MFA_ENROLLMENT_SKIPPED\' '
            .'AND DBMS_LOB.INSTR("AUD_Snapshot_JSON", :u) > 0', ['u' => $admin['username']]));
        // La misma sesión sigue sin restricción al renovarse.
        $renovado = $this->post('/api/v1/login/refresh', []);
        self::assertSame([200, null], [$renovado['estado'], $renovado['json']['restriction']]);

        // El siguiente ingreso lo exige y ya no deja omitirlo.
        $siguiente = $this->login($admin['username'])['json'];
        self::assertSame('MFA_ENROLLMENT_REQUIRED', $siguiente['restriction']);
        self::assertArrayNotHasKey('mfa_can_skip', $this->claims($siguiente['access_token']));
        self::assertSame([409, 'MFA_SKIP_ALREADY_USED'], $this->detalle($this->post('/api/v1/me/2fa/skip', [], $siguiente['access_token'])));
    }

    public function testQuienNoDebeTenerMfaNoPuedeOmitirlo(): void
    {
        $tecnico = D::crearUsuario();
        $acceso = $this->login($tecnico['username'])['json']['access_token'];
        self::assertSame([409, 'MFA_SKIP_NOT_AVAILABLE'], $this->detalle($this->post('/api/v1/me/2fa/skip', [], $acceso)));
    }

    public function testLaExcepcionLaDaSoloElAdministradorDelSistemaConPlazoYMotivo(): void
    {
        $sa = D::crearUsuario(['rol' => 'SUPER_ADMIN', 'metodo_2fa' => 'TOTP', 'secreto_totp' => self::SECRETO]);
        $admin = D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]);
        $ruta ='/api/v1/org/usuarios/'.$this->uuid($admin['id']).'/mfa-exemption';
        $hasta = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');

        $otro = $this->token(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));
        self::assertSame(403, $this->put($ruta, ['until' => $hasta, 'reason' => 'Sin teléfono aún'], $otro)['estado']);
        $tokenSa = $this->token($sa);
        self::assertSame([422, 'MFA_EXEMPTION_TOO_LONG'],
            $this->detalle($this->put($ruta, ['until' => (new \DateTimeImmutable('+40 days'))->format('Y-m-d'), 'reason' => 'Demasiado'], $tokenSa)));
        self::assertSame([422, 'MFA_EXEMPTION_IN_PAST'],
            $this->detalle($this->put($ruta, ['until' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d'), 'reason' => 'Ya pasó'], $tokenSa)));
        self::assertSame(422, $this->put($ruta, ['until' => $hasta], $tokenSa)['estado'], 'El motivo es obligatorio.');

        self::assertSame(200, $this->put($ruta, ['until' => $hasta, 'reason' => 'Sin teléfono aún'], $tokenSa)['estado']);
        self::assertNull($this->login($admin['username'])['json']['restriction'], 'Exenta: entra sin configurar el MFA.');
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" WHERE "AUD_Accion" = \'MFA_EXEMPTION_GRANTED\' '
            .'AND DBMS_LOB.INSTR("AUD_Snapshot_JSON", :u) > 0', ['u' => $admin['username']]));

        $this->cliente->request('DELETE', $ruta, server: ['HTTP_AUTHORIZATION' => "Bearer {$tokenSa}"]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('MFA_ENROLLMENT_REQUIRED', $this->login($admin['username'])['json']['restriction']);
    }

    public function testElEquipoRecordadoNoPideElCodigoHastaQueSeRevoca(): void
    {
        $u = D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true, 'metodo_2fa' => 'TOTP', 'secreto_totp' => self::SECRETO]);
        $desafio = $this->login($u['username'])['json'];
        self::assertSame(30, $desafio['remember_days']);

        $r = $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $desafio['challenge_token'],
            'code' => Totp::codigoEn(self::SECRETO, time()), 'remember_device' => true]);
        self::assertSame(200, $r['estado']);
        self::assertNotNull($this->cliente->getCookieJar()->get(TrustedDevices::COOKIE, '/api/v1/login'), 'El navegador recibe su marca.');

        $directo = $this->login($u['username'])['json'];
        self::assertArrayHasKey('access_token', $directo, 'Con el equipo recordado basta la contraseña.');
        self::assertArrayNotHasKey('requires_2fa', $directo);
        $equipos = $this->get('/api/v1/me/trusted-devices', $directo['access_token']);
        self::assertCount(1, $equipos);

        // Otro secreto MFA (restablecido y vuelto a configurar): el equipo deja de valer.
        $cifrado = D::conexion()->fetchOne('SELECT "USU_2FA_Secret" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $u['id']]);
        D::conexion()->executeStatement('UPDATE "INV_USUARIO" SET "USU_2FA_Secret" = :s WHERE "USU_Usuario" = :u', ['s' => $cifrado.'x', 'u' => $u['id']]);
        self::assertTrue($this->login($u['username'])['json']['requires_2fa'] ?? false);
        D::conexion()->executeStatement('UPDATE "INV_USUARIO" SET "USU_2FA_Secret" = :s WHERE "USU_Usuario" = :u', ['s' => $cifrado, 'u' => $u['id']]);
        self::assertArrayHasKey('access_token', $this->login($u['username'])['json']);

        // Cerrar todas las sesiones olvida los equipos.
        $this->post('/api/v1/login/logout-all', [], $directo['access_token']);
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM SYS_TRUSTED_DEVICE WHERE TDV_USER = :u', ['u' => $u['id']]));
        self::assertTrue($this->login($u['username'])['json']['requires_2fa'] ?? false);
    }

    public function testSinMarcarLaCasillaNoSeRecuerdaYCadaUnoBorraSoloLosSuyos(): void
    {
        $u = D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true, 'metodo_2fa' => 'TOTP', 'secreto_totp' => self::SECRETO]);
        $desafio = $this->login($u['username'])['json']['challenge_token'];
        $acceso = $this->post('/api/v1/login/2fa/verify', ['challenge_token' => $desafio, 'code' => Totp::codigoEn(self::SECRETO, time())])['json']['access_token'];
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM SYS_TRUSTED_DEVICE WHERE TDV_USER = :u', ['u' => $u['id']]));

        $ajeno = D::crearUsuario();
        D::conexion()->executeStatement('INSERT INTO SYS_TRUSTED_DEVICE (TDV_USER, TDV_TOKEN_HASH, TDV_STAMP, TDV_CREATED_AT, TDV_EXPIRES_AT) '
            ."VALUES (:u, :h, 'x', SYSTIMESTAMP, SYSTIMESTAMP + 1)", ['u' => $ajeno['id'], 'h' => hash('sha256', D::sufijo())]);
        $id = (int) D::conexion()->fetchOne('SELECT MAX(TDV_ID) FROM SYS_TRUSTED_DEVICE WHERE TDV_USER = :u', ['u' => $ajeno['id']]);
        $this->cliente->request('DELETE', "/api/v1/me/trusted-devices/{$id}", server: ['HTTP_AUTHORIZATION' => "Bearer {$acceso}"]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM SYS_TRUSTED_DEVICE WHERE TDV_ID = :id', ['id' => $id]));
    }

    // ---- apoyo ----

    /** @param array{username: string} $u */
    private function token(array $u): string
    {
        return static::getContainer()->get(Tokens::class)->crearAcceso($u['username'], 'x');
    }

    private function uuid(string $hex): string
    {
        return \App\Dominio\Ident::aTexto($hex);
    }

    /** @return array<string, mixed> */
    private function claims(string $jwt): array
    {
        return (array) json_decode((string) base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
    }

    /** @return array{estado: int, json: array<string, mixed>} */
    private function login(string $username): array
    {
        $this->cliente->request('POST', '/api/v1/login/access-token', ['username' => $username, 'password' => D::PASSWORD]);

        return $this->respuesta();
    }

    /**
     * @param array<string, mixed> $cuerpo
     *
     * @return array{estado: int, json: array<string, mixed>}
     */
    private function post(string $ruta, array $cuerpo, ?string $acceso = null): array
    {
        $this->cliente->request('POST', $ruta, server: $this->cabeceras($acceso), content: [] === $cuerpo ? '' : (string) json_encode($cuerpo));

        return $this->respuesta();
    }

    /**
     * @param array<string, mixed> $cuerpo
     *
     * @return array{estado: int, json: array<string, mixed>}
     */
    private function put(string $ruta, array $cuerpo, string $acceso): array
    {
        $this->cliente->request('PUT', $ruta, server: $this->cabeceras($acceso), content: (string) json_encode($cuerpo));

        return $this->respuesta();
    }

    /** @return array<mixed> */
    private function get(string $ruta, string $acceso): array
    {
        $this->cliente->request('GET', $ruta, server: ['HTTP_AUTHORIZATION' => "Bearer {$acceso}"]);

        return $this->respuesta()['json'];
    }

    /**
     * @param array{estado: int, json: array<string, mixed>} $r
     *
     * @return array{0: int, 1: mixed}
     */
    private function detalle(array $r): array
    {
        return [$r['estado'], $r['json']['detail'] ?? null];
    }

    /** @return array<string, string> */
    private function cabeceras(?string $acceso): array
    {
        return ['CONTENT_TYPE' => 'application/json', ...(null === $acceso ? [] : ['HTTP_AUTHORIZATION' => "Bearer {$acceso}"])];
    }

    /** @return array{estado: int, json: array<string, mixed>} */
    private function respuesta(): array
    {
        $r = $this->cliente->getResponse();

        return ['estado' => $r->getStatusCode(), 'json' => (array) json_decode((string) $r->getContent(), true)];
    }
}
