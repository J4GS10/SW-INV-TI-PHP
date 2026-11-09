<?php

declare(strict_types=1);

/*
 * Siembra el entorno de QA (fase 7): dos sedes de QA y un usuario por rol y
 * caso (global y no global, con y sin 2FA, cambio obligatorio, para bloqueo).
 * Idempotente: si el usuario existe, le repone contraseña, estado y 2FA.
 *
 * Corre dentro de inventario-php-dev con el repo montado, conectado como DUEÑO
 * del esquema de QA (sin contexto VPD = modo sistema). Lo llama
 * herramientas/qa/entorno.sh sembrar; nunca apunta a producción (exige
 * ENVIRONMENT=qa y la Oracle del proyecto inventario_qa).
 *
 * Salida: QA_DIR/usuarios.json (usuarios, contraseñas y secretos TOTP de QA;
 * no versionado).
 */

use App\Db\SesionOracleMiddleware;
use App\Dominio\Ident;
use App\Seguridad\Contrasenas;
use App\Seguridad\Fernet;
use App\Seguridad\Totp;
use Doctrine\DBAL\DriverManager;

require '/repo/api/vendor/autoload.php';

$e = static fn (string $k): string => (string) (getenv($k) ?: '');
if ('qa' !== $e('ENVIRONMENT')) {
    fwrite(STDERR, "sembrar.php solo corre con ENVIRONMENT=qa.\n");
    exit(2);
}
$salida = $e('QA_SALIDA') ?: '/qa/usuarios.json';

$c = DriverManager::getConnection([
    'driver' => 'oci8',
    'host' => $e('ORACLE_HOST'),
    'port' => 1521,
    'dbname' => 'XEPDB1',
    'servicename' => 'XEPDB1',
    'service' => true,
    'user' => $e('ORACLE_SCHEMA_OWNER'),
    'password' => $e('ORACLE_OWNER_PASSWORD'),
    'charset' => 'AL32UTF8',
    'driverOptions' => ['exclusive' => true],
]);
foreach (SesionOracleMiddleware::sentencias('', '') as $sql) {
    $c->executeStatement($sql);
}
$fernet = new Fernet(explode(',', $e('FIELD_ENCRYPTION_KEY')));

// Sedes de QA (dos, para probar el alcance entre sedes).
// Base vacía: lo mínimo para que exista una sede y una persona.
if (0 === (int) $c->fetchOne('SELECT COUNT(*) FROM "INV_MUNICIPIO"')) {
    $c->executeStatement("INSERT INTO \"INV_PAIS\" (\"PAI_Nombre\", \"PAI_Codigo_ISO\") VALUES ('Guatemala', 'GT')");
    $c->executeStatement("INSERT INTO \"INV_ESTADO\" (\"EST_Nombre\", \"PAI_Pais\") SELECT 'Guatemala', MAX(\"PAI_Pais\") FROM \"INV_PAIS\"");
    $c->executeStatement("INSERT INTO \"INV_MUNICIPIO\" (\"MUN_Nombre\", \"EST_Estado\") SELECT 'Guatemala', MAX(\"EST_Estado\") FROM \"INV_ESTADO\"");
}
if (0 === (int) $c->fetchOne('SELECT COUNT(*) FROM "INV_CARGO" WHERE "CAR_Es_Jefatura" = 0')) {
    $c->executeStatement("INSERT INTO \"INV_CARGO\" (\"CAR_Nombre\", \"CAR_Es_Jefatura\") VALUES ('Colaborador', 0)");
}
$municipio = (int) $c->fetchOne('SELECT MIN("MUN_Municipio") FROM "INV_MUNICIPIO"');
$sedes = [];
foreach (['A' => 'QA Sede Norte', 'B' => 'QA Sede Sur'] as $clave => $nombre) {
    $id = $c->fetchOne('SELECT MIN("SED_Sede") FROM "INV_SEDE" WHERE "SED_Nombre" = :n', ['n' => $nombre]);
    if (null === $id) {
        $c->executeStatement('INSERT INTO "INV_SEDE" ("SED_Nombre", "MUN_Municipio") VALUES (:n, :m)', ['n' => $nombre, 'm' => $municipio]);
        $id = $c->fetchOne('SELECT MIN("SED_Sede") FROM "INV_SEDE" WHERE "SED_Nombre" = :n', ['n' => $nombre]);
    }
    $sedes[$clave] = (int) $id;
}
$departamento = (int) $c->fetchOne('SELECT MIN("DEP_Departamento") FROM "INV_DEPARTAMENTO" WHERE "DEP_Activo" = 1');
$cargo = (int) $c->fetchOne('SELECT MIN("CAR_Cargo") FROM "INV_CARGO" WHERE "CAR_Es_Jefatura" = 0');

// username => [rol, global, sedes, 2FA (null|TOTP|EMAIL), debe cambiar]
$plan = [
    // Los roles de TWO_FACTOR_REQUIRED_ROLES ya traen 2FA; qa_enrolar prueba el enrolamiento obligatorio.
    'qa_superadmin' => ['SUPER_ADMIN', true, [], 'TOTP', false],
    'qa_admin_seg' => ['ADMIN_SEGURIDAD', true, [], 'EMAIL', false],
    'qa_jefe_ti' => ['ADMIN_TI', true, [], 'TOTP', false],
    'qa_admin_ti' => ['ADMIN_TI', true, [], 'TOTP', false],
    'qa_totp' => ['ADMIN_TI', true, [], 'TOTP', false],
    'qa_enrolar' => ['ADMIN_TI', true, [], null, false],
    'qa_admin_ti_norte' => ['ADMIN_TI', false, ['A'], 'TOTP', false],
    'qa_tecnico_norte' => ['TECNICO', false, ['A'], null, false],
    'qa_tecnico_sur' => ['TECNICO', false, ['B'], null, false],
    'qa_auditor' => ['AUDITOR', true, [], 'EMAIL', false],
    'qa_consulta_norte' => ['CONSULTA', false, ['A'], null, false],
    'qa_compras_norte' => ['COMPRAS', false, ['A'], null, false],
    'qa_contabilidad' => ['COMPRAS', true, [], null, false],
    'qa_cambio' => ['TECNICO', false, ['A'], null, true],
    'qa_bloqueo' => ['CONSULTA', false, ['A'], null, false],
];


// Cuentas para probar a mano (usuarios y clave en un archivo no versionado): sin 2FA ni cambio obligatorio.
$rutaManuales = dirname($salida).'/usuarios_manuales.json';
$manuales = is_file($rutaManuales) ? json_decode((string) file_get_contents($rutaManuales), true, flags: JSON_THROW_ON_ERROR) : ['password' => '', 'usuarios' => []];
$nombresManuales = [];
// Uso manual: solo las cuentas sencillas. La suite E2E (QA_MFA_REQUIRED=1) necesita además sus cuentas qa_*.
if ('1' !== $e('QA_MFA_REQUIRED')) {
    $plan = [];
}
foreach ($manuales['usuarios'] as $username => [$rol, $global, $susSedes]) {
    $plan[$username] = [$rol, $global, $susSedes, null, false];
    $nombresManuales[$username] = true;
}

// El interruptor del administrador del sistema arranca apagado en QA; la suite E2E lo enciende (QA_MFA_REQUIRED=1).
$c->executeStatement('UPDATE "SYS_CONFIGURACION" SET "SYS_Mfa_Required" = :r WHERE "SYS_Configuracion" = 1', ['r' => '1' === $e('QA_MFA_REQUIRED') ? 1 : 0]);
$usuarios = [];
foreach ($plan as $username => [$rol, $global, $susSedes, $metodo, $debeCambiar]) {
    // Contraseña aleatoria que cumple la política (mayúscula, minúscula, dígito, símbolo, 21 caracteres).
    $clave = isset($nombresManuales[$username]) ? (string) $manuales['password'] : 'Qa'.bin2hex(random_bytes(8)).'#7x';
    $secreto = 'TOTP' === $metodo ? Totp::generarSecreto() : null;
    $correo = "{$username}@qa.inventario.local";
    $id = $c->fetchOne('SELECT "USU_Usuario" FROM "INV_USUARIO" WHERE "USU_Username" = :u', ['u' => $username]);
    $sedePersona = [] === $susSedes ? null : $sedes[$susSedes[0]];
    if (false === $id || null === $id) {
        $persona = Ident::nuevo();
        $c->executeStatement(
            'INSERT INTO "INV_PERSONA" ("PER_Persona", "PER_Primer_Nombre", "PER_Primer_Apellido", "PER_Email_Corporativo", '
            .'"PER_Estado", "DEP_Departamento", "CAR_Cargo", "SED_Sede") VALUES (:id, :n, :a, :correo, 1, :dep, :car, :sede)',
            ['id' => $persona, 'n' => 'QA', 'a' => $username, 'correo' => $correo, 'dep' => $departamento, 'car' => $cargo, 'sede' => $sedePersona],
        );
        $id = Ident::nuevo();
        $c->executeStatement(
            'INSERT INTO "INV_USUARIO" ("USU_Usuario", "USU_Username", "USU_Password_Hash", "USU_Rol", "USU_Estado", '
            .'"USU_SSO_Habilitado", "USU_Intentos_Fallidos", "USU_Bloqueos_Consecutivos", "USU_Debe_Cambiar_Password", '
            .'"USU_2FA_Habilitado", "USU_Alcance_Global", "PER_Persona") '
            .'VALUES (:id, :u, :h, :rol, 1, 0, 0, 0, 0, 0, 0, :persona)',
            ['id' => $id, 'u' => $username, 'h' => Contrasenas::hashear($clave), 'rol' => $rol, 'persona' => $persona],
        );
    }
    $c->executeStatement(
        'UPDATE "INV_USUARIO" SET "USU_Password_Hash" = :h, "USU_Rol" = :rol, "USU_Estado" = 1, "USU_Intentos_Fallidos" = 0, '
        .'"USU_Bloqueos_Consecutivos" = 0, "USU_Bloqueado_Hasta" = NULL, "USU_Debe_Cambiar_Password" = :debe, '
        .'"USU_2FA_Habilitado" = :mfa, "USU_2FA_Metodo" = :metodo, "USU_2FA_Secret" = :secreto, "USU_2FA_Ultimo_Paso" = NULL, '
        // Guías de ayuda desactivadas (su diálogo tapa la pantalla); una prueba las reactiva por la API.
        .'"USU_Alcance_Global" = :global, "USU_Preferencias" = \'{"guias_desactivadas": true}\' WHERE "USU_Usuario" = :id',
        [
            'h' => Contrasenas::hashear($clave), 'rol' => $rol, 'debe' => $debeCambiar ? 1 : 0, 'mfa' => null === $metodo ? 0 : 1,
            'metodo' => $metodo, 'secreto' => null === $secreto ? null : $fernet->cifrar($secreto), 'global' => $global ? 1 : 0, 'id' => $id,
        ],
    );
    $c->executeStatement('DELETE FROM "INV_USUARIO_SEDE" WHERE "USU_Usuario" = :id', ['id' => $id]);
    foreach ($susSedes as $s) {
        $c->executeStatement('INSERT INTO "INV_USUARIO_SEDE" ("USU_Usuario", "SED_Sede") VALUES (:u, :s)', ['u' => $id, 's' => $sedes[$s]]);
    }
    $usuarios[$username] = [
        'id' => $id, 'rol' => $rol, 'global' => $global, 'sedes' => array_map(static fn (string $s): int => $sedes[$s], $susSedes),
        'password' => $clave, 'correo' => $correo, 'metodo_2fa' => $metodo, 'secreto_totp' => $secreto, 'debe_cambiar' => $debeCambiar,
    ];
}

file_put_contents($salida, json_encode(['sedes' => $sedes, 'usuarios' => array_diff_key($usuarios, $nombresManuales)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
// Legible por el usuario sin privilegios del contenedor de las pruebas (pwuser); el archivo
// vive en api/var/qa (no versionado) y solo tiene cuentas de QA.
chmod($salida, 0644);
printf("Sembrados %d usuarios de QA en %d sedes: %s.\n", count($usuarios), count($sedes), implode(', ', array_keys($usuarios)));
