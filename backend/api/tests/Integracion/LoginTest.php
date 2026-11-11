<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Config\Ajustes;
use App\Dominio\Ident;
use App\Seguridad\BloqueoCuenta;
use App\Seguridad\CacheAuth;
use App\Tests\Soporte\DatosPrueba as D;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Inicio de sesión y autenticación contra Oracle 21c real (VPD, disparadores
 * y bitácora de solo inserción incluidos). Mismo contrato que el backend anterior.
 */
final class LoginTest extends WebTestCase
{
    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
    }

    public function testLoginCorrectoEntregaTokensRegistraSesionYAudita(): void
    {
        $sede = D::crearSede('Sede '.D::sufijo());
        $u = D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$sede]]);

        $r = $this->login($u['username'], D::PASSWORD, ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0']);

        self::assertSame(200, $r['estado']);
        self::assertSame(['access_token', 'token_type', 'expires_in', 'restriction'], array_keys($r['json']));
        self::assertSame('bearer', $r['json']['token_type']);
        self::assertSame(900, $r['json']['expires_in']);
        self::assertNull($r['json']['restriction']);
        // Symfony añade "private" a no-store; el efecto para cachés intermedias es el mismo.
        self::assertStringContainsString('no-store', (string) $this->cliente->getResponse()->headers->get('Cache-Control'));

        $acceso = (array) JWT::decode($r['json']['access_token'], new Key($_SERVER['SECRET_KEY'], 'HS256'));
        self::assertSame($u['username'], $acceso['sub']);
        self::assertSame('TECNICO', $acceso['role']);

        $cookie = $this->cliente->getCookieJar()->get('rtk', '/api/v1/login');
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', strtolower((string) $cookie->getSameSite()));
        $refresh = (array) JWT::decode($cookie->getValue(), new Key($_SERVER['SECRET_KEY'], 'HS256'));
        self::assertSame('refresh', $refresh['type']);
        self::assertSame($acceso['sid'], $refresh['sid']);

        $sesion = D::conexion()->fetchAssociative(
            'SELECT "SES_Dispositivo", "SES_Metodo", "SES_Refresh_Jti" FROM "SYS_SESION" WHERE "SES_Sesion" = :s',
            ['s' => Ident::aHex($acceso['sid'])],
        );
        self::assertSame(['SES_Dispositivo' => 'Chrome · Windows', 'SES_Metodo' => 'password', 'SES_Refresh_Jti' => $refresh['jti']], $sesion);
        self::assertSame(1, $this->auditorias($u['id'], 'LOGIN_SUCCESS'));
        self::assertNotNull(D::conexion()->fetchOne('SELECT "USU_Ultimo_Login" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $u['id']]));
    }

    public function testMeDevuelveUsuarioYAlcanceOrdenado(): void
    {
        $sufijo = D::sufijo();
        $b = D::crearSede("B sede {$sufijo}");
        $a = D::crearSede("A sede {$sufijo}");
        $u = D::crearUsuario(['rol' => 'AUDITOR', 'sedes' => [$b, $a]]);

        $this->cliente->request('GET', '/api/v1/me', server: $this->bearer($u['username']));
        $me = json_decode((string) $this->cliente->getResponse()->getContent(), true);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'username' => $u['username'],
            'role' => 'AUDITOR',
            'active' => true,
            'person_id' => Ident::aTexto($u['persona']),
            'scope' => ['global' => false, 'sedes' => [
                ['id' => $a, 'nombre' => "A sede {$sufijo}"],
                ['id' => $b, 'nombre' => "B sede {$sufijo}"],
            ]],
            'foto_url' => null,
        ], $me);
    }

    public function testCincoFallosBloqueanYLaContrasenaCorrectaRecibe429(): void
    {
        $u = D::crearUsuario();
        foreach (range(1, 5) as $_) {
            $r = $this->login($u['username'], 'Incorrecta.2026');
            self::assertSame([400, 'INCORRECT_USERNAME_OR_PASSWORD'], [$r['estado'], $r['json']['detail']]);
        }
        self::assertSame(1, $this->auditorias($u['id'], 'ACCOUNT_LOCKED'));

        $r = $this->login($u['username'], D::PASSWORD);
        self::assertSame([429, 'ACCOUNT_LOCKED'], [$r['estado'], $r['json']['detail']]);
        // Con contraseña errónea, una cuenta bloqueada responde igual que una inexistente.
        $r = $this->login($u['username'], 'Incorrecta.2026');
        self::assertSame([400, 'INCORRECT_USERNAME_OR_PASSWORD'], [$r['estado'], $r['json']['detail']]);

        // Sin Redis (como en esta suite) no hay bloqueo por IP: se bloquea la cuenta con el umbral simple.
        $minutos = D::contar(
            'SELECT ROUND((CAST("USU_Bloqueado_Hasta" AS DATE) - CAST(SYS_EXTRACT_UTC(SYSTIMESTAMP) AS DATE)) * 1440) '
            .'FROM "INV_USUARIO" WHERE "USU_Usuario" = :u',
            ['u' => $u['id']],
        );
        self::assertEqualsWithDelta(15, $minutos, 1);
    }

    /** Fase 8: quien conoce un usuario no puede dejar a su titular sin acceso fallando desde otra IP. */
    public function testElBloqueoEsPorCuentaEIpYUnAtaqueDistribuidoBloqueaLaCuenta(): void
    {
        $url = (string) ($_SERVER['REDIS_PRUEBAS_URL'] ?? $_ENV['REDIS_PRUEBAS_URL'] ?? '');
        if ('' === $url) {
            self::markTestSkipped('REDIS_PRUEBAS_URL no definida.');
        }
        // El bloqueo por IP vive en Redis (en producción, el mismo de la API).
        $ajustes = Ajustes::desdeEntorno([...$_SERVER, ...$_ENV, 'REDIS_URL' => $url]);
        $this->cliente->disableReboot();
        static::getContainer()->set(BloqueoCuenta::class, new BloqueoCuenta($ajustes, new CacheAuth($ajustes, new NullLogger())));
        $u = D::crearUsuario();
        $atacante = ['REMOTE_ADDR' => '203.0.113.7'];
        foreach (range(1, 5) as $_) {
            self::assertSame(400, $this->login($u['username'], 'Incorrecta.2026', $atacante)['estado']);
        }
        self::assertSame(429, $this->login($u['username'], D::PASSWORD, $atacante)['estado'], 'La IP que falló queda bloqueada.');
        self::assertSame(1, $this->auditorias($u['id'], 'ACCOUNT_LOCKED'));
        self::assertSame(200, $this->login($u['username'], D::PASSWORD, ['REMOTE_ADDR' => '198.51.100.9'])['estado'], 'El titular entra desde su IP.');

        // Muchos fallos desde varias IP (umbral × 4) bloquean la cuenta entera.
        foreach (['203.0.113.10', '203.0.113.11', '203.0.113.12', '203.0.113.13'] as $ip) {
            foreach (range(1, 5) as $_) {
                $this->login($u['username'], 'Incorrecta.2026', ['REMOTE_ADDR' => $ip]);
            }
        }
        $r = $this->login($u['username'], D::PASSWORD, ['REMOTE_ADDR' => '198.51.100.9']);
        self::assertSame([429, 'ACCOUNT_LOCKED'], [$r['estado'], $r['json']['detail']]);
    }

    public function testUsuarioInexistenteEInactivo(): void
    {
        $r = $this->login('no_existe_'.D::sufijo(), D::PASSWORD);
        self::assertSame([400, 'INCORRECT_USERNAME_OR_PASSWORD'], [$r['estado'], $r['json']['detail']]);

        $u = D::crearUsuario(['activo' => false]);
        $r = $this->login($u['username'], D::PASSWORD);
        self::assertSame([400, 'INACTIVE_USER'], [$r['estado'], $r['json']['detail']]);
    }

    public function testUnHashBcryptDelBackendActualSeRehaceAArgon2(): void
    {
        $u = D::crearUsuario(['hash' => '$2b$12$OXmKTLFLVlzy2qRT41HhhO9RmMSXfbNSyLxgdOty7Y1x77sKDuJzu']);

        self::assertSame(200, $this->login($u['username'], D::PASSWORD)['estado']);
        $hash = (string) D::conexion()->fetchOne('SELECT "USU_Password_Hash" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $u['id']]);
        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=3,p=2$', $hash);
        self::assertSame(200, $this->login($u['username'], D::PASSWORD)['estado']);
    }

    public function testSesionRestringidaSoloAdmiteAutoservicio(): void
    {
        $u = D::crearUsuario(['debe_cambiar' => true]);
        $r = $this->login($u['username'], D::PASSWORD);
        self::assertSame('PASSWORD_CHANGE_REQUIRED', $r['json']['restriction']);

        $this->cliente->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$r['json']['access_token']]);
        self::assertResponseIsSuccessful();

        $admin = D::crearUsuario(['rol' => 'ADMIN_TI']);
        self::assertSame('MFA_ENROLLMENT_REQUIRED', $this->login($admin['username'], D::PASSWORD)['json']['restriction']);
    }

    public function testConSegundoFactorEntregaUnDesafioYNoTokens(): void
    {
        $u = D::crearUsuario(['metodo_2fa' => 'TOTP']);
        $r = $this->login($u['username'], D::PASSWORD);

        self::assertSame(200, $r['estado']);
        self::assertTrue($r['json']['requires_2fa']);
        self::assertSame('TOTP', $r['json']['method']);
        $desafio = (array) JWT::decode($r['json']['challenge_token'], new Key($_SERVER['SECRET_KEY'], 'HS256'));
        self::assertSame('2fa', $desafio['type']);
        self::assertNull($this->cliente->getCookieJar()->get('rtk', '/api/v1/login'));
        self::assertSame(1, $this->auditorias($u['id'], '2FA_CHALLENGE'));
    }

    public function testTokenRevocadoSesionCerradaYRevocacionGlobal(): void
    {
        $u = D::crearUsuario();
        $sesion = $this->login($u['username'], D::PASSWORD)['json']['access_token'];
        $acceso = (array) JWT::decode($sesion, new Key($_SERVER['SECRET_KEY'], 'HS256'));
        $this->me($sesion);
        self::assertResponseIsSuccessful();

        D::conexion()->executeStatement(
            'UPDATE "SYS_SESION" SET "SES_Cerrada_En" = SYS_EXTRACT_UTC(SYSTIMESTAMP) WHERE "SES_Sesion" = :s',
            ['s' => Ident::aHex($acceso['sid'])],
        );
        $this->me($sesion);
        self::assertSame(['detail' => 'TOKEN_REVOKED'], $this->json(401));

        $otro = $this->bearer($u['username']);
        $this->cliente->request('GET', '/api/v1/me', server: $otro);
        self::assertResponseIsSuccessful();
        $jti = ((array) JWT::decode(substr($otro['HTTP_AUTHORIZATION'], 7), new Key($_SERVER['SECRET_KEY'], 'HS256')))['jti'];
        $this->revocar($jti, $u['id'], 'SYSTIMESTAMP');
        $this->cliente->request('GET', '/api/v1/me', server: $otro);
        self::assertSame(['detail' => 'TOKEN_REVOKED'], $this->json(401));

        $tercero = $this->bearer($u['username']);
        $this->revocar('USR-ALL-'.Ident::aTexto($u['id']), $u['id'], "SYS_EXTRACT_UTC(SYSTIMESTAMP) + INTERVAL '1' SECOND");
        $this->cliente->request('GET', '/api/v1/me', server: $tercero);
        self::assertSame(['detail' => 'TOKEN_REVOKED'], $this->json(401));
    }

    /**
     * Modo worker (FrankenPHP): el kernel y la conexión sobreviven entre
     * peticiones. El contexto VPD de un usuario no debe llegar a la siguiente.
     */
    public function testElContextoVpdNoPasaALaSiguientePeticionDelMismoProceso(): void
    {
        $u = D::crearUsuario(['sedes' => [D::crearSede('Sede '.D::sufijo())]]);
        $this->cliente->disableReboot();
        $conexion = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        $contexto = "SELECT SYS_CONTEXT('INVENTARIO_CTX', 'USUARIO') FROM DUAL";

        $this->cliente->request('GET', '/api/v1/me', server: $this->bearer($u['username']));
        self::assertResponseIsSuccessful();
        self::assertSame($u['id'], $conexion->fetchOne($contexto), 'Durante la petición el VPD filtra por el usuario.');

        $this->cliente->request('GET', '/health');
        self::assertResponseIsSuccessful();
        self::assertNull($conexion->fetchOne($contexto));
    }

    public function testErroresDeAutenticacionYCabeceras(): void
    {
        $this->cliente->request('GET', '/api/v1/me', server: ['HTTP_X_REQUEST_ID' => 'prueba-123']);
        self::assertSame(['detail' => 'Not authenticated'], $this->json(401));
        $h = $this->cliente->getResponse()->headers;
        self::assertSame('Bearer', $h->get('WWW-Authenticate'));
        self::assertSame('prueba-123', $h->get('X-Request-ID'));
        self::assertSame('nosniff', $h->get('X-Content-Type-Options'));
        self::assertSame('DENY', $h->get('X-Frame-Options'));

        $u = D::crearUsuario();
        $expirado = static::getContainer()->get(\App\Seguridad\Tokens::class)->crearAcceso($u['username'], 'TECNICO', segundos: -5);
        $this->me($expirado);
        self::assertSame(['detail' => 'TOKEN_EXPIRED'], $this->json(401));

        $this->cliente->request('POST', '/api/v1/login/access-token', ['username' => 'x', 'password' => 'y', 'grant_type' => 'otro']);
        self::assertSame('VALIDATION_ERROR', $this->json(422)['detail']);
    }

    /**
     * @param array<string, string> $server
     *
     * @return array{estado: int, json: array<string, mixed>}
     */
    private function login(string $username, string $password, array $server = []): array
    {
        $this->cliente->request('POST', '/api/v1/login/access-token', ['username' => $username, 'password' => $password], server: $server);
        $respuesta = $this->cliente->getResponse();

        return ['estado' => $respuesta->getStatusCode(), 'json' => json_decode((string) $respuesta->getContent(), true)];
    }

    private function me(string $token): void
    {
        $this->cliente->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
    }

    /**
     * Access token válido sin pasar por el login (como lo emitiría cualquiera de los dos backends).
     *
     * @return array<string, string>
     */
    private function bearer(string $username): array
    {
        $token = static::getContainer()->get(\App\Seguridad\Tokens::class)->crearAcceso($username, 'TECNICO');

        return ['HTTP_AUTHORIZATION' => "Bearer {$token}"];
    }

    /** @return array<string, mixed> */
    private function json(int $estado): array
    {
        self::assertResponseStatusCodeSame($estado);

        return json_decode((string) $this->cliente->getResponse()->getContent(), true);
    }

    private function auditorias(string $usuario, string $accion): int
    {
        return D::contar(
            'SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" WHERE "USU_Usuario" = :u AND "AUD_Accion" = :a',
            ['u' => $usuario, 'a' => $accion],
        );
    }

    private function revocar(string $jti, string $usuario, string $fechaSql): void
    {
        D::conexion()->executeStatement(
            'INSERT INTO "SYS_TOKEN_REVOCADO" ("TRV_Jti", "TRV_Tipo", "TRV_Fecha_Revocacion", "TRV_Expira", "USU_Usuario") '
            ."VALUES (:j, 'access', {$fechaSql}, SYS_EXTRACT_UTC(SYSTIMESTAMP) + INTERVAL '1' DAY, :u)",
            ['j' => $jti, 'u' => $usuario],
        );
    }
}
