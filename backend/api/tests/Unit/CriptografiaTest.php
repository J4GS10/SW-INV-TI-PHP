<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\Ajustes;
use App\Http\ErrorApi;
use App\Seguridad\CifradoCampos;
use App\Seguridad\CodigosSeguridad;
use App\Seguridad\Fernet;
use App\Seguridad\PoliticaContrasenas;
use App\Seguridad\TokenFernetInvalido;
use App\Seguridad\Totp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Compatibilidad criptográfica con el backend anterior: los valores fijos los
 * generó ese backend (cryptography, pyotp, hmac) con estas mismas claves.
 */
final class CriptografiaTest extends TestCase
{
    private const string FERNET_PRIMARIA = 'ZmVybmV0X2NsYXZlX2RlX3BydWViYXNfMzJfYnl0ZXM=';
    private const string FERNET_VIEJA = 'b2xkX2tleV9wYXJhX3JvdGFjaW9uXzMyX2J5dGVzX18=';

    public function testDescifraValoresDelBackendActualConRotacionDeClaves(): void
    {
        $fernet = new Fernet([self::FERNET_PRIMARIA, self::FERNET_VIEJA]);
        self::assertSame('JBSWY3DPEHPK3PXP', $fernet->descifrar(
            'gAAAAABq4TkqC3Z5o6ILuCi0nL2S8UtW_MJg38FW6ZFYuKjAyXc5YO8_qfvgt7_2XHGE_W8iRB3MRP9aRNFasfL49uv1xOOaViiic2DS_9U3pCjhITFXZ28=',
        ));
        self::assertSame('clave-secreta-ñ', $fernet->descifrar(
            'gAAAAABq4TkqjSe221NQYjMiCv706lzeglj40G-dGAgR2f4LKJBeh9EhAwNbKFX8rggTFU3D9FOfHlhP25p5auPbywZv_Pj3ihYeT8iWfkhpAno_c9DjBg8=',
        ));
    }

    public function testCifraConLaPrimeraClaveYRechazaManipulaciones(): void
    {
        $fernet = new Fernet([self::FERNET_PRIMARIA, self::FERNET_VIEJA]);
        $token = $fernet->cifrar('secreto');
        self::assertSame('secreto', (new Fernet([self::FERNET_PRIMARIA]))->descifrar($token));
        self::assertNotSame($token, $fernet->cifrar('secreto'), 'IV aleatorio por cifrado.');

        $bytes = base64_decode(strtr($token, '-_', '+/'));
        $bytes[30] = \chr(\ord($bytes[30]) ^ 1);
        foreach ([strtr(base64_encode($bytes), '+/', '-_'), 'no-es-fernet', ''] as $malo) {
            try {
                $fernet->descifrar($malo);
                self::fail('Se esperaba TokenFernetInvalido');
            } catch (TokenFernetInvalido) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(TokenFernetInvalido::class);
        (new Fernet([self::FERNET_VIEJA]))->descifrar($token);
    }

    public function testEnProduccionUnValorManipuladoNoSeSirve(): void
    {
        $dev = new CifradoCampos($this->ajustes(), new NullLogger());
        self::assertSame('legado-en-claro', $dev->descifrar('legado-en-claro'));

        $prod = new CifradoCampos($this->ajustes([
            'ENVIRONMENT' => 'production', 'HMAC_KEYS' => str_repeat('h', 32),
        ]), new NullLogger());
        $this->expectException(TokenFernetInvalido::class);
        $prod->descifrar('legado-en-claro');
    }

    public function testTotpRfc6238YVectoresDelBackendActual(): void
    {
        $s = 'JBSWY3DPEHPK3PXP';
        self::assertSame('282760', Totp::codigoEn($s, 0));
        self::assertSame('996554', Totp::codigoEn($s, 59));
        self::assertSame('071271', Totp::codigoEn($s, 1111111109));
        self::assertSame('388014', Totp::codigoEn($s, 1793142739));
        // RFC 6238, apéndice B (secreto ASCII "12345678901234567890", 8 dígitos → últimos 6).
        self::assertSame('287082', Totp::codigoEn('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59));
        self::assertSame(
            'otpauth://totp/Inventario%20Lombardi:ana.p%C3%A9rez?secret=JBSWY3DPEHPK3PXP&issuer=Inventario%20Lombardi',
            Totp::uriAprovisionamiento($s, 'ana.pérez', 'Inventario Lombardi'),
        );
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', Totp::generarSecreto());
    }

    public function testTotpToleraUnaVentanaYDevuelveElPaso(): void
    {
        $s = 'JBSWY3DPEHPK3PXP';
        $t = 1793142739;
        $paso = intdiv($t, 30);
        self::assertSame($paso, Totp::pasoCoincidente($s, Totp::codigo($s, $paso), $t));
        self::assertSame($paso - 1, Totp::pasoCoincidente($s, Totp::codigo($s, $paso - 1), $t));
        self::assertSame($paso + 1, Totp::pasoCoincidente($s, ' '.Totp::codigo($s, $paso + 1).' ', $t));
        self::assertNull(Totp::pasoCoincidente($s, Totp::codigo($s, $paso - 2), $t));
        self::assertNull(Totp::pasoCoincidente($s, 'abcdef', $t));
        self::assertNull(Totp::pasoCoincidente('secreto-no-base32!', '123456', $t));
    }

    public function testHashesDeCodigosIgualesAlBackendActual(): void
    {
        $c = new CodigosSeguridad($this->ajustes());
        self::assertSame('83b44522bf034e87b08433f0175175e5604f621eb43e593fd562b218ad4375a2', $c->hash(' abcd-efgh '));
        self::assertSame([
            '0651d8e2404392f24da1e495ad2b356bebd338b56167d1d6dd5a6b80be6720d4',
            '3caa6f404dd7c93fde4a866dc902951bd00c935c71cd3c24ef9bdb58ca86df33',
            '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92',
        ], $c->candidatos('123456'));
        self::assertSame('dcda80ae385867e20a670db5d80c66469186ff5639aff24312f6f0bd604861fa', CodigosSeguridad::hashRestablecimiento('token-plano'));
        self::assertSame(
            '6f122f01246d47431f3b2e7ccca3c4cc3591e187868e8f6e50ad77c0879ca53d',
            (new CifradoCampos($this->ajustes(), new NullLogger()))->huella('valor'),
        );
    }

    public function testFormatoDeCodigosAleatorios(): void
    {
        $codigos = CodigosSeguridad::codigosRecuperacion(8);
        self::assertCount(8, array_unique($codigos));
        foreach ($codigos as $codigo) {
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $codigo);
        }
        self::assertMatchesRegularExpression('/^\d{6}$/', CodigosSeguridad::otpNumerico());
        [$token, $hash] = CodigosSeguridad::tokenRestablecimiento();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertSame(hash('sha256', $token), $hash);
    }

    public function testPoliticaDeContrasenasIgualAlBackendActual(): void
    {
        $p = new PoliticaContrasenas($this->ajustes());
        $casos = [
            'Verano2026!' => ['too_common'],
            'P@ssw0rd2026' => ['too_common', 'contains_blocked_word'],
            'Abcd1234xyz' => ['sequential_chars'],
            'Lombardi2026A' => ['contains_blocked_word'],
            'Ana.Perez99X' => ['contains_personal_data'],
            'Zx9!kLm2Qw7r' => [],
            'aaaaB1cdefgh' => ['repeated_chars', 'sequential_chars'],
            'corta1A' => ['min_length:10'],
        ];
        foreach ($casos as $password => $esperado) {
            self::assertSame($esperado, $p->errores($password, 'anap', ['perez', 'juan']), $password);
        }
        self::assertSame(['contains_username'], $p->errores('Xq7!anaPzWk', 'anap'));

        try {
            $p->validar('corta1A');
            self::fail('Se esperaba 400');
        } catch (ErrorApi $e) {
            self::assertSame([400, 'PASSWORD_POLICY_VIOLATION:min_length:10'], [$e->estado, $e->detalle]);
        }
    }

    public function testLaContrasenaTemporalCumpleLaPolitica(): void
    {
        $p = new PoliticaContrasenas($this->ajustes());
        foreach (range(1, 20) as $_) {
            $temporal = $p->generarTemporal('anap');
            self::assertSame([], $p->errores($temporal, 'anap'));
            self::assertSame(16, \strlen($temporal));
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-z2-9#$%&*+\-=?@]+$/', $temporal);
        }
    }

    public function testValidacionesDeArranqueEnProduccion(): void
    {
        $base = ['ENVIRONMENT' => 'production', 'FIELD_ENCRYPTION_KEY' => self::FERNET_PRIMARIA, 'HMAC_KEYS' => str_repeat('h', 32)];
        self::assertTrue($this->ajustes($base)->esProduccion());

        foreach ([
            'FIELD_ENCRYPTION_KEY es obligatorio' => ['FIELD_ENCRYPTION_KEY' => ''],
            'HMAC_KEYS es obligatorio' => ['HMAC_KEYS' => ''],
            'no puede ser SECRET_KEY' => ['HMAC_KEYS' => str_repeat('k', 40)],
            'al menos 32' => ['HMAC_KEYS' => str_repeat('h', 32).',corta'],
            'REDIS_URL sin AUTH' => ['REDIS_URL' => 'redis://redis:6379/0'],
            'no puede superar 120' => ['SESSION_IDLE_TIMEOUT_MINUTES' => '180'],
            'mayor que ACCESS_TOKEN' => ['SESSION_IDLE_TIMEOUT_MINUTES' => '15'],
            'entre 1 y 30' => ['REFRESH_TOKEN_EXPIRE_DAYS' => '31'],
            'valor por defecto conocido' => ['SECRET_KEY' => 'super_secret_key_change_me_in_prod'],
            'número entero' => ['ACCOUNT_LOCKOUT_THRESHOLD' => 'cinco'],
        ] as $mensaje => $cambio) {
            try {
                $this->ajustes([...$base, ...$cambio]);
                self::fail("Se esperaba el error: {$mensaje}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($mensaje, $e->getMessage());
            }
        }
        // Fuera de producción, una inactividad incoherente se corrige al doble del access token.
        self::assertSame(30, $this->ajustes(['SESSION_IDLE_TIMEOUT_MINUTES' => '10'])->sesionInactividadMinutos);
    }

    /** @param array<string, string> $extra */
    private function ajustes(array $extra = []): Ajustes
    {
        return Ajustes::desdeEntorno([
            'SECRET_KEY' => str_repeat('k', 40),
            'FIELD_ENCRYPTION_KEY' => self::FERNET_PRIMARIA.','.self::FERNET_VIEJA,
            'HMAC_KEYS' => 'hmac_primaria_de_pruebas,hmac_vieja',
            ...$extra,
        ]);
    }
}
