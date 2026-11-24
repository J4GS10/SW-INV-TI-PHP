<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Db\ContextoRls;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\PruebaApi;

/**
 * Exactly-once writes (Idempotency-Key on every authenticated write), logical deletes
 * (ROW ARCHIVAL, the database refuses a physical DELETE) and the administrative lock.
 */
final class ConsistencyTest extends PruebaApi
{
    protected function tearDown(): void
    {
        static::getContainer()->get(ContextoRls::class)->reset();
        parent::tearDown();
    }

    public function testAWriteWithTheSameKeyRunsOnceAndReturnsTheSameAnswer(): void
    {
        $token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));
        $name = 'Proveedor idempotente '.D::sufijo();
        $key = 'k'.bin2hex(random_bytes(12));

        [$first, $created] = $this->pedir('POST', '/api/v1/compras/proveedores', ['PRV_Nombre' => $name], $token, ['Idempotency-Key' => $key]);
        [$again, $replayed] = $this->pedir('POST', '/api/v1/compras/proveedores', ['PRV_Nombre' => $name], $token, ['Idempotency-Key' => $key]);

        self::assertSame([201, 201], [$first, $again]);
        self::assertSame($created, $replayed, 'The retry gets the stored answer.');
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_PROVEEDOR" WHERE "PRV_Nombre" = :n', ['n' => $name]), 'It ran once.');
        self::assertSame('COMPLETED', D::conexion()->fetchOne('SELECT "IDK_Estado" FROM "SYS_IDEMPOTENCY_KEY" WHERE "IDK_Key" = :k', ['k' => $key]));
        // The same key with another body is a conflict; without a key the duplicate name is refused (it really exists once).
        self::assertSame([409, 'IDEMPOTENCY_KEY_CONFLICT_DIFFERENT_BODY'],
            $this->detalleCon('POST', '/api/v1/compras/proveedores', ['PRV_Nombre' => $name.' otro'], $token, $key));
        self::assertSame(409, $this->pedir('POST', '/api/v1/compras/proveedores', ['PRV_Nombre' => $name], $token)[0]);
    }

    public function testAFailedWriteReleasesItsKeyAndAnAppliedOneIsNeverRepeated(): void
    {
        $token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));
        $key = 'k'.bin2hex(random_bytes(12));
        // 422 without committing anything: the key is free again for the corrected request.
        self::assertSame(422, $this->pedir('POST', '/api/v1/compras/proveedores', ['PRV_Nombre' => 'x'], $token, ['Idempotency-Key' => $key])[0]);
        self::assertFalse(D::conexion()->fetchOne('SELECT 1 FROM "SYS_IDEMPOTENCY_KEY" WHERE "IDK_Key" = :k', ['k' => $key]));

        // A process that died after its commit and before storing the answer: the key stays APPLIED and a retry is refused.
        $name = 'Proveedor aplicado '.D::sufijo();
        $applied = 'k'.bin2hex(random_bytes(12));
        self::assertSame(201, $this->pedir('POST', '/api/v1/compras/proveedores', ['PRV_Nombre' => $name], $token, ['Idempotency-Key' => $applied])[0]);
        D::conexion()->executeStatement("UPDATE \"SYS_IDEMPOTENCY_KEY\" SET \"IDK_Estado\" = 'APPLIED', \"IDK_Response_Body\" = NULL, \"IDK_Creada_En\" = :t WHERE \"IDK_Key\" = :k",
            ['k' => $applied, 't' => Reloj::aBd(Reloj::ahora()->modify('-10 minutes'))]);
        self::assertSame([409, 'IDEMPOTENCY_ALREADY_APPLIED'], $this->detalleCon('POST', '/api/v1/compras/proveedores', ['PRV_Nombre' => $name], $token, $applied));
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_PROVEEDOR" WHERE "PRV_Nombre" = :n', ['n' => $name]));
    }

    public function testDeletingArchivesTheRecordAndThePhysicalDeleteIsRefused(): void
    {
        $admin = D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]);
        $token = $this->tokenDe($admin);
        $created = $this->ok(201, 'POST', '/api/v1/compras/proveedores', ['PRV_Nombre' => 'Proveedor archivado '.D::sufijo()], $token);
        $id = (int) $created['PRV_Proveedor'];

        $this->ok(204, 'DELETE', "/api/v1/compras/proveedores/{$id}", null, $token);
        self::assertSame(404, $this->pedir('GET', "/api/v1/compras/proveedores/{$id}", null, $token)[0], 'Nobody sees it any more.');
        self::assertSame(404, $this->pedir('DELETE', "/api/v1/compras/proveedores/{$id}", null, $token)[0]);
        $c = D::conexion();
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "INV_PROVEEDOR" WHERE "PRV_Proveedor" = :id', ['id' => $id]));
        $c->executeStatement('ALTER SESSION SET ROW ARCHIVAL VISIBILITY = ALL');
        try {
            self::assertSame('1', $c->fetchOne('SELECT ORA_ARCHIVE_STATE FROM "INV_PROVEEDOR" WHERE "PRV_Proveedor" = :id', ['id' => $id]), 'The row is kept, archived.');
        } finally {
            $c->executeStatement('ALTER SESSION SET ROW ARCHIVAL VISIBILITY = ACTIVE');
        }

        // During a request (user set) the database refuses a physical DELETE on a business table.
        $other = $this->ok(201, 'POST', '/api/v1/compras/proveedores', ['PRV_Nombre' => 'Proveedor intocable '.D::sufijo()], $token);
        $rls = static::getContainer()->get(ContextoRls::class);
        $rls->fijarUsuario($admin['id']);
        try {
            static::getContainer()->get(\Doctrine\DBAL\Connection::class)
                ->executeStatement('DELETE FROM "INV_PROVEEDOR" WHERE "PRV_Proveedor" = :id', ['id' => (int) $other['PRV_Proveedor']]);
            self::fail('The physical DELETE should have been refused.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('ORA-20006', $e->getMessage());
        } finally {
            $rls->limpiar();
        }
    }

    public function testTheSystemAdministratorLocksAndUnlocksAnAccount(): void
    {
        $sa = D::crearUsuario(['rol' => 'SUPER_ADMIN']);
        $token = $this->tokenDe($sa);
        $target = D::crearUsuario(['rol' => 'TECNICO']);
        $route = '/api/v1/org/usuarios/'.Ident::aTexto($target['id']);
        $targetToken = $this->tokenDe($target);
        self::assertSame(200, $this->pedir('GET', '/api/v1/me', null, $targetToken)[0]);

        // Bad data first: a reason made of spaces, a past date and an impossible one.
        self::assertSame([422, 'LOCK_REASON_REQUIRED'], $this->detalle('POST', "{$route}/lock", ['reason' => '       '], $token));
        self::assertSame([422, 'LOCK_UNTIL_MUST_BE_FUTURE'], $this->detalle('POST', "{$route}/lock", ['reason' => 'Prueba de bloqueo', 'until' => '2020-01-01'], $token));
        self::assertSame([422, 'INVALID_PARAMETER_VALUE'], $this->detalle('POST', "{$route}/lock", ['reason' => 'Prueba de bloqueo', 'until' => '2099-02-31'], $token));
        self::assertSame(403, $this->pedir('POST', '/api/v1/org/usuarios/'.Ident::aTexto($sa['id']).'/lock', ['reason' => 'A mí mismo'], $token)[0]);

        $this->ok(200, 'POST', "{$route}/lock", ['reason' => 'Prueba de bloqueo'], $token);
        self::assertSame(401, $this->pedir('GET', '/api/v1/me', null, $targetToken)[0], 'An open session ends at once.');
        self::assertSame([429, 'ACCOUNT_LOCKED'], $this->login($target['username'], D::PASSWORD));
        $listed = array_values(array_filter($this->ok(200, 'GET', '/api/v1/org/usuarios', null, $token),
            static fn (array $u): bool => $u['USU_Username'] === $target['username']))[0];
        self::assertSame(['Prueba de bloqueo', null], [$listed['bloqueo_admin_motivo'], $listed['bloqueo_admin_hasta']]);

        $this->ok(200, 'POST', "{$route}/unlock", null, $token);
        self::assertSame(200, $this->login($target['username'], D::PASSWORD)[0]);
    }

    public function testNamesIdentifiersAndPurchaseDatesRefuseJunk(): void
    {
        $token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));
        $refused = function (string $route, array $body) use ($token): array {
            [$status, $answer] = $this->pedir('POST', $route, $body, $token);
            self::assertSame(422, $status);
            $types = [];
            foreach ($answer['errors'] as $error) {
                $types[$error['loc'][1]] = $error['type'];
            }

            return $types;
        };

        $asset = $refused('/api/v1/core/activos', ['ACT_Serie_Fabricante' => '<img src=x onerror=alert(1)>', 'ACT_Hostname' => 'PC 01;DROP',
            'ACT_Fecha_Compra' => Reloj::ahora()->modify('+3 days')->format('Y-m-d')]);
        self::assertSame(['string_pattern_mismatch', 'string_pattern_mismatch', 'date_in_the_future'],
            [$asset['ACT_Serie_Fabricante'], $asset['ACT_Hostname'], $asset['ACT_Fecha_Compra']]);
        // What a label really carries is accepted: today's date, dashes, slashes, dots, accents.
        $valid = $refused('/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'SN-5CG/123.4 (B) Ñ', 'ACT_Hostname' => 'gt-lap_001', 'ACT_Fecha_Compra' => Reloj::ahora()->format('Y-m-d')]);
        self::assertArrayNotHasKey('ACT_Serie_Fabricante', $valid);
        self::assertArrayNotHasKey('ACT_Hostname', $valid);
        self::assertArrayNotHasKey('ACT_Fecha_Compra', $valid);

        $person = $refused('/api/v1/org/personas', ['PER_Primer_Nombre' => '<script>alert(1)</script>', 'PER_Primer_Apellido' => 'Pérez=1+1', 'PER_Segundo_Apellido' => "O'Connor-Müller"]);
        self::assertSame(['string_pattern_mismatch', 'string_pattern_mismatch'], [$person['PER_Primer_Nombre'], $person['PER_Primer_Apellido']]);
        self::assertArrayNotHasKey('PER_Segundo_Apellido', $person, 'Apostrophes, hyphens and any alphabet are real names.');
    }

    public function testAnEditMadeOnAStaleVersionIsRefusedAndChangesNothing(): void
    {
        $token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));
        [, $supplier] = $this->pedir('POST', '/api/v1/compras/proveedores', ['PRV_Nombre' => 'Proveedor versionado '.D::sufijo()], $token);
        $route = "/api/v1/compras/proveedores/{$supplier['PRV_Proveedor']}";
        $loaded = $supplier['row_version'];
        $contact = static fn (): mixed => D::conexion()->fetchOne('SELECT "PRV_Contacto" FROM "INV_PROVEEDOR" WHERE "PRV_Proveedor" = :p', ['p' => $supplier['PRV_Proveedor']]);

        // Two people opened the same record: the first one saves, the second one is told instead of undoing that change.
        [$first, $saved] = $this->pedir('PATCH', $route, ['PRV_Contacto' => 'Ana'], $token, ['If-Match' => "\"{$loaded}\""]);
        self::assertSame([200, $loaded + 1], [$first, $saved['row_version']]);
        self::assertSame([412, 'RECORD_CHANGED_BY_ANOTHER_USER'], $this->conCabeceras('PATCH', $route, ['PRV_Contacto' => 'Beto'], $token, ['If-Match' => "\"{$loaded}\""]));
        self::assertSame('Ana', $contact());

        // Any write moves the version, not only the forms (here, one made straight in the database).
        D::conexion()->executeStatement('UPDATE "INV_PROVEEDOR" SET "PRV_Telefono" = :t WHERE "PRV_Proveedor" = :p', ['t' => '5550101', 'p' => $supplier['PRV_Proveedor']]);
        self::assertSame(412, $this->pedir('PATCH', $route, ['PRV_Contacto' => 'Beto'], $token, ['If-Match' => "\"{$saved['row_version']}\""])[0]);
        $current = $saved['row_version'] + 1;
        self::assertSame(200, $this->pedir('PATCH', $route, ['PRV_Contacto' => 'Beto'], $token, ['If-Match' => "\"{$current}\""])[0]);
        self::assertSame('Beto', $contact());

        // A retry of an edit that already applied gets its stored answer: its version is history, not a conflict.
        $headers = ['If-Match' => '"'.($current + 1).'"', 'Idempotency-Key' => 'k'.bin2hex(random_bytes(12))];
        [$applied, $answer] = $this->pedir('PATCH', $route, ['PRV_Contacto' => 'Carla'], $token, $headers);
        self::assertSame([200, [200, $answer]], [$applied, $this->pedir('PATCH', $route, ['PRV_Contacto' => 'Carla'], $token, $headers)]);

        // Without If-Match nothing is checked (scripts, batch edits); a malformed one is the client's error.
        self::assertSame(200, $this->pedir('PATCH', $route, ['PRV_Contacto' => 'Dora'], $token)[0]);
        self::assertSame([400, 'INVALID_IF_MATCH'], $this->conCabeceras('PATCH', $route, ['PRV_Contacto' => 'Eva'], $token, ['If-Match' => 'ayer']));
        self::assertSame('Dora', $contact());
    }

    public function testRoutineWritesDoNotRejectAnOpenForm(): void
    {
        $token = $this->tokenDe(D::crearUsuario(['rol' => 'SUPER_ADMIN', 'global' => true]));
        $target = D::crearUsuario(['rol' => 'CONSULTA', 'global' => true]);
        $route = '/api/v1/org/usuarios/'.Ident::aTexto($target['id']);
        [, $user] = $this->pedir('GET', $route, null, $token);
        // Logging in and failing a password are not edits of the account.
        D::conexion()->executeStatement('UPDATE "INV_USUARIO" SET "USU_Ultimo_Login" = SYSTIMESTAMP, "USU_Intentos_Fallidos" = 1 WHERE "USU_Usuario" = :u', ['u' => $target['id']]);
        self::assertSame(200, $this->pedir('PATCH', $route, ['USU_Rol' => 'CONSULTA'], $token, ['If-Match' => "\"{$user['row_version']}\""])[0]);

        // The generic catalogues are versioned through their definition.
        $token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));
        [, $item] = $this->pedir('POST', '/api/v1/catalogos/tipos-area', ['nombre' => 'Área versionada '.D::sufijo()], $token);
        $route = "/api/v1/catalogos/tipos-area/{$item['id']}";
        self::assertSame(200, $this->pedir('PATCH', $route, ['descripcion' => 'uno'], $token, ['If-Match' => "\"{$item['row_version']}\""])[0]);
        self::assertSame([412, 'RECORD_CHANGED_BY_ANOTHER_USER'], $this->conCabeceras('PATCH', $route, ['descripcion' => 'dos'], $token, ['If-Match' => "\"{$item['row_version']}\""]));
    }

    public function testTheVersionIsCheckedAgainUnderLockBeforeTheWrite(): void
    {
        // Somebody saves between the first check (when the request arrives) and this request's own write.
        $token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));
        [, $supplier] = $this->pedir('POST', '/api/v1/compras/proveedores', ['PRV_Nombre' => 'Proveedor bloqueado '.D::sufijo()], $token);
        $versions = static::getContainer()->get(\App\Db\RowVersions::class);
        $versions->expect('INV_PROVEEDOR', 'PRV_Proveedor', $supplier['PRV_Proveedor'], $supplier['row_version']);
        D::conexion()->executeStatement('UPDATE "INV_PROVEEDOR" SET "PRV_Contacto" = :c WHERE "PRV_Proveedor" = :p', ['c' => 'Otro', 'p' => $supplier['PRV_Proveedor']]);

        $wrote = false;
        try {
            static::getContainer()->get(\App\Db\Transaccion::class)->ejecutar(static function () use (&$wrote): void {
                $wrote = true;
            });
            self::fail('Se esperaba RowChangedByAnotherUser');
        } catch (\App\Db\RowChangedByAnotherUser $e) {
            self::assertSame([$supplier['row_version'], $supplier['row_version'] + 1], [$e->expected, $e->current]);
        }
        self::assertFalse($wrote, 'The edit never ran.');
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: mixed}
     */
    private function conCabeceras(string $method, string $route, array $body, string $token, array $headers): array
    {
        [$status, $answer] = $this->pedir($method, $route, $body, $token, $headers);

        return [$status, \is_array($answer) ? ($answer['detail'] ?? null) : null];
    }

    /** @return array{0: int, 1: mixed} */
    private function login(string $username, string $password): array
    {
        $this->cliente->request('POST', '/api/v1/login/access-token', ['username' => $username, 'password' => $password]);
        $body = json_decode((string) $this->cliente->getResponse()->getContent(), true);

        return [$this->cliente->getResponse()->getStatusCode(), \is_array($body) ? ($body['detail'] ?? null) : null];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: mixed}
     */
    private function detalleCon(string $method, string $route, array $body, string $token, string $key): array
    {
        [$status, $answer] = $this->pedir($method, $route, $body, $token, ['Idempotency-Key' => $key]);

        return [$status, \is_array($answer) ? ($answer['detail'] ?? null) : null];
    }
}
