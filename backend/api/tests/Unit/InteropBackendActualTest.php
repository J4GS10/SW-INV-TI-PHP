<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dominio\ErrorDominio;
use App\Config\Ajustes;
use App\Seguridad\Contrasenas;
use App\Seguridad\PoliticaSesion;
use App\Seguridad\Tokens;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

/**
 * Compatibilidad con el backend anterior: los valores fijos de esta prueba los
 * generó ese backend (passlib y PyJWT) con la SECRET_KEY de pruebas. Si alguno
 * falla, los usuarios existentes no podrían entrar o los tokens no valdrían
 * entre ambos backends durante la migración.
 */
final class InteropBackendActualTest extends TestCase
{
    private const string CLAVE = 'clave_de_pruebas_interop_de_al_menos_32_caracteres';
    private const string PASSWORD = 'Prueba.Segura#2026';
    private const string HASH_ARGON2 = '$argon2id$v=19$m=65536,t=3,p=2$Sel9DwEAAKCU8p4T4tx7jw$YFIyhPXgeq1g0ZRqoGZIROja6aCx1eAriQhzo0sqg/Y';
    private const string HASH_BCRYPT = '$2b$12$OXmKTLFLVlzy2qRT41HhhO9RmMSXfbNSyLxgdOty7Y1x77sKDuJzu';
    private const string JWT_ACTUAL = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzaWQiOiIwZjBlMGQwYy0wYjBhLTQ5MDgtODcwNi0wNTA0MDMwMjAxMDAiLCJwd2RfY2hhbmdlIjp0cnVlLCJpYXQiOjE3OTMxNDI3MzksImlhdF9tcyI6MTc5MzE0MjczOTc3OCwiZXhwIjo0OTQ2NzQyNzM5LCJzdWIiOiJzYSIsInJvbGUiOiJTVVBFUl9BRE1JTiIsInR5cGUiOiJhY2Nlc3MiLCJqdGkiOiJlZjdkNzlkM2NkNjI5MTUxYzlkN2VkMjFmOWQ2MjczZiJ9.t8f8xotwGDRdOfaK4vlN7fQATcw17ncKsyjTgn5tOts';

    public function testVerificaHashesArgon2YBcryptDelBackendActual(): void
    {
        self::assertTrue(Contrasenas::verificar(self::PASSWORD, self::HASH_ARGON2));
        self::assertTrue(Contrasenas::verificar(self::PASSWORD, self::HASH_BCRYPT));
        self::assertFalse(Contrasenas::verificar('otra', self::HASH_ARGON2));
        self::assertFalse(Contrasenas::verificar(self::PASSWORD, null));
    }

    public function testSoloElHashBcryptSeRehace(): void
    {
        self::assertFalse(Contrasenas::necesitaRehash(self::HASH_ARGON2));
        self::assertTrue(Contrasenas::necesitaRehash(self::HASH_BCRYPT));
        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=3,p=2$', Contrasenas::hashear(self::PASSWORD));
    }

    public function testAceptaUnTokenEmitidoPorElBackendActual(): void
    {
        $payload = $this->tokens()->decodificar(self::JWT_ACTUAL);

        self::assertSame('sa', $payload['sub']);
        self::assertSame('SUPER_ADMIN', $payload['role']);
        self::assertSame('0f0e0d0c-0b0a-4908-8706-050403020100', $payload['sid']);
        self::assertSame(PoliticaSesion::CAMBIO_PASSWORD, PoliticaSesion::restriccion($payload));
        // iat_ms = 1793142739778
        self::assertSame('2026-10-27 23:12:19.778000', Tokens::emitidoEn($payload)?->format('Y-m-d H:i:s.u'));
    }

    public function testEmiteTokensConLosClaimsDelBackendActual(): void
    {
        $token = $this->tokens()->crearAcceso('ana', 'TECNICO', ['sid' => 'abc', 'mfa_enroll' => true]);
        $payload = (array) JWT::decode($token, new Key(self::CLAVE, 'HS256'));

        self::assertSame(['sid', 'mfa_enroll', 'iat', 'iat_ms', 'exp', 'sub', 'role', 'type', 'jti'], array_keys($payload));
        self::assertSame('access', $payload['type']);
        self::assertSame(15 * 60, $payload['exp'] - $payload['iat']);
        self::assertSame(intdiv($payload['iat_ms'], 1000), $payload['iat']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $payload['jti']);
    }

    public function testElRefreshRespetaInactividadYTopeAbsoluto(): void
    {
        $tokens = $this->tokens();
        $refresh = (array) JWT::decode($tokens->crearRefresh('ana', 'TECNICO'), new Key(self::CLAVE, 'HS256'));
        self::assertSame(120 * 60, $refresh['exp'] - $refresh['iat']);
        self::assertSame('refresh', $refresh['type']);

        // Sesión iniciada hace 719 h 50 min: quedan 10 minutos del tope de 720 h.
        $viejo = time() - (720 * 3600 - 600);
        $refresh = (array) JWT::decode($tokens->crearRefresh('ana', 'TECNICO', $viejo), new Key(self::CLAVE, 'HS256'));
        self::assertEqualsWithDelta(600, $refresh['exp'] - $refresh['iat'], 1);
        self::assertSame($viejo, $refresh['auth_time']);
    }

    public function testHuellaDePasswordIgualAlBackendActual(): void
    {
        self::assertSame('358c37d0fa4f74a1', $this->tokens()->huellaPassword('abc'));
    }

    public function testCodigosDeErrorDelToken(): void
    {
        $tokens = $this->tokens();
        foreach ([
            'COULD_NOT_VALIDATE_CREDENTIALS' => 'no.es.jwt',
            'TOKEN_EXPIRED' => $tokens->crearAcceso('ana', 'TECNICO', segundos: -10),
            'INVALID_TOKEN_TYPE' => $tokens->crearRefresh('ana', 'TECNICO'),
        ] as $esperado => $token) {
            try {
                $tokens->decodificar($token);
                self::fail("Se esperaba {$esperado}");
            } catch (ErrorDominio $e) {
                self::assertSame($esperado, $e->detalle);
                self::assertSame(401, \App\EventListener\ErroresListener::estadoHttp($e->motivo));
            }
        }
    }

    private function tokens(): Tokens
    {
        return new Tokens(Ajustes::desdeEntorno(['SECRET_KEY' => self::CLAVE]));
    }
}
