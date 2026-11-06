<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Dominio\Ident;
use App\Seguridad\Tokens;
use App\Tests\Soporte\DatosPrueba as D;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** /org: personas con alcance por sede y gestión de identidades (IAM). */
final class OrganizacionTest extends WebTestCase
{
    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
    }

    public function testPersonasConAlcanceUnicidadGlobalYPermisos(): void
    {
        $a = D::crearSede('A '.D::sufijo());
        $b = D::crearSede('B '.D::sufijo());
        $admin = $this->token(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$a]]));
        $ajena = D::crearPersona($b);
        $dep = (int) D::conexion()->fetchOne('SELECT MIN("DEP_Departamento") FROM "INV_DEPARTAMENTO"');
        $car = (int) D::conexion()->fetchOne('SELECT MIN("CAR_Cargo") FROM "INV_CARGO"');
        $correo = 'Ana.'.D::sufijo().'@Pruebas.Local';

        [$e, $p] = $this->pedir('POST', '/api/v1/org/personas', $admin, ['PER_Primer_Nombre' => 'Ana', 'PER_Primer_Apellido' => 'López',
            'PER_Email_Corporativo' => $correo, 'DEP_Departamento' => $dep, 'CAR_Cargo' => $car]);
        self::assertSame(201, $e);
        self::assertSame([mb_strtolower($correo), $a, true], [$p['PER_Email_Corporativo'], $p['SED_Sede'], $p['PER_Estado']], 'Sede implícita: la única del alcance.');
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $p['PER_Persona']);
        self::assertArrayHasKey('created_at', $p);

        // El correo es único en toda la empresa, aunque la otra persona esté fuera del alcance.
        $correoAjeno = D::conexion()->fetchOne('SELECT "PER_Email_Corporativo" FROM "INV_PERSONA" WHERE "PER_Persona" = :p', ['p' => $ajena]);
        self::assertSame([400, 'EMAIL_ALREADY_EXISTS'], $this->detalle('POST', '/api/v1/org/personas', $admin, ['PER_Primer_Nombre' => 'Otra', 'PER_Primer_Apellido' => 'Persona',
            'PER_Email_Corporativo' => strtoupper((string) $correoAjeno), 'DEP_Departamento' => $dep, 'CAR_Cargo' => $car]));
        self::assertSame([403, 'SEDE_OUT_OF_SCOPE'], $this->detalle('POST', '/api/v1/org/personas', $admin, ['PER_Primer_Nombre' => 'Otra', 'PER_Primer_Apellido' => 'Persona',
            'PER_Email_Corporativo' => 'x'.D::sufijo().'@pruebas.local', 'DEP_Departamento' => $dep, 'CAR_Cargo' => $car, 'SED_Sede' => $b]));

        [, $lista] = $this->pedir('GET', '/api/v1/org/personas', $admin);
        self::assertContains($p['PER_Persona'], array_column($lista, 'PER_Persona'));
        self::assertNotContains(Ident::aTexto($ajena), array_column($lista, 'PER_Persona'));
        self::assertSame([404, 'PERSON_NOT_FOUND'], $this->detalle('GET', '/api/v1/org/personas/'.Ident::aTexto($ajena), $admin));
        self::assertSame([400, 'PERSON_CANNOT_BE_OWN_MANAGER'], $this->detalle('PATCH', "/api/v1/org/personas/{$p['PER_Persona']}", $admin, ['PER_Jefe' => $p['PER_Persona']]));
        self::assertSame(204, $this->pedir('DELETE', "/api/v1/org/personas/{$p['PER_Persona']}", $admin)[0]);
        self::assertFalse($this->pedir('GET', "/api/v1/org/personas/{$p['PER_Persona']}", $admin)[1]['PER_Estado']);

        $consulta = $this->token(D::crearUsuario(['rol' => 'CONSULTA', 'sedes' => [$a]]));
        self::assertSame([403, 'NOT_ENOUGH_PERMISSIONS'], $this->detalle('GET', '/api/v1/org/personas', $consulta), 'Consulta no ve datos personales.');
        self::assertSame(200, $this->pedir('GET', '/api/v1/org/sedes', $consulta)[0]);
    }

    public function testJerarquiaYAntiEscaladaEnIam(): void
    {
        $sede = D::crearSede('S '.D::sufijo());
        $super = D::crearUsuario(['rol' => 'SUPER_ADMIN']);
        $seg = D::crearUsuario(['rol' => 'ADMIN_SEGURIDAD']);
        $tSeg = $this->token($seg);
        $tSuper = $this->token($super);

        self::assertSame([403, 'NOT_ENOUGH_PERMISSIONS'], $this->detalle('GET', '/api/v1/org/usuarios', $this->token(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]))), 'ADMIN_TI no administra cuentas.');

        $persona = D::crearPersona($sede);
        // ADMIN_SEGURIDAD consulta, pero no crea ni modifica cuentas.
        self::assertSame(200, $this->pedir('GET', '/api/v1/org/usuarios', $tSeg)[0]);
        self::assertSame([403, 'NOT_ENOUGH_PERMISSIONS'], $this->detalle('POST', '/api/v1/org/usuarios', $tSeg, ['USU_Username' => 'u'.D::sufijo(),
            'USU_Password' => 'Zx9!kLm2Qw7r', 'USU_Rol' => 'TECNICO', 'PER_Persona' => Ident::aTexto($persona), 'sedes' => [$sede]]));
        self::assertSame([400, 'SCOPE_REQUIRED'], $this->detalle('POST', '/api/v1/org/usuarios', $tSuper, ['USU_Username' => 'u'.D::sufijo(),
            'USU_Password' => 'Zx9!kLm2Qw7r', 'USU_Rol' => 'TECNICO', 'PER_Persona' => Ident::aTexto($persona)]));
        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('POST', '/api/v1/org/usuarios', $tSuper, ['USU_Username' => 'u'.D::sufijo(),
            'USU_Rol' => 'TECNICO', 'PER_Persona' => Ident::aTexto($persona), 'sedes' => [$sede]]), 'Sin contraseña ni SSO.');

        [$e, $nuevo] = $this->pedir('POST', '/api/v1/org/usuarios', $tSuper, ['USU_Username' => 'tec_'.D::sufijo(), 'USU_Password' => 'Zx9!kLm2Qw7r',
            'USU_Rol' => 'TECNICO', 'PER_Persona' => Ident::aTexto($persona), 'sedes' => [$sede]]);
        self::assertSame(201, $e);
        self::assertSame([true, false, [['SED_Sede' => $sede, 'SED_Nombre' => $nuevo['sedes'][0]['SED_Nombre']]], false],
            [$nuevo['USU_Debe_Cambiar_Password'], $nuevo['USU_Alcance_Global'], $nuevo['sedes'], $nuevo['mfa_requerido']]);
        self::assertSame([400, 'PERSON_ALREADY_HAS_USER'], $this->detalle('POST', '/api/v1/org/usuarios', $tSuper, ['USU_Username' => 'otro_'.D::sufijo(),
            'USU_Password' => 'Zx9!kLm2Qw7r', 'USU_Rol' => 'TECNICO', 'PER_Persona' => Ident::aTexto($persona), 'sedes' => [$sede]]));

        $id = $nuevo['USU_Usuario'];
        foreach ([['PATCH', '', ['USU_Rol' => 'ADMIN_TI']], ['PATCH', '', ['sedes' => [$sede]]], ['DELETE', '', null],
            ['POST', '/password/reset', null], ['POST', '/2fa/reset', null], ['POST', '/unlock', null]] as [$metodo, $ruta, $cuerpo]) {
            self::assertSame([403, 'NOT_ENOUGH_PERMISSIONS'], $this->detalle($metodo, "/api/v1/org/usuarios/{$id}{$ruta}", $tSeg, $cuerpo), $metodo.$ruta);
        }
        self::assertSame(200, $this->pedir('POST', "/api/v1/org/usuarios/{$id}/sessions/revoke", $tSeg)[0], 'Puede cerrar sesiones ante un incidente.');
        self::assertSame([403, 'CANNOT_MODIFY_OWN_ACCOUNT'], $this->detalle('PATCH', '/api/v1/org/usuarios/'.Ident::aTexto($super['id']), $tSuper, ['USU_Estado' => false]));
        // El SUPER_ADMIN otorga y retira el alcance global.
        self::assertSame(200, $this->pedir('PATCH', "/api/v1/org/usuarios/{$id}", $tSuper, ['USU_Alcance_Global' => true])[0]);
        [$e, $editado] = $this->pedir('PATCH', "/api/v1/org/usuarios/{$id}", $tSuper, ['USU_Rol' => 'ADMIN_TI', 'USU_Alcance_Global' => false, 'sedes' => [$sede]]);
        self::assertSame([200, 'ADMIN_TI', false, true], [$e, $editado['USU_Rol'], $editado['USU_Alcance_Global'], $editado['mfa_requerido']]);

        $diff = json_decode((string) D::conexion()->fetchOne(
            'SELECT "AUD_Snapshot_JSON" FROM "INV_AUDITORIA_SISTEMA" WHERE "USU_Usuario" = :u AND "AUD_Accion" = \'UPDATE\' AND "AUD_Entidad_Afectada" = \'INV_USUARIO\' ORDER BY "AUD_Fecha_Hora" DESC FETCH FIRST 1 ROWS ONLY',
            ['u' => $super['id']]), true)['diff'];
        self::assertSame(['antes' => 'TECNICO', 'despues' => 'ADMIN_TI'], $diff['USU_Rol']);
        self::assertSame(['antes' => ['global' => true, 'sedes' => []], 'despues' => ['global' => false, 'sedes' => [$sede]]], $diff['alcance']);
    }

    public function testAccionesAdministrativasSobreUnaCuenta(): void
    {
        $sede = D::crearSede('S '.D::sufijo());
        $objetivo = D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$sede], 'metodo_2fa' => 'TOTP', 'secreto_totp' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP']);
        $iam = $this->token(D::crearUsuario(['rol' => 'SUPER_ADMIN']));
        $id = Ident::aTexto($objetivo['id']);
        $tokenObjetivo = $this->token($objetivo);
        D::conexion()->executeStatement('UPDATE "INV_USUARIO" SET "USU_Intentos_Fallidos" = 5, "USU_Bloqueado_Hasta" = SYS_EXTRACT_UTC(SYSTIMESTAMP) + INTERVAL \'1\' HOUR WHERE "USU_Usuario" = :u', ['u' => $objetivo['id']]);

        self::assertSame([200, ['status' => 'success']], $this->pedir('POST', "/api/v1/org/usuarios/{$id}/unlock", $iam));
        self::assertNull(D::conexion()->fetchOne('SELECT "USU_Bloqueado_Hasta" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $objetivo['id']]));

        [$e, $temporal] = $this->pedir('POST', "/api/v1/org/usuarios/{$id}/password/reset", $iam);
        self::assertSame(200, $e);
        self::assertSame(16, \strlen($temporal['temporary_password']));
        self::assertSame('no-store, private', $this->cliente->getResponse()->headers->get('Cache-Control'));
        self::assertSame(1, (int) D::conexion()->fetchOne('SELECT "USU_Debe_Cambiar_Password" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $objetivo['id']]));
        self::assertSame([401, 'TOKEN_REVOKED'], $this->detalle('GET', '/api/v1/me', $tokenObjetivo), 'Las sesiones previas se cierran.');
        $this->cliente->request('POST', '/api/v1/login/access-token', ['username' => $objetivo['username'], 'password' => $temporal['temporary_password']]);
        self::assertResponseIsSuccessful();

        self::assertSame([200, ['status' => 'success', 'message' => '2FA_RESET']], $this->pedir('POST', "/api/v1/org/usuarios/{$id}/2fa/reset", $iam));
        self::assertSame(0, (int) D::conexion()->fetchOne('SELECT "USU_2FA_Habilitado" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $objetivo['id']]));
        self::assertSame(204, $this->pedir('DELETE', "/api/v1/org/usuarios/{$id}", $iam)[0]);
        self::assertSame(0, (int) D::conexion()->fetchOne('SELECT "USU_Estado" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $objetivo['id']]));

        [, $resumen] = $this->pedir('GET', '/api/v1/org/usuarios/seguridad/resumen', $iam);
        self::assertTrue($resumen['rls_base_datos']);
        self::assertSame(['ADMIN_SEGURIDAD', 'ADMIN_TI', 'SUPER_ADMIN'], $resumen['politica']['mfa_roles_obligatorios']);
    }

    public function testUnSuperAdminPuedeDesactivarAOtroSuperAdmin(): void
    {
        // Quien desactiva es otro SUPER_ADMIN activo: nunca se queda el sistema sin ninguno
        // (la regla del último SUPER_ADMIN y la de la propia cuenta lo garantizan).
        $objetivo = D::crearUsuario(['rol' => 'SUPER_ADMIN']);
        $solicitante = $this->token(D::crearUsuario(['rol' => 'SUPER_ADMIN']));
        self::assertSame(200, $this->pedir('PATCH', '/api/v1/org/usuarios/'.Ident::aTexto($objetivo['id']), $solicitante, ['USU_Estado' => false])[0]);
        self::assertSame(0, (int) D::conexion()->fetchOne('SELECT "USU_Estado" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $objetivo['id']]));
    }

    public function testContextoDePersonaParaIam(): void
    {
        $sede = D::crearSede('S '.D::sufijo());
        $persona = D::crearPersona($sede);
        [$e, $contexto] = $this->pedir('GET', '/api/v1/org/personas/'.Ident::aTexto($persona).'/contexto', $this->token(D::crearUsuario(['rol' => 'ADMIN_SEGURIDAD'])));
        self::assertSame(200, $e);
        self::assertNotContains($contexto['rol_sugerido']['rol'], ['SUPER_ADMIN', 'ADMIN_SEGURIDAD'], 'Nunca se sugiere un rol privilegiado.');
        self::assertSame(['sedes' => [$sede], 'origen' => 'persona'], $contexto['alcance_sugerido']);
        self::assertSame([], $contexto['activos_vigentes'], 'ADMIN_SEGURIDAD no ve equipos.');
        self::assertMatchesRegularExpression('/^p[0-9a-f]{32}$/', (string) $contexto['username_sugerido']);
    }

    // ---- apoyo ---------------------------------------------------------

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
    private function pedir(string $metodo, string $ruta, string $token, ?array $cuerpo = null): array
    {
        $this->cliente->request($metodo, $ruta, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"],
            content: null === $cuerpo ? '' : json_encode($cuerpo));
        $r = $this->cliente->getResponse();

        return [$r->getStatusCode(), json_decode((string) $r->getContent(), true)];
    }

    /**
     * @param array<string, mixed>|null $cuerpo
     *
     * @return array{0: int, 1: mixed}
     */
    private function detalle(string $metodo, string $ruta, string $token, ?array $cuerpo = null): array
    {
        [$e, $json] = $this->pedir($metodo, $ruta, $token, $cuerpo);

        return [$e, $json['detail'] ?? $json];
    }
}
