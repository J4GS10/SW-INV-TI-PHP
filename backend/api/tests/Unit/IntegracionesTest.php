<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Correo\CorreoEnCola;
use App\Correo\Notificador;
use App\Directorio\ConexionLdap;
use App\Directorio\NombrePersona;
use App\Directorio\UsuarioDirectorio;
use App\Dominio\Reloj;
use App\Integracion\ConfigAd;
use App\Integracion\FormularioIntegracion;
use App\Notificaciones\EventoNotificacion;
use App\Notificaciones\ReglaNotificacion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reglas puras de la fase 5 (caja blanca): directorio, formulario de integración, reglas y cola. */
final class IntegracionesTest extends TestCase
{
    public function testObjectGuidSeConvierteComoUuidBytesLe(): void
    {
        // uuid.UUID(bytes_le=bytes.fromhex('2d89ec95f99e34429d022b06916e8f0c')) en el backend anterior.
        self::assertSame('95ec892d-9ef9-4234-9d02-2b06916e8f0c', UsuarioDirectorio::guidATexto((string) hex2bin('2d89ec95f99e34429d022b06916e8f0c')));
        self::assertSame('abc', UsuarioDirectorio::guidATexto('{ABC}'));
    }

    public function testUsuarioDelDirectorioDesdeAtributos(): void
    {
        $u = UsuarioDirectorio::desdeAtributos([
            'objectguid' => [(string) hex2bin('2d89ec95f99e34429d022b06916e8f0c')], 'distinguishedname' => ['CN=A,DC=x'],
            'mail' => [' Ana@X.Local '], 'useraccountcontrol' => ['514'], 'mobile' => ['555'], 'givenname' => ['Ana'],
        ]);
        self::assertNotNull($u);
        self::assertSame(['ana@x.local', false, '555', 'Ana', null], [$u->correo, $u->habilitado, $u->telefono, $u->nombres, $u->jefeDn]);
        self::assertTrue(UsuarioDirectorio::desdeAtributos(['objectguid' => ['g'], 'distinguishedname' => ['d'], 'useraccountcontrol' => ['x']])?->habilitado);
        self::assertNull(UsuarioDirectorio::desdeAtributos(['distinguishedname' => ['CN=A']]), 'Sin objectGUID no hay persona.');
    }

    /** @return iterable<string, array{0: ?string, 1: array{0: ?string, 1: ?string}, 2: array{0: ?string, 1: ?string}}> */
    public static function nombres(): iterable
    {
        yield 'compuestos' => ['Juan  Carlos Andrés', ['Juan', 'Carlos Andrés'], [null, null]];
        yield 'partículas' => [null, [null, null], ['De la Cruz', 'Pérez']];
        yield 'simple' => ['Ana', ['Ana', null], ['García', 'López']];
        yield 'solo partículas' => [null, [null, null], ['de la', null]];
    }

    /**
     * @param array{0: ?string, 1: ?string} $nombres
     * @param array{0: ?string, 1: ?string} $apellidos
     */
    #[DataProvider('nombres')]
    public function testNombresYApellidos(?string $nombre, array $nombres, array $apellidos): void
    {
        self::assertSame($nombres, NombrePersona::nombres($nombre));
        $texto = match ($apellidos) {
            ['De la Cruz', 'Pérez'] => 'De la Cruz Pérez',
            ['García', 'López'] => 'García López',
            ['de la', null] => 'de la',
            default => null,
        };
        self::assertSame($apellidos, NombrePersona::apellidos($texto));
        self::assertSame(50, mb_strlen((string) NombrePersona::nombres(str_repeat('á', 80))[0]), 'Corta a 50 caracteres (no bytes).');
    }

    public function testReglasDelFormularioDeIntegracion(): void
    {
        $base = ['modo' => 'CORREO_AD', 'proveedor' => 'SMTP', 'cuenta_email' => 'a@b.local', 'smtp_host' => 'smtp', 'graph_tenant_id' => null,
            'graph_client_id' => null, 'ad_servidor' => 'ldaps://dc1, LDAP://dc2', 'ad_base_dn' => 'DC=x', 'ad_usar_cuenta_servicio' => true, 'ad_bind_usuario' => null];
        self::assertNull(FormularioIntegracion::reglaIncumplida($base));
        self::assertNull(FormularioIntegracion::reglaIncumplida(['modo' => 'DESACTIVADO']));
        self::assertSame('AD_SERVIDOR_DEBE_SER_LDAP_O_LDAPS', FormularioIntegracion::reglaIncumplida([...$base, 'ad_servidor' => 'ldaps://a,dc2']));
        self::assertSame('CUENTA_EMAIL_REQUERIDA', FormularioIntegracion::reglaIncumplida([...$base, 'cuenta_email' => null]));
        self::assertNull(FormularioIntegracion::reglaIncumplida([...$base, 'modo' => 'CORREO', 'ad_servidor' => null]), 'Sin AD no se exige servidor.');
    }

    public function testCanalCifradoYRaizDelDominio(): void
    {
        self::assertTrue((new ConfigAd(true, ['ldaps://a', 'LDAPS://b']))->canalCifrado());
        self::assertFalse((new ConfigAd(true, ['ldaps://a', 'ldap://b']))->canalCifrado());
        self::assertTrue((new ConfigAd(true, ['ldap://a'], startTls: true))->canalCifrado());
        self::assertFalse((new ConfigAd(true))->canalCifrado());
        $c = new ConfigAd(true, ['ldaps://a'], 'OU=Usuarios, DC=empresa,DC=local', usuarioBind: 'svc');
        self::assertSame(' DC=empresa,DC=local', $c->raizDominio());
        self::assertSame('OU=Usuarios, DC=empresa,DC=local', $c->baseGrupos());
        self::assertTrue($c->lista());
        self::assertNotSame($c->huella(), (new ConfigAd(true, ['ldaps://a'], 'OU=Usuarios, DC=empresa,DC=local', usuarioBind: 'svc', passwordBind: 'x'))->huella());
    }

    public function testEscapeDeFiltrosLdap(): void
    {
        self::assertSame('\2a\29\28cn=\2a\5c\00', ConexionLdap::escapar("*)(cn=*\\\0"));
    }

    public function testLosEventosConfigurablesSonLosDelNotificador(): void
    {
        self::assertSame(Notificador::CONFIGURABLES, array_map(static fn (EventoNotificacion $e): string => $e->value, EventoNotificacion::cases()));
        self::assertSame('a,b', ReglaNotificacion::unir([' a', 'b', 'a', '']));
        self::assertNull(ReglaNotificacion::unir([' ']));
    }

    public function testTransicionesDeUnCorreoEnCola(): void
    {
        $c = new CorreoEnCola('id', 'p', 'a', 'h', ['x@y'], null, true, true, null, CorreoEnCola::PENDIENTE, 3, Reloj::ahora());
        self::assertFalse($c->fallar('e', 7, [60, 300, 900]));
        self::assertGreaterThan(Reloj::ahora()->modify('+890 seconds'), $c->proximoIntento, 'Tercer intento: tercera espera.');
        $c->intentos = 9;
        $c->fallar('e', 7, [60]);
        self::assertSame(CorreoEnCola::FALLIDO, $c->estado);
        $c->intentos = 0;
        $c->retener('AUTH_FAILED: x', 15);
        self::assertSame(0, $c->intentos, 'Nunca negativo.');
        $c->fijarDestinatarios([]);
        self::assertFalse($c->resolver);
    }
}
