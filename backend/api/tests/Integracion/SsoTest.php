<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Autenticacion\Sso;
use App\Config\Ajustes;
use App\Dominio\Ident;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\Totp;
use App\Tests\Soporte\DatosPrueba as D;
use Doctrine\DBAL\Connection;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * SSO OIDC contra un proveedor simulado (discovery, token y JWKS con una
 * clave RSA real): flujo completo y cada defensa del id_token.
 */
final class SsoTest extends WebTestCase
{
    private const string FRONTEND = 'https://inventario.pruebas.local';

    private KernelBrowser $cliente;
    private \OpenSSLAsymmetricKey $privada;
    /** @var array<string, mixed> claims del próximo id_token (se combinan con los de base) */
    private array $claims = [];
    private ?string $algoritmo = null;
    private string $proveedor = 'google';

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->privada = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        $c = static::getContainer();
        $c->set(Sso::class, new Sso(new MockHttpClient($this->proveedorSimulado(...)), $c->get(UsuarioRepositorio::class),
            $c->get(GobiernoRepositorio::class), $c->get(Connection::class), $c->get(Ajustes::class)));
    }

    public function testListaLosProveedoresConfigurados(): void
    {
        $this->cliente->request('GET', '/api/v1/login/sso/providers');
        self::assertSame(['enabled' => true, 'providers' => [
            ['provider' => 'microsoft', 'label' => 'Microsoft 365'], ['provider' => 'google', 'label' => 'Google Workspace'],
        ]], json_decode((string) $this->cliente->getResponse()->getContent(), true));
        $this->cliente->request('GET', '/api/v1/login/sso/github/authorize');
        self::assertResponseStatusCodeSame(404);
    }

    public function testFlujoCompletoConGoogle(): void
    {
        $u = D::crearUsuario(['sso' => 'google']);
        $destino = $this->volverDelProveedor($u['correo'], self::FRONTEND.'/panel');

        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::FRONTEND.'/panel#sso=ok', $destino);
        $refresh = $this->cliente->getCookieJar()->get('rtk', '/api/v1/login');
        self::assertNotNull($refresh);
        $payload = (array) JWT::decode($refresh->getValue(), new Key($_SERVER['SECRET_KEY'], 'HS256'));
        self::assertSame('sso', $payload['amr']);
        self::assertSame('sso', D::conexion()->fetchOne('SELECT "SES_Metodo" FROM "SYS_SESION" WHERE "SES_Sesion" = :s',
            ['s' => Ident::aHex($payload['sid'])]));
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" WHERE "USU_Usuario" = :u AND "AUD_Accion" = \'LOGIN_SUCCESS\'', ['u' => $u['id']]));
        self::assertNull($this->cliente->getCookieJar()->get(Sso::COOKIE_VINCULO, '/api/v1/login/sso'), 'El vínculo es de un solo uso.');
    }

    public function testUnCallbackSinElVinculoDelNavegadorSeRechaza(): void
    {
        $u = D::crearUsuario(['sso' => 'google']);
        $url = $this->autorizar();
        // Otro navegador (víctima de un CSRF de login): no tiene la cookie del vínculo.
        $this->cliente->getCookieJar()->clear();
        $this->claims = ['email' => $u['correo'], 'nonce' => $url['nonce']];
        $this->cliente->request('GET', '/api/v1/login/sso/google/callback', ['code' => 'c', 'state' => $url['state']]);
        self::assertSame(['detail' => 'SSO_STATE_INVALID'], $this->json(401));
    }

    public function testDefensasDelIdToken(): void
    {
        $u = D::crearUsuario(['sso' => 'google']);
        // [código esperado, claims alterados, algoritmo de firma (null = RS256 legítimo)]
        $casos = [
            ['SSO_NONCE_INVALID', ['nonce' => 'otro'], null],
            ['SSO_ID_TOKEN_INVALID', ['aud' => 'otra-app'], null],
            ['SSO_ID_TOKEN_INVALID', ['iss' => 'https://malicioso.example'], null],
            ['SSO_ID_TOKEN_INVALID', ['exp' => time() - 10], null],
            ['SSO_EMAIL_NOT_VERIFIED', ['email_verified' => false], null],
            ['SSO_ID_TOKEN_INVALID', [], 'HS256'],   // confusión de algoritmo
            ['SSO_ID_TOKEN_INVALID', [], 'BASURA'],  // token malformado: 401, nunca 500
        ];
        foreach ($casos as [$esperado, $extra, $alg]) {
            $this->algoritmo = $alg;
            $this->volverDelProveedor($u['correo'], null, $extra);
            self::assertSame($esperado, $this->json(null)['detail'] ?? null, $esperado.' '.json_encode($extra).' '.$alg);
        }
    }

    public function testCuentasNoAutorizadas(): void
    {
        $sinSso = D::crearUsuario();
        $otroProveedor = D::crearUsuario(['sso' => 'microsoft']);
        foreach ([
            'SSO_USER_NOT_AUTHORIZED' => 'nadie_'.D::sufijo().'@pruebas.local',
            'SSO_NOT_ENABLED_FOR_USER' => $sinSso['correo'],
            'SSO_PROVIDER_NOT_ALLOWED_FOR_USER' => $otroProveedor['correo'],
            'SSO_EMAIL_DOMAIN_NOT_ALLOWED' => 'alguien@otro-dominio.com',
        ] as $esperado => $correo) {
            $this->volverDelProveedor($correo);
            self::assertSame(['detail' => $esperado], $this->json(403));
        }
    }

    public function testMicrosoftConTenantGenericoYProteccionNoauth(): void
    {
        $this->proveedor = 'microsoft';
        $u = D::crearUsuario(['sso' => 'cualquiera']);
        $base = ['tid' => 'tenant-permitido', 'iss' => 'https://login.microsoftonline.com/tenant-permitido/v2.0'];

        // `email` sin dominio certificado (xms_edov) se ignora: vale el UPN.
        $this->volverDelProveedor('ignorado@otro-dominio.com', null, [...$base, 'preferred_username' => strtoupper($u['correo'])]);
        self::assertResponseStatusCodeSame(303);

        $this->volverDelProveedor($u['correo'], null, [...$base, 'tid' => 'tenant-ajeno', 'iss' => 'https://login.microsoftonline.com/tenant-ajeno/v2.0', 'preferred_username' => $u['correo']]);
        self::assertSame(['detail' => 'SSO_TENANT_NOT_ALLOWED'], $this->json(403));
        $this->volverDelProveedor($u['correo'], null, [...$base, 'iss' => 'https://login.microsoftonline.com/otro/v2.0', 'preferred_username' => $u['correo']]);
        self::assertSame(['detail' => 'SSO_ID_TOKEN_INVALID'], $this->json(401));
    }

    public function testConSegundoFactorLocalElDesafioViajaEnElFragmento(): void
    {
        $secreto = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $u = D::crearUsuario(['sso' => 'google', 'metodo_2fa' => 'TOTP', 'secreto_totp' => $secreto]);
        $destino = $this->volverDelProveedor($u['correo']);

        self::assertStringStartsWith(self::FRONTEND.'/sso/callback#sso=2fa&method=TOTP&challenge=', $destino);
        self::assertNull($this->cliente->getCookieJar()->get('rtk', '/api/v1/login'), 'Sin sesión hasta el segundo factor.');
        parse_str((string) parse_url($destino, \PHP_URL_FRAGMENT), $fragmento);

        $this->cliente->request('POST', '/api/v1/login/2fa/verify', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['challenge_token' => $fragmento['challenge'], 'code' => Totp::codigoEn($secreto, time())]));
        self::assertResponseIsSuccessful();
        $acceso = json_decode((string) $this->cliente->getResponse()->getContent(), true)['access_token'];
        $sid = ((array) JWT::decode($acceso, new Key($_SERVER['SECRET_KEY'], 'HS256')))['sid'];
        self::assertSame('sso', D::conexion()->fetchOne('SELECT "SES_Metodo" FROM "SYS_SESION" WHERE "SES_Sesion" = :s', ['s' => Ident::aHex($sid)]));
    }

    public function testElDestinoSoloPuedeSerDelFrontend(): void
    {
        $sso = static::getContainer()->get(Sso::class);
        foreach (['https://malicioso.example/x', 'https://inventario.pruebas.local.malicioso.example/', '//malicioso.example',
            'javascript:alert(1)', 'https://user@inventario.pruebas.local/', 'http://inventario.pruebas.local/', "https://inventario.pruebas.local/\\\\malo"] as $malo) {
            self::assertSame(self::FRONTEND.'/sso/callback', $sso->volverASeguro($malo), $malo);
        }
        self::assertSame(self::FRONTEND.'/activos?x=1', $sso->volverASeguro(self::FRONTEND.'/activos?x=1'));
    }

    // ---- Apoyo ---------------------------------------------------------

    /** @return array{state: string, nonce: string} */
    private function autorizar(?string $volverA = null): array
    {
        $this->cliente->request('GET', "/api/v1/login/sso/{$this->proveedor}/authorize", null === $volverA ? [] : ['return_to' => $volverA]);
        self::assertResponseIsSuccessful();
        $url = json_decode((string) $this->cliente->getResponse()->getContent(), true)['authorization_url'];
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $q);
        self::assertSame('openid email profile', $q['scope']);

        return ['state' => $q['state'], 'nonce' => $q['nonce']];
    }

    /** @param array<string, mixed> $extra */
    private function volverDelProveedor(string $correo, ?string $volverA = null, array $extra = []): ?string
    {
        $url = $this->autorizar($volverA);
        $this->claims = ['email' => $correo, 'nonce' => $url['nonce'], ...$extra];
        $this->cliente->request('GET', "/api/v1/login/sso/{$this->proveedor}/callback", ['code' => 'codigo', 'state' => $url['state']]);
        $this->algoritmo = null;

        return $this->cliente->getResponse()->headers->get('Location');
    }

    /** @return array<string, mixed> */
    private function json(?int $estado): array
    {
        if (null !== $estado) {
            self::assertResponseStatusCodeSame($estado);
        }

        return (array) json_decode((string) $this->cliente->getResponse()->getContent(), true);
    }

    /** @param array<string, mixed> $opciones */
    private function proveedorSimulado(string $metodo, string $url, array $opciones): MockResponse
    {
        $google = 'google' === $this->proveedor;
        $emisor = $google ? 'https://accounts.google.com' : 'https://login.microsoftonline.com/{tenantid}/v2.0';
        if (str_ends_with($url, '/.well-known/openid-configuration')) {
            return new MockResponse(json_encode(['issuer' => $emisor, 'token_endpoint' => 'https://idp.example/token', 'jwks_uri' => 'https://idp.example/jwks']));
        }
        if ('https://idp.example/jwks' === $url) {
            $detalle = openssl_pkey_get_details($this->privada);
            $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

            return new MockResponse(json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256',
                'n' => $b64($detalle['rsa']['n']), 'e' => $b64($detalle['rsa']['e'])]]]));
        }
        if ('https://idp.example/token' === $url) {
            $ahora = time();
            $claims = ['iss' => $google ? 'https://accounts.google.com' : null, 'aud' => $google ? 'cliente-google' : 'cliente-ms',
                'sub' => 'sujeto', 'iat' => $ahora, 'exp' => $ahora + 300, ...$this->claims];
            $token = match ($this->algoritmo) {
                'HS256' => JWT::encode($claims, 'clave-publica-usada-como-secreto', 'HS256', 'k1'),
                'BASURA' => '!!!.@@@.###',
                default => JWT::encode($claims, $this->privada, 'RS256', 'k1'),
            };

            return new MockResponse(json_encode(['id_token' => $token, 'access_token' => 'x']));
        }

        return new MockResponse('', ['http_code' => 404]);
    }
}
