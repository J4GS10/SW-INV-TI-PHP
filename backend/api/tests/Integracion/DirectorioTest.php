<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Mailpit;
use App\Tests\Soporte\PruebaApi;
use App\Tests\Soporte\SambaAd as AD;

/**
 * /api/v1/directorio contra Oracle real, el AD de pruebas (Samba AD por LDAPS y
 * StartTLS con certificado verificado) y Mailpit: contrato, permisos, cuenta de
 * servicio (secretos cifrados y nunca devueltos), sincronización de personas,
 * grupos anidados, reglas de notificación y cortacircuitos.
 */
final class DirectorioTest extends PruebaApi
{
    private const string BASE = '/api/v1/directorio';

    private string $super;
    private string $adminTi;

    protected function setUp(): void
    {
        parent::setUp();
        // Mismo proceso entre peticiones: el cortacircuitos en memoria se conserva (sin Redis en pruebas).
        $this->cliente->disableReboot();
        Mailpit::vaciar();
        AD::retirarPersonas();
        D::conexion()->executeStatement('DELETE FROM "SYS_INTEGRACION_CORREO"');
        D::conexion()->executeStatement('DELETE FROM "INV_REGLA_NOTIFICACION"');
        $this->super = $this->tokenDe(D::crearUsuario(['rol' => 'SUPER_ADMIN']));
        $this->adminTi = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));
    }

    protected function tearDown(): void
    {
        AD::habilitar(AD::DN_ANA, true);
        D::conexion()->executeStatement('DELETE FROM "SYS_INTEGRACION_CORREO"');
        parent::tearDown();
    }

    public function testPermisosYEstadoSinIntegracion(): void
    {
        self::assertSame(401, $this->pedir('GET', self::BASE.'/estado', token: '')[0]);
        foreach ([['GET', '/integracion'], ['POST', '/probar'], ['GET', '/grupos'], ['GET', '/notificaciones/reglas'],
            ['POST', '/integracion/desbloquear'], ['GET', '/notificaciones/vista-previa/asignacion']] as [$metodo, $ruta]) {
            self::assertSame([403, 'NOT_ENOUGH_PERMISSIONS'], $this->detalle($metodo, self::BASE.$ruta, null, $this->adminTi), $ruta);
        }
        $tecnico = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'global' => true]));
        self::assertSame(403, $this->pedir('GET', self::BASE.'/estado', token: $tecnico)[0]);

        $estado = $this->ok(200, 'GET', self::BASE.'/estado', token: $this->super);
        self::assertSame(['enabled', 'configured', 'server', 'base_dn', 'admin_group', 'sync_interval_minutes', 'last_sync'], array_keys($estado));
        self::assertFalse($estado['enabled']);
        self::assertSame(0, $estado['sync_interval_minutes']);

        // Sin AD: probar informa AD_DISABLED, los grupos son una lista vacía y sincronizar responde 400.
        self::assertSame(['ok' => false, 'message' => 'AD_DISABLED', 'usuarios_encontrados' => 0], $this->ok(200, 'POST', self::BASE.'/probar', token: $this->super));
        self::assertSame([], $this->ok(200, 'GET', self::BASE.'/grupos?q=x', token: $this->super));
        self::assertSame([400, 'AD_DISABLED'], $this->detalle('POST', self::BASE.'/sincronizar', null, $this->super));

        // Sin fila guardada, la configuración viene del entorno.
        $i = $this->ok(200, 'GET', self::BASE.'/integracion', token: $this->super);
        self::assertSame('entorno', $i['origen']);
        self::assertSame('CORREO', $i['modo']);
        self::assertSame('inventario@pruebas.local', $i['cuenta_email']);
        self::assertSame(['smtp_hasta' => null, 'ad_hasta' => null], $i['bloqueo']);
        self::assertTrue($i['cifrado_ok']);
        self::assertArrayNotHasKey('cuenta_password', $i);
    }

    public function testGuardarIntegracionCifraLosSecretosNoLosDevuelveNiLosAudita(): void
    {
        $formulario = [
            'modo' => 'CORREO_AD', 'proveedor' => 'SMTP', 'cuenta_email' => 'Servicio@Pruebas.LOCAL', 'smtp_host' => 'mailpit',
            'smtp_puerto' => 1025, 'smtp_seguridad' => 'NINGUNA', 'ad_servidor' => AD::SERVIDOR, 'ad_base_dn' => AD::BASE_USUARIOS,
            'ad_usar_cuenta_servicio' => false, 'ad_bind_usuario' => AD::CUENTA, 'ad_bind_password' => AD::CLAVE,
            'cuenta_password' => 'clave-smtp', 'ad_intervalo_min' => 30,
        ];
        $r = $this->ok(200, 'PUT', self::BASE.'/integracion', $formulario, $this->super);
        self::assertSame('interfaz', $r['origen']);
        self::assertSame('Servicio@pruebas.local', $r['cuenta_email'], 'Dominio en minúsculas, como CorporateEmail.');
        self::assertTrue($r['password_configurada']);
        self::assertTrue($r['ad_bind_password_configurada']);
        self::assertFalse($r['graph_secret_configurado']);
        foreach (['cuenta_password', 'ad_bind_password', 'graph_client_secret'] as $secreto) {
            self::assertArrayNotHasKey($secreto, $r);
        }
        $fila = D::conexion()->fetchAssociative('SELECT * FROM "SYS_INTEGRACION_CORREO" WHERE "INT_Id" = 1');
        self::assertIsArray($fila);
        self::assertStringNotContainsString(AD::CLAVE, (string) $fila['INT_AD_Bind_Password_Enc']);
        self::assertSame(AD::CLAVE, D::fernet()->descifrar((string) $fila['INT_AD_Bind_Password_Enc']));
        $auditoria = (string) D::conexion()->fetchOne(
            "SELECT \"AUD_Snapshot_JSON\" FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Entidad_Afectada\" = 'SYS_INTEGRACION_CORREO' ORDER BY \"AUD_Fecha_Hora\" DESC FETCH FIRST 1 ROWS ONLY");
        self::assertStringNotContainsString(AD::CLAVE, $auditoria);
        self::assertStringNotContainsString('clave-smtp', $auditoria);
        self::assertSame(['ad_bind_password', 'cuenta_password'], json_decode($auditoria, true)['secretos_modificados']);

        // Secreto omitido = se conserva; "" = se borra.
        $r = $this->ok(200, 'PUT', self::BASE.'/integracion', [...$formulario, 'ad_bind_password' => null, 'cuenta_password' => ''], $this->super);
        self::assertTrue($r['ad_bind_password_configurada']);
        self::assertFalse($r['password_configurada']);
        $estado = $this->ok(200, 'GET', self::BASE.'/estado', token: $this->super);
        self::assertSame([true, true, AD::SERVIDOR, AD::BASE_USUARIOS, 30], [$estado['enabled'], $estado['configured'], $estado['server'], $estado['base_dn'], $estado['sync_interval_minutes']]);
    }

    public function testValidacionesDelFormularioConElMismoContrato(): void
    {
        $sinCuenta = $this->pedir('PUT', self::BASE.'/integracion', ['modo' => 'CORREO', 'smtp_host' => 'x'], $this->super);
        self::assertSame(422, $sinCuenta[0]);
        self::assertSame(['value_error', ['body'], 'Value error, CUENTA_EMAIL_REQUERIDA'],
            [$sinCuenta[1]['errors'][0]['type'], $sinCuenta[1]['errors'][0]['loc'], $sinCuenta[1]['errors'][0]['msg']]);

        $casos = [
            [['modo' => 'CORREO_AD', 'cuenta_email' => 'a@b.local', 'smtp_host' => 'x', 'ad_servidor' => 'http://dc', 'ad_base_dn' => 'DC=x'], 'AD_SERVIDOR_DEBE_SER_LDAP_O_LDAPS'],
            [['modo' => 'CORREO_AD', 'cuenta_email' => 'a@b.local', 'smtp_host' => 'x'], 'AD_SERVIDOR_Y_BASE_DN_REQUERIDOS'],
            [['modo' => 'CORREO', 'proveedor' => 'GRAPH', 'cuenta_email' => 'a@b.local', 'graph_tenant_id' => 't'], 'GRAPH_TENANT_Y_CLIENT_REQUERIDOS'],
            [['modo' => 'CORREO_AD', 'cuenta_email' => 'a@b.local', 'smtp_host' => 'x', 'ad_servidor' => AD::SERVIDOR, 'ad_base_dn' => 'DC=x', 'ad_usar_cuenta_servicio' => false], 'AD_BIND_USUARIO_REQUERIDO'],
        ];
        foreach ($casos as [$cuerpo, $codigo]) {
            [$e, $r] = $this->pedir('PUT', self::BASE.'/integracion', $cuerpo, $this->super);
            self::assertSame([422, "Value error, {$codigo}"], [$e, $r['errors'][0]['msg']], $codigo);
        }
        [$e, $r] = $this->pedir('PUT', self::BASE.'/integracion', ['modo' => 'OTRO', 'smtp_puerto' => 0, 'ad_intervalo_min' => 99999], $this->super);
        self::assertSame(422, $e);
        self::assertSame([['body', 'modo'], ['body', 'smtp_puerto'], ['body', 'ad_intervalo_min']], array_column($r['errors'], 'loc'));
        self::assertSame(['literal_error', 'greater_than_equal', 'less_than_equal'], array_column($r['errors'], 'type'));

        // En las pruebas, los errores del formulario van bajo ["body", "config"].
        [$e, $r] = $this->pedir('POST', self::BASE.'/integracion/probar-correo', ['config' => ['modo' => 'CORREO'], 'destinatario' => 'no-es-correo'], $this->super);
        self::assertSame(422, $e);
        self::assertSame([['body', 'config'], ['body', 'destinatario']], array_column($r['errors'], 'loc'));
        [$e, $r] = $this->pedir('POST', self::BASE.'/integracion/probar-ad', [], $this->super);
        self::assertSame([422, ['body', 'config'], 'missing'], [$e, $r['errors'][0]['loc'], $r['errors'][0]['type']]);
        [$e, $r] = $this->pedir('POST', self::BASE.'/integracion/probar-ad', ['config' => ['smtp_puerto' => 'x']], $this->super);
        self::assertSame([422, ['body', 'config', 'smtp_puerto']], [$e, $r['errors'][0]['loc']]);
        self::assertSame(422, $this->pedir('POST', self::BASE.'/sincronizar?dry_run=quizas', token: $this->super)[0]);
        self::assertSame(422, $this->pedir('GET', self::BASE.'/grupos?q='.str_repeat('a', 101), token: $this->super)[0]);
    }

    public function testProbarCorreoEnviaUnCorreoRealSinGuardarNada(): void
    {
        $config = ['modo' => 'CORREO', 'cuenta_email' => 'servicio@pruebas.local', 'smtp_host' => 'mailpit', 'smtp_puerto' => 1025, 'smtp_seguridad' => 'NINGUNA'];
        $r = $this->ok(200, 'POST', self::BASE.'/integracion/probar-correo', ['config' => $config, 'destinatario' => 'destino@pruebas.local'], $this->super);
        self::assertSame(['ok' => true, 'codigo' => 'OK', 'mensaje' => 'Correo enviado a destino@pruebas.local', 'usuarios_encontrados' => 0], $r);
        $mensajes = Mailpit::para('destino@pruebas.local');
        self::assertCount(1, $mensajes);
        self::assertSame('[Inventario] Prueba de la cuenta de servicio', $mensajes[0]['Subject']);
        self::assertStringContainsString('Remitente: servicio@pruebas.local · Proveedor: SMTP', (string) $mensajes[0]['HTML']);
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "SYS_INTEGRACION_CORREO"'), 'La prueba no guarda la configuración.');

        $r = $this->ok(200, 'POST', self::BASE.'/integracion/probar-correo', ['config' => ['modo' => 'DESACTIVADO'], 'destinatario' => 'destino@pruebas.local'], $this->super);
        self::assertSame(['ok' => false, 'codigo' => 'NOT_CONFIGURED', 'mensaje' => 'Modo desactivado o faltan datos del proveedor.', 'usuarios_encontrados' => 0], $r);
        $r = $this->ok(200, 'POST', self::BASE.'/integracion/probar-correo', ['config' => [...$config, 'smtp_host' => 'no-existe.invalid'], 'destinatario' => 'destino@pruebas.local'], $this->super);
        self::assertFalse($r['ok']);
        self::assertSame('CONNECTION_FAILED', $r['codigo']);
    }

    public function testProbarAdPorLdapsYStartTlsConCertificadoVerificado(): void
    {
        $base = ['modo' => 'CORREO_AD', 'cuenta_email' => 'servicio@pruebas.local', 'smtp_host' => 'mailpit', 'ad_base_dn' => AD::BASE_USUARIOS,
            'ad_usar_cuenta_servicio' => false, 'ad_bind_usuario' => AD::CUENTA, 'ad_bind_password' => AD::CLAVE];
        // Límite real de 5/min por usuario: cada prueba con un SUPER_ADMIN distinto.
        $probar = fn (array $cambios): array => $this->ok(200, 'POST', self::BASE.'/integracion/probar-ad', ['config' => [...$base, ...$cambios]],
            $this->tokenDe(D::crearUsuario(['rol' => 'SUPER_ADMIN'])));

        // jefe.ti, ana.lopez y baja.ad (sin.correo no cumple el filtro; la cuenta de servicio está en otra OU).
        self::assertSame(['ok' => true, 'codigo' => 'OK', 'mensaje' => 'Conexión correcta', 'usuarios_encontrados' => 3], $probar(['ad_servidor' => AD::SERVIDOR]));
        self::assertSame(3, $probar(['ad_servidor' => AD::SERVIDOR_STARTTLS, 'ad_start_tls' => true])['usuarios_encontrados']);
        // Varios DC: el primero no responde, se usa el siguiente.
        self::assertTrue($probar(['ad_servidor' => 'ldaps://no-existe.invalid,'.AD::SERVIDOR])['ok']);

        self::assertSame(['ok' => false, 'codigo' => 'AUTH_FAILED', 'mensaje' => 'AD_AUTH_FAILED: LDAPInvalidCredentialsResult', 'usuarios_encontrados' => 0],
            $probar(['ad_servidor' => AD::SERVIDOR, 'ad_bind_password' => 'clave-mala']));
        // LDAP plano sin StartTLS: la contraseña viajaría en claro; se rechaza sin intentar el bind.
        self::assertSame(['CONNECTION_FAILED', 'AD_INSECURE_CHANNEL: usar ldaps:// o StartTLS'],
            array_values(array_intersect_key($probar(['ad_servidor' => AD::SERVIDOR_STARTTLS]), ['codigo' => 1, 'mensaje' => 1])));
        // Certificado válido pero de otro nombre (se llega por el nombre del contenedor): se rechaza.
        self::assertSame('CONNECTION_FAILED', $probar(['ad_servidor' => 'ldaps://samba-ad-pruebas'])['codigo']);
        // La verificación del certificado no se puede desactivar desde el formulario.
        self::assertSame('CONNECTION_FAILED', $probar(['ad_servidor' => 'ldaps://samba-ad-pruebas', 'ad_verificar_cert' => false])['codigo']);
        self::assertSame(['ok' => false, 'codigo' => 'NOT_CONFIGURED', 'mensaje' => 'Faltan servidor, base DN o usuario de conexión.', 'usuarios_encontrados' => 0],
            $this->ok(200, 'POST', self::BASE.'/integracion/probar-ad', ['config' => ['modo' => 'CORREO', 'cuenta_email' => 'a@b.local', 'smtp_host' => 'x']], $this->super));
    }

    public function testSincronizacionCreaVinculaAsignaJefesYLaSimulacionNoGuarda(): void
    {
        AD::configurar();
        $antes = D::contar('SELECT COUNT(*) FROM "INV_PERSONA"');
        $simulada = $this->ok(200, 'POST', self::BASE.'/sincronizar?dry_run=true', token: $this->super);
        self::assertSame([true, 3, 2, 1], [$simulada['dry_run'], $simulada['total_ad'], $simulada['creados'], $simulada['jefes_asignados']]);
        self::assertSame($antes, D::contar('SELECT COUNT(*) FROM "INV_PERSONA"'), 'La simulación revierte.');
        self::assertSame(['fecha', 'dry_run', 'total_ad', 'creados', 'actualizados', 'vinculados', 'desactivados', 'reactivados',
            'jefes_asignados', 'pendientes_con_activos', 'errores', 'bajas_retenidas', 'advertencia'], array_keys($simulada));

        $r = $this->ok(200, 'POST', self::BASE.'/sincronizar', token: $this->super);
        self::assertSame([false, 2, 0, 0, 1, 0, null], [$r['dry_run'], $r['creados'], $r['actualizados'], $r['desactivados'], $r['jefes_asignados'], $r['bajas_retenidas'], $r['advertencia']]);
        $ana = D::conexion()->fetchAssociative('SELECT p.*, d."DEP_Nombre", c."CAR_Nombre" FROM "INV_PERSONA" p JOIN "INV_DEPARTAMENTO" d ON d."DEP_Departamento" = p."DEP_Departamento" '
            .'JOIN "INV_CARGO" c ON c."CAR_Cargo" = p."CAR_Cargo" WHERE p."PER_Email_Corporativo" = :c', ['c' => AD::ANA]);
        $jefe = D::conexion()->fetchAssociative('SELECT * FROM "INV_PERSONA" WHERE "PER_Email_Corporativo" = :c', ['c' => AD::JEFE]);
        self::assertIsArray($ana);
        self::assertIsArray($jefe);
        self::assertSame(['Ana', 'María', 'López', 'García'], [$ana['PER_Primer_Nombre'], $ana['PER_Segundo_Nombre'], $ana['PER_Primer_Apellido'], $ana['PER_Segundo_Apellido']]);
        self::assertSame(['De la Cruz', 'Pérez', '22220000'], [$jefe['PER_Primer_Apellido'], $jefe['PER_Segundo_Apellido'], $jefe['PER_Telefono']]);
        self::assertSame(['Tecnología AD', 'Analista AD', AD::DN_ANA], [$ana['DEP_Nombre'], $ana['CAR_Nombre'], $ana['PER_AD_DN']]);
        self::assertSame($jefe['PER_Persona'], $ana['PER_Jefe']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $ana['PER_AD_GUID']);
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "INV_PERSONA" WHERE "PER_Email_Corporativo" = :c', ['c' => AD::BAJA]),
            'No se crean personas para cuentas deshabilitadas.');

        // Segunda pasada sin cambios en el AD: nada que crear ni actualizar.
        $r = $this->ok(200, 'POST', self::BASE.'/sincronizar', token: $this->super);
        self::assertSame([0, 0, 0, 0], [$r['creados'], $r['actualizados'], $r['vinculados'], $r['jefes_asignados']]);
        $ultima = $this->ok(200, 'GET', self::BASE.'/estado', token: $this->super)['last_sync'];
        self::assertSame($r, $ultima, 'El estado devuelve la última sincronización registrada.');
        self::assertSame(1, D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'AD_SYNC' AND \"AUD_Snapshot_JSON\" LIKE :f",
            ['f' => '%'.$r['fecha'].'%']));
    }

    public function testVinculaPorCorreoYDetectaConflictos(): void
    {
        AD::configurar();
        // Persona existente con el correo de ana (otras mayúsculas): se vincula, no se duplica.
        $id = D::crearPersona(null, 'Previa');
        D::conexion()->executeStatement('UPDATE "INV_PERSONA" SET "PER_Email_Corporativo" = :c WHERE "PER_Persona" = :id', ['c' => 'Ana.Lopez@ad.pruebas.local', 'id' => $id]);
        $r = $this->ok(200, 'POST', self::BASE.'/sincronizar', token: $this->super);
        self::assertSame([1, 1], [$r['creados'], $r['vinculados']]);
        self::assertSame(AD::ANA, D::conexion()->fetchOne('SELECT "PER_Email_Corporativo" FROM "INV_PERSONA" WHERE "PER_Persona" = :id', ['id' => $id]));

        // Otra persona ya usa el correo nuevo del jefe: se informa y no se toca a ninguna.
        AD::modificar(AD::DN_JEFE, ['mail' => ['ana.lopez@ad.pruebas.local']]);
        try {
            $r = $this->ok(200, 'POST', self::BASE.'/sincronizar', token: $this->super);
            self::assertContains(['email' => AD::ANA, 'detalle' => 'EMAIL_DUPLICADO_EN_AD'], $r['errores']);
        } finally {
            AD::modificar(AD::DN_JEFE, ['mail' => ['Jefe.TI@AD.Pruebas.Local']]);
        }
    }

    public function testBajasEnElDirectorioRespetanActivosYProtegenAlUltimoSuperAdmin(): void
    {
        AD::configurar();
        $this->ok(200, 'POST', self::BASE.'/sincronizar', token: $this->super);
        $ana = (string) D::conexion()->fetchOne('SELECT "PER_Persona" FROM "INV_PERSONA" WHERE "PER_Email_Corporativo" = :c', ['c' => AD::ANA]);
        $cuenta = D::crearUsuario(['rol' => 'TECNICO']);
        D::conexion()->executeStatement('UPDATE "INV_USUARIO" SET "PER_Persona" = :p WHERE "USU_Usuario" = :u', ['p' => $ana, 'u' => $cuenta['id']]);
        $tokenAna = $this->tokenDe($cuenta);
        self::assertSame(200, $this->pedir('GET', '/api/v1/me', token: $tokenAna)[0]);

        // Cuenta deshabilitada en el AD: persona y usuario desactivados, acceso revocado.
        AD::habilitar(AD::DN_ANA, false);
        $r = $this->ok(200, 'POST', self::BASE.'/sincronizar', token: $this->super);
        self::assertSame(1, $r['desactivados']);
        self::assertSame('0', (string) D::conexion()->fetchOne('SELECT "PER_Estado" FROM "INV_PERSONA" WHERE "PER_Persona" = :p', ['p' => $ana]));
        self::assertSame('0', (string) D::conexion()->fetchOne('SELECT "USU_Estado" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $cuenta['id']]));
        self::assertContains($this->pedir('GET', '/api/v1/me', token: $tokenAna)[0], [401, 403], 'El token emitido antes ya no sirve.');

        // Habilitada de nuevo: la persona se reactiva; su usuario del sistema no.
        AD::habilitar(AD::DN_ANA, true);
        $r = $this->ok(200, 'POST', self::BASE.'/sincronizar', token: $this->super);
        self::assertSame(1, $r['reactivados']);
        self::assertSame('0', (string) D::conexion()->fetchOne('SELECT "USU_Estado" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $cuenta['id']]));

        // El directorio no deja el sistema sin SUPER_ADMIN activo.
        $otros = D::conexion()->fetchFirstColumn("SELECT \"USU_Usuario\" FROM \"INV_USUARIO\" WHERE \"USU_Rol\" = 'SUPER_ADMIN' AND \"USU_Estado\" = 1");
        D::conexion()->executeStatement("UPDATE \"INV_USUARIO\" SET \"USU_Estado\" = 0 WHERE \"USU_Rol\" = 'SUPER_ADMIN'");
        D::conexion()->executeStatement("UPDATE \"INV_USUARIO\" SET \"USU_Estado\" = 1, \"USU_Rol\" = 'SUPER_ADMIN' WHERE \"USU_Usuario\" = :u", ['u' => $cuenta['id']]);
        try {
            AD::habilitar(AD::DN_ANA, false);
            $r = $this->ok(200, 'POST', self::BASE.'/sincronizar', token: $this->tokenDe($cuenta));
            self::assertSame(0, $r['desactivados']);
            self::assertSame('1', (string) D::conexion()->fetchOne('SELECT "USU_Estado" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $cuenta['id']]));
            $snapshot = json_decode((string) D::conexion()->fetchOne(
                "SELECT \"AUD_Snapshot_JSON\" FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'AD_SYNC' ORDER BY \"AUD_Fecha_Hora\" DESC FETCH FIRST 1 ROWS ONLY"), true);
            self::assertSame('LAST_SUPER_ADMIN', $snapshot['protegidos'][0]['motivo']);
            self::assertArrayNotHasKey('protegidos', $r, 'Los protegidos solo quedan en la bitácora (como el contrato actual).');
        } finally {
            D::conexion()->executeStatement("UPDATE \"INV_USUARIO\" SET \"USU_Rol\" = 'TECNICO', \"USU_Estado\" = 0 WHERE \"USU_Usuario\" = :u", ['u' => $cuenta['id']]);
            foreach ($otros as $u) {
                D::conexion()->executeStatement('UPDATE "INV_USUARIO" SET "USU_Estado" = 1 WHERE "USU_Usuario" = :u', ['u' => $u]);
            }
        }
    }

    public function testBajaMasivaSeRetieneHastaConfirmarla(): void
    {
        AD::configurar();
        $this->ok(200, 'POST', self::BASE.'/sincronizar', token: $this->super);
        // Base mal configurada (una OU sin usuarios): el AD «devuelve» 0 personas.
        AD::configurar(['INT_AD_Base_DN' => AD::BASE_GRUPOS]);
        $this->servicio(\App\Integracion\FuenteIntegracion::class)->invalidar();
        $r = $this->ok(200, 'POST', self::BASE.'/sincronizar', token: $this->super);
        self::assertSame([0, 0, 2, 'AD_SYNC_MASS_DEACTIVATION_BLOCKED'], [$r['total_ad'], $r['desactivados'], $r['bajas_retenidas'], $r['advertencia']]);
        self::assertSame(2, D::contar('SELECT COUNT(*) FROM "INV_PERSONA" WHERE "PER_AD_GUID" IS NOT NULL AND "PER_Estado" = 1'));

        $r = $this->ok(200, 'POST', self::BASE.'/sincronizar?forzar_bajas=true', token: $this->super);
        self::assertSame([2, 0], [$r['desactivados'], $r['bajas_retenidas']]);
    }

    public function testGruposConEscapeYDestinatariosPorReglasJefesYGruposAnidados(): void
    {
        AD::configurar(['INT_AD_Grupo_Admin' => 'Inventario-Admins']);
        self::assertSame([['nombre' => 'Soporte-N2', 'dn' => 'CN=Soporte-N2,'.AD::BASE_GRUPOS]],
            $this->ok(200, 'GET', self::BASE.'/grupos?q=soporte', token: $this->super));
        self::assertSame(['Grupo (especial)'], array_column($this->ok(200, 'GET', self::BASE.'/grupos?q=(especial)', token: $this->super), 'nombre'));
        self::assertSame([], $this->ok(200, 'GET', self::BASE.'/grupos?q=*)(cn=*', token: $this->super), 'Sin inyección en el filtro LDAP.');
        self::assertCount(3, $this->ok(200, 'GET', self::BASE.'/grupos', token: $this->super));

        $this->ok(200, 'POST', self::BASE.'/sincronizar', token: $this->super);
        $ana = (string) D::conexion()->fetchOne('SELECT "PER_Persona" FROM "INV_PERSONA" WHERE "PER_Email_Corporativo" = :c', ['c' => AD::ANA]);
        $this->ok(200, 'PUT', self::BASE.'/notificaciones/reglas/asignacion', ['notificar_jefe' => true, 'copiar_admins' => false,
            'grupos_ad' => ['Soporte-N2'], 'correos_extra' => ['Extra@Pruebas.LOCAL']], $this->super);
        $previa = $this->ok(200, 'GET', self::BASE.'/notificaciones/vista-previa/asignacion?persona_id='.\App\Dominio\Ident::aTexto($ana), token: $this->super);
        self::assertSame([AD::ANA, AD::JEFE, 'extra@pruebas.local'], $previa['destinatarios']);
        self::assertSame([['email' => AD::ANA, 'origen' => 'afectado'], ['email' => AD::JEFE, 'origen' => 'jefe'], ['email' => 'extra@pruebas.local', 'origen' => 'extra']],
            $previa['detalle'], 'Sin repetidos: ana ya estaba como afectada (también es de Soporte-N2).');

        // Administradores: NOTIFY_ADMIN_EMAILS + miembros transitivos y habilitados del grupo (baja.ad no).
        $previa = $this->ok(200, 'GET', self::BASE.'/notificaciones/vista-previa/stock_bajo', token: $this->super);
        self::assertSame(['admin@pruebas.local', AD::ANA, AD::JEFE], $previa['destinatarios']);
        self::assertSame(['admins'], array_values(array_unique(array_column($previa['detalle'], 'origen'))));
        self::assertSame([404, 'PERSON_NOT_FOUND'], $this->detalle('GET', self::BASE.'/notificaciones/vista-previa/asignacion?persona_id='.\App\Dominio\Ident::aTexto(\App\Dominio\Ident::nuevo()), null, $this->super));
    }

    public function testReglasDeNotificacionCrudAuditoriaYContrato(): void
    {
        $reglas = $this->ok(200, 'GET', self::BASE.'/notificaciones/reglas', token: $this->super);
        self::assertSame(['asignacion', 'devolucion', 'transferencia', 'baja', 'offboarding', 'mantenimiento_abierto', 'mantenimiento_cerrado',
            'stock_bajo', 'garantia_por_vencer', 'recepcion_diferencias', 'ingreso_registrado'], array_column($reglas, 'evento'));
        self::assertSame(['activa' => true, 'notificar_afectado' => false, 'notificar_jefe' => false, 'copiar_admins' => true,
            'grupos_ad' => [], 'correos_extra' => [], 'evento' => 'baja', 'personalizada' => false], $reglas[3]);

        $r = $this->ok(200, 'PUT', self::BASE.'/notificaciones/reglas/baja', ['activa' => false, 'grupos_ad' => ['A', ' A ', 'B'],
            'correos_extra' => ['x@pruebas.local']], $this->super);
        self::assertSame(['activa' => false, 'notificar_afectado' => true, 'notificar_jefe' => false, 'copiar_admins' => true,
            'grupos_ad' => ['A', 'B'], 'correos_extra' => ['x@pruebas.local'], 'evento' => 'baja', 'personalizada' => true], $r);
        self::assertSame(['destinatarios' => [], 'detalle' => []], $this->ok(200, 'GET', self::BASE.'/notificaciones/vista-previa/baja', token: $this->super),
            'Regla inactiva: nadie recibe el aviso.');
        self::assertSame(204, $this->pedir('DELETE', self::BASE.'/notificaciones/reglas/baja', token: $this->super)[0]);
        self::assertFalse($this->ok(200, 'GET', self::BASE.'/notificaciones/reglas', token: $this->super)[3]['personalizada']);
        self::assertSame(204, $this->pedir('DELETE', self::BASE.'/notificaciones/reglas/baja', token: $this->super)[0], 'Restablecer dos veces no falla.');
        self::assertGreaterThanOrEqual(2, D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Entidad_Afectada\" = 'INV_REGLA_NOTIFICACION' AND \"AUD_Fecha_Hora\" > SYSTIMESTAMP - INTERVAL '5' MINUTE"));

        foreach ([['PUT', ['activa' => true]], ['DELETE', null]] as [$metodo, $cuerpo]) {
            self::assertSame([404, 'NOTIFICATION_EVENT_NOT_FOUND'], $this->detalle($metodo, self::BASE.'/notificaciones/reglas/password_reset', $cuerpo, $this->super));
        }
        self::assertSame([404, 'NOTIFICATION_EVENT_NOT_FOUND'], $this->detalle('GET', self::BASE.'/notificaciones/vista-previa/no-existe', null, $this->super));
        [$e, $r] = $this->pedir('PUT', self::BASE.'/notificaciones/reglas/baja', ['correos_extra' => ['ok@pruebas.local', 'mal'], 'activa' => null], $this->super);
        self::assertSame(422, $e);
        self::assertSame([['body', 'activa'], ['body', 'correos_extra', 1]], array_column($r['errors'], 'loc'));
        [$e, $r] = $this->pedir('PUT', self::BASE.'/notificaciones/reglas/baja', ['grupos_ad' => array_fill(0, 21, 'g')], $this->super);
        self::assertSame([422, 'too_long'], [$e, $r['errors'][0]['type']]);
    }

    public function testCortacircuitosDeAdPausaTrasUnaClaveMalaYSeDesbloquea(): void
    {
        AD::configurar(['INT_AD_Bind_Password_Enc' => D::fernet()->cifrar('clave-mala')]);
        self::assertSame(['ok' => false, 'message' => 'AD_AUTH_FAILED: LDAPInvalidCredentialsResult', 'usuarios_encontrados' => 0],
            $this->ok(200, 'POST', self::BASE.'/probar', token: $this->super));
        // Pausado: no se intenta otro bind (protege la cuenta de servicio de un bloqueo en el AD).
        self::assertSame('AD_AUTH_PAUSED', $this->ok(200, 'POST', self::BASE.'/probar', token: $this->super)['message']);
        self::assertSame([502, 'AD_AUTH_FAILED'], $this->detalle('POST', self::BASE.'/sincronizar', null, $this->super));
        self::assertNotNull($this->ok(200, 'GET', self::BASE.'/integracion', token: $this->super)['bloqueo']['ad_hasta']);
        self::assertSame([], $this->ok(200, 'GET', self::BASE.'/grupos', token: $this->super), 'Grupos: lista vacía mientras está pausado.');

        AD::configurar();
        $this->servicio(\App\Integracion\FuenteIntegracion::class)->invalidar();
        self::assertSame(204, $this->pedir('POST', self::BASE.'/integracion/desbloquear', token: $this->super)[0]);
        self::assertNull($this->ok(200, 'GET', self::BASE.'/integracion', token: $this->super)['bloqueo']['ad_hasta']);
        self::assertSame(3, $this->ok(200, 'POST', self::BASE.'/probar', token: $this->super)['usuarios_encontrados']);
        // Directorio caído: sincronizar responde 502 AD_UNAVAILABLE.
        AD::configurar(['INT_AD_Servidor' => 'ldaps://no-existe.invalid']);
        $this->servicio(\App\Integracion\FuenteIntegracion::class)->invalidar();
        self::assertSame([502, 'AD_UNAVAILABLE'], $this->detalle('POST', self::BASE.'/sincronizar', null, $this->super));
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
        /** @var T */
        return $this->cliente->getContainer()->get($clase);
    }
}
