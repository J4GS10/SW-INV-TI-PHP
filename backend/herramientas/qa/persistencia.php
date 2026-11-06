<?php

declare(strict_types=1);

/*
 * Persistence check for QA (never production): writes real data through the API and verifies
 * that it is still there, byte for byte, after each restart/crash/restore. See persistencia.sh.
 *
 *   php persistencia.php write
 *   php persistencia.php verify <round name>
 *
 * Reads:  /qa/usuarios_manuales.json, /qa/usuarios.json (site ids)
 * Writes: /qa/evidencia/persistencia_estado.json (temporary QA users and secrets; not versioned)
 */

use App\Dominio\Ident;
use App\Seguridad\Totp;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

require '/repo/api/vendor/autoload.php';

const STATE_FILE = '/qa/evidencia/persistencia_estado.json';
const COMPANY = 'Prueba Lombardi';
const ROUNDS = 5;
const ATTACHMENT_RECOVERY_SECONDS = 180;

$env = static fn (string $k): string => (string) (getenv($k) ?: '');
if ('qa' !== $env('ENVIRONMENT')) {
    fwrite(STDERR, "persistencia.php solo corre con ENVIRONMENT=qa.\n");
    exit(2);
}

final class Api
{
    public function __construct(private string $base)
    {
    }

    /**
     * @param array<string, mixed>|null  $json
     * @param array<string, string>|null $form
     * @param array<string, string>      $headers
     *
     * @return array{0: int, 1: string}
     */
    public function call(string $method, string $path, ?string $token = null, ?array $json = null, ?array $form = null, array $headers = [], ?string $upload = null, string $uploadName = 'archivo.png', string $uploadType = 'image/png'): array
    {
        $h = curl_init($this->base.$path);
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = "{$k}: {$v}";
        }
        if (null !== $token) {
            $out[] = "Authorization: Bearer {$token}";
        }
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            // QA uses its own test certificate.
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0];
        if (null !== $json) {
            $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
            $out[] = 'Content-Type: application/json';
        } elseif (null !== $form) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($form);
        } elseif (null !== $upload) {
            $options[CURLOPT_POSTFIELDS] = ['file' => new CURLStringFile($upload, $uploadName, $uploadType)];
        }
        $options[CURLOPT_HTTPHEADER] = $out;
        curl_setopt_array($h, $options);
        $body = curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);

        return [$status, \is_string($body) ? $body : ''];
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    public function ok(int $expected, string $method, string $path, ?string $token = null, ?array $json = null, array $headers = [], ?string $upload = null): array
    {
        [$status, $body] = $this->call($method, $path, $token, $json, null, $headers, $upload);
        if ($expected !== $status) {
            throw new RuntimeException("{$method} {$path}: se esperaba {$expected} y llegó {$status}: ".mb_substr($body, 0, 300));
        }
        $data = json_decode($body, true);

        return \is_array($data) ? $data : [];
    }

    public function login(string $username, string $password): string
    {
        [$status, $body] = $this->call('POST', '/api/v1/login/access-token', form: ['username' => $username, 'password' => $password]);
        $data = json_decode($body, true);
        if (200 !== $status || !\is_string($data['access_token'] ?? null)) {
            throw new RuntimeException("Entrada de {$username}: {$status} ".mb_substr($body, 0, 200));
        }

        return $data['access_token'];
    }
}

function database(callable $env): Connection
{
    $c = DriverManager::getConnection(['driver' => 'oci8', 'host' => 'oracle', 'port' => 1521, 'dbname' => 'XEPDB1', 'servicename' => 'XEPDB1', 'service' => true,
        'user' => $env('ORACLE_SCHEMA_OWNER'), 'password' => $env('ORACLE_OWNER_PASSWORD'), 'charset' => 'AL32UTF8']);
    foreach (App\Db\SesionOracleMiddleware::sentencias('', '') as $sql) {
        $c->executeStatement($sql);
    }

    return $c;
}

function suffix(): string
{
    return bin2hex(random_bytes(4));
}

/** @return array{0: string, 1: string} username and temporary password */
function createAccount(Connection $db, Api $api, string $creatorToken, int $site, string $role): array
{
    $person = Ident::nuevo();
    $username = 'persist_'.suffix();
    $department = $db->fetchOne('SELECT MIN("DEP_Departamento") FROM "INV_DEPARTAMENTO" WHERE "DEP_Activo" = 1');
    $position = $db->fetchOne('SELECT MIN("CAR_Cargo") FROM "INV_CARGO" WHERE "CAR_Es_Jefatura" = 0');
    $db->executeStatement('INSERT INTO "INV_PERSONA" ("PER_Persona", "PER_Primer_Nombre", "PER_Primer_Apellido", "PER_Email_Corporativo", "PER_Estado", "DEP_Departamento", "CAR_Cargo", "SED_Sede") '
        .'VALUES (:id, :n, :a, :m, 1, :d, :c, :s)', ['id' => $person, 'n' => 'Persistencia', 'a' => $username, 'm' => "{$username}@qa.inventario.local", 'd' => $department, 'c' => $position, 's' => $site]);
    $password = 'Tm.'.suffix().suffix().'#9';
    $api->ok(201, 'POST', '/api/v1/org/usuarios', $creatorToken, ['USU_Username' => $username, 'USU_Password' => $password, 'USU_Rol' => $role, 'PER_Persona' => Ident::aTexto($person), 'sedes' => [$site]]);

    return [$username, $password];
}

function activate(Api $api, string $username, string $temporary): string
{
    $final = 'Ac.'.suffix().suffix().'#4';
    $api->ok(204, 'POST', '/api/v1/me/password', $api->login($username, $temporary), ['current_password' => $temporary, 'new_password' => $final]);

    return $final;
}

function requestReset(Api $api, string $username, string $mailpit): string
{
    $since = time() - 2;
    $api->ok(202, 'POST', '/api/v1/login/password-reset/request', json: ['identifier' => $username]);
    for ($i = 0; $i < 90; ++$i) {
        $found = json_decode((string) file_get_contents($mailpit.'/api/v1/search?limit=20&query='.urlencode("to:\"{$username}@qa.inventario.local\"")), true);
        foreach ($found['messages'] ?? [] as $m) {
            if (strtotime((string) $m['Created']) < $since) {
                continue;
            }
            $message = json_decode((string) file_get_contents($mailpit.'/api/v1/message/'.$m['ID']), true);
            if (1 === preg_match('/reset-password#token=([A-Za-z0-9_-]+)/', $message['Text'].' '.$message['HTML'], $match)) {
                return $match[1];
            }
        }
        sleep(1);
    }
    throw new RuntimeException("No llegó el correo de restablecimiento de {$username}.");
}

function png(int $red): string
{
    $image = imagecreatetruecolor(64, 24);
    imagefill($image, 0, 0, imagecolorallocate($image, $red, 90, 160));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function write(Api $api, Connection $db, string $mailpit): void
{
    $manual = json_decode((string) file_get_contents('/qa/usuarios_manuales.json'), true, flags: JSON_THROW_ON_ERROR);
    $password = (string) $manual['password'];
    $site = (int) json_decode((string) file_get_contents('/qa/usuarios.json'), true, flags: JSON_THROW_ON_ERROR)['sedes']['A'];
    $system = $api->login('sysadmin', $password);
    $admin = $api->login('adminti', $password);
    $buying = $api->login('compras', $password);
    $technician = $api->login('tecnico', $password);

    // Identity: name, city and an uploaded logo.
    $city = 'Ciudad '.suffix();
    $api->ok(200, 'PUT', '/api/v1/gov/config', $system, ['SYS_Nombre_Empresa' => COMPANY, 'SYS_Ciudad' => $city, 'SYS_Color_Primario' => '#123456']);
    $api->ok(200, 'POST', '/api/v1/gov/config/logo', $system, upload: png(200));
    [, $logo] = $api->call('GET', '/api/v1/gov/config/logo');

    // MFA: an account enrolled with an authenticator app and its recovery codes.
    [$mfaUser, $mfaTemporary] = createAccount($db, $api, $system, $site, 'CONSULTA');
    $mfaPassword = activate($api, $mfaUser, $mfaTemporary);
    $token = $api->login($mfaUser, $mfaPassword);
    $setup = $api->ok(200, 'POST', '/api/v1/me/2fa/totp/setup', $token);
    $secret = (string) ($setup['secret'] ?? throw new RuntimeException('Sin secreto TOTP: '.implode(',', array_keys($setup))));
    $step = intdiv(time(), 30);
    $activation = $api->ok(200, 'POST', '/api/v1/me/2fa/totp/activate', $token, ['code' => Totp::codigo($secret, $step)]);

    // Password reset: one finished, and one link left pending per round. The system allows a single
    // request per account within its anti-flood window, so each round has its own account.
    [$resetUser, $resetTemporary] = createAccount($db, $api, $system, $site, 'CONSULTA');
    activate($api, $resetUser, $resetTemporary);
    $resetPassword = 'Rs.'.suffix().suffix().'#3';
    $api->ok(204, 'POST', '/api/v1/login/password-reset/confirm', json: ['token' => requestReset($api, $resetUser, $mailpit), 'new_password' => $resetPassword]);
    $pendingResets = [];
    for ($round = 0; $round < ROUNDS; ++$round) {
        [$user, $temporary] = createAccount($db, $api, $system, $site, 'CONSULTA');
        $current = activate($api, $user, $temporary);
        $pendingResets[] = ['user' => $user, 'password' => $current, 'token' => requestReset($api, $user, $mailpit)];
    }

    // Business data: receipt with attachment, asset, kardex.
    $supplier = $api->ok(201, 'POST', '/api/v1/compras/proveedores', $admin, ['PRV_Nombre' => 'Proveedor persistencia '.suffix()])['PRV_Proveedor'];
    $tag = strtoupper(suffix());
    $db->executeStatement('INSERT INTO "INV_TIPO_ACTIVO" ("TAC_Nombre", "TAC_Prefijo", "TAC_Clase", "TAC_Aplica_Depreciacion") VALUES (:n, :p, \'COMPUTO\', 1)', ['n' => "Tipo {$tag}", 'p' => 'P'.substr($tag, 0, 4)]);
    $type = (int) $db->fetchOne('SELECT "TAC_Tipo_Activo" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Nombre" = :n', ['n' => "Tipo {$tag}"]);
    $model = $api->ok(201, 'POST', '/api/v1/goods-receipts/catalog/models', $technician, ['asset_type_id' => $type, 'brand' => "Marca {$tag}", 'model' => "Modelo {$tag}"])['model_id'];
    $today = (new DateTimeImmutable('now', new DateTimeZone('America/Guatemala')))->format('Y-m-d');
    $document = 'PERSIST-'.$tag;
    $receipt = $api->ok(201, 'POST', '/api/v1/goods-receipts', $buying, ['supplier_id' => $supplier, 'document_number' => $document, 'received_date' => $today, 'site_id' => $site, 'currency' => 'GTQ',
        'lines' => [['item_text' => 'Laptop persistencia', 'quantity' => 1, 'unit_cost' => '1000.00', 'warranty_months' => 12]]], ['Idempotency-Key' => bin2hex(random_bytes(16))]);
    $attachmentBytes = png(30);
    $attachment = $api->ok(201, 'POST', "/api/v1/adjuntos/ingresos/{$receipt['id']}", $buying, upload: $attachmentBytes);
    $attachmentId = null;
    foreach ($attachment as $key => $value) {
        if (1 === preg_match('/_Adjunto$/', $key)) {
            $attachmentId = $value;
        }
    }
    $api->ok(200, 'POST', "/api/v1/goods-receipts/{$receipt['id']}/confirm", $technician, ['lines' => [['id' => $receipt['lines'][0]['id'], 'received_quantity' => 1, 'line_type' => 'ASSET', 'asset_type_id' => $type, 'model_id' => $model]]]);
    $asset = $api->ok(200, 'GET', '/api/v1/goods-receipts/pending-units', $technician)[0]['asset_id'];
    $serial = 'SN-PERSIST-'.$tag;
    $api->ok(200, 'PATCH', "/api/v1/goods-receipts/units/{$asset}", $technician, ['serial' => $serial]);

    $state = ['city' => $city, 'logo_sha' => hash('sha256', $logo), 'mfa_user' => $mfaUser, 'mfa_password' => $mfaPassword, 'totp_secret' => $secret, 'totp_step' => $step,
        'recovery' => $activation['recovery_codes'], 'recovery_used' => 0, 'reset_user' => $resetUser, 'reset_password' => $resetPassword, 'pending_resets' => $pendingResets,
        'receipt' => $receipt['id'], 'document' => $document, 'attachment' => $attachmentId, 'attachment_sha' => hash('sha256', $attachmentBytes), 'serial' => $serial,
        'kardex' => (int) $db->fetchOne('SELECT COUNT(*) FROM "INV_KARDEX"'), 'audit' => (int) $db->fetchOne('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA"')];
    file_put_contents(STATE_FILE, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo "Datos escritos: empresa, logo, MFA ({$mfaUser}), restablecimiento ({$resetUser}), ingreso {$document}, adjunto, activo {$serial}.\n";
}

function verify(Api $api, Connection $db, string $mailpit, string $round): int
{
    $state = json_decode((string) file_get_contents(STATE_FILE), true, flags: JSON_THROW_ON_ERROR);
    $manual = json_decode((string) file_get_contents('/qa/usuarios_manuales.json'), true, flags: JSON_THROW_ON_ERROR);
    $password = (string) $manual['password'];
    $failures = 0;
    // Saved as it goes: a code or link already spent must not be reused by the next round.
    $save = static function () use (&$state): void {
        file_put_contents(STATE_FILE, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    };
    $check = static function (string $what, bool $passed, string $detail = '') use (&$failures): void {
        echo($passed ? '  OK    ' : '  FALLA ').$what.($passed || '' === $detail ? '' : " ({$detail})")."\n";
        $failures += $passed ? 0 : 1;
    };
    echo "== {$round}\n";

    $config = $api->ok(200, 'GET', '/api/v1/gov/config');
    $check('Nombre de la empresa', COMPANY === ($config['SYS_Nombre_Empresa'] ?? null), (string) ($config['SYS_Nombre_Empresa'] ?? ''));
    $check('Color de marca', '#123456' === ($config['SYS_Color_Primario'] ?? null));
    [$logoStatus, $logo] = $api->call('GET', '/api/v1/gov/config/logo');
    $check('Logo subido, idéntico byte a byte', 200 === $logoStatus && $state['logo_sha'] === hash('sha256', $logo), "HTTP {$logoStatus}");

    // MFA: the password alone must not be enough, the secret still validates, and a used code stays used.
    [$s1, $b1] = $api->call('POST', '/api/v1/login/access-token', form: ['username' => $state['mfa_user'], 'password' => $state['mfa_password']]);
    $challenge = json_decode($b1, true)['challenge_token'] ?? null;
    $check('MFA sigue exigido con la contraseña correcta', 200 === $s1 && \is_string($challenge) && !isset(json_decode($b1, true)['access_token']), "HTTP {$s1}");
    while (intdiv(time(), 30) <= $state['totp_step']) {
        sleep(2);
    }
    $state['totp_step'] = intdiv(time(), 30);
    [$s2] = $api->call('POST', '/api/v1/login/2fa/verify', json: ['challenge_token' => $challenge, 'code' => Totp::codigo($state['totp_secret'], $state['totp_step'])]);
    $check('Secreto TOTP sigue siendo válido (descifrado)', 200 === $s2, "HTTP {$s2}");
    $challengeOf = static fn (): ?string => json_decode($api->call('POST', '/api/v1/login/access-token', form: ['username' => $state['mfa_user'], 'password' => $state['mfa_password']])[1], true)['challenge_token'] ?? null;
    [$s3] = $api->call('POST', '/api/v1/login/2fa/verify', json: ['challenge_token' => $challengeOf(), 'code' => $state['recovery'][$state['recovery_used']]]);
    $check('Código de recuperación sin usar sirve', 200 === $s3, "HTTP {$s3}");
    [$s4] = $api->call('POST', '/api/v1/login/2fa/verify', json: ['challenge_token' => $challengeOf(), 'code' => $state['recovery'][$state['recovery_used']]]);
    $check('Código de recuperación usado ya no sirve', 400 === $s4, "HTTP {$s4}");
    if ($state['recovery_used'] > 0) {
        [$s5] = $api->call('POST', '/api/v1/login/2fa/verify', json: ['challenge_token' => $challengeOf(), 'code' => $state['recovery'][0]]);
        $check('Código usado antes del reinicio sigue gastado', 400 === $s5, "HTTP {$s5}");
    }
    ++$state['recovery_used'];
    $save();

    // Password reset: the finished one holds; the pending link survives and still works once.
    [$s6] = $api->call('POST', '/api/v1/login/access-token', form: ['username' => $state['reset_user'], 'password' => $state['reset_password']]);
    $check('Contraseña restablecida antes sigue vigente', 200 === $s6, "HTTP {$s6}");
    $pending = array_shift($state['pending_resets']);
    $save();
    $next = 'Rs.'.suffix().suffix().'#3';
    [$s7, $b7] = $api->call('POST', '/api/v1/login/password-reset/confirm', json: ['token' => $pending['token'], 'new_password' => $next]);
    $check('Enlace de restablecimiento pendiente sigue sirviendo', 204 === $s7, "HTTP {$s7} ".mb_substr($b7, 0, 80));
    [$s8] = $api->call('POST', '/api/v1/login/access-token', form: ['username' => $pending['user'], 'password' => $next]);
    $check('Entra con la contraseña nueva', 200 === $s8, "HTTP {$s8}");
    [$s8b] = $api->call('POST', '/api/v1/login/access-token', form: ['username' => $pending['user'], 'password' => $pending['password']]);
    $check('La contraseña anterior ya no entra', 200 !== $s8b, "HTTP {$s8b}");

    // Business data.
    $buying = $api->login('compras', $password);
    $receipt = $api->ok(200, 'GET', "/api/v1/goods-receipts/{$state['receipt']}", $buying);
    $check('Ingreso a bodega y su estado', $state['document'] === ($receipt['document_number'] ?? null) && 'COMPLETED' === ($receipt['status'] ?? null), (string) ($receipt['status'] ?? ''));
    // After a storage failure the file may take a moment to be readable again: measure how long.
    $started = time();
    do {
        [$s9, $attachment] = $api->call('GET', "/api/v1/adjuntos/{$state['attachment']}/download", $buying);
        if (200 !== $s9) {
            sleep(3);
        }
    } while (200 !== $s9 && time() - $started < ATTACHMENT_RECOVERY_SECONDS);
    $waited = time() - $started;
    $check('Adjunto idéntico byte a byte (MinIO)'.($waited > 1 ? ", disponible tras {$waited} s" : ''), 200 === $s9 && $state['attachment_sha'] === hash('sha256', $attachment), "HTTP {$s9}: ".mb_substr($attachment, 0, 120));
    [$s10, $pdf] = $api->call('GET', "/api/v1/goods-receipts/{$state['receipt']}/act.pdf", $buying);
    $check('Acta de recibido se genera', 200 === $s10 && str_starts_with($pdf, '%PDF'), "HTTP {$s10}");
    $check('Serie del activo', 1 === (int) $db->fetchOne('SELECT COUNT(*) FROM "INV_ACTIVO" WHERE "ACT_Serie_Fabricante" = :s', ['s' => $state['serial']]));
    $kardex = (int) $db->fetchOne('SELECT COUNT(*) FROM "INV_KARDEX"');
    $check('Kardex no perdió filas', $kardex >= $state['kardex'], "{$kardex} de {$state['kardex']}");
    $audit = (int) $db->fetchOne('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA"');
    $check('Auditoría no perdió filas', $audit >= $state['audit'], "{$audit} de {$state['audit']}");
    $integrity = $api->ok(200, 'GET', '/api/v1/inventario/kardex/verificar', $api->login('adminti', $password));
    $check('Cadena del kardex íntegra', true === ($integrity['integro'] ?? null));

    $state['kardex'] = $kardex;
    $state['audit'] = $audit;
    $save();

    return $failures;
}

$api = new Api($env('QA_URL') ?: 'https://qa.inventario.local');
$db = database($env);
$mailpit = $env('QA_MAILPIT') ?: 'http://mailpit:8025';
try {
    if ('write' === ($argv[1] ?? '')) {
        write($api, $db, $mailpit);
        exit(0);
    }
    exit(verify($api, $db, $mailpit, (string) ($argv[2] ?? 'comprobación')) > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: '.$e->getMessage()."\n");
    exit(3);
}
