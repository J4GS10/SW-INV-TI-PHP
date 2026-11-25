<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Catalogo\RegistroCatalogos;
use App\Db\RowVersions;
use App\Http\ErrorApi;
use App\Http\RowVersionListener;
use App\Http\Versioned;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Optimistic concurrency wiring: what the screen sends (If-Match) and that every route marked
 * #[Versioned] points at a table that really has ROW_VERSION and its trigger.
 */
final class RowVersionTest extends TestCase
{
    public function testIfMatchAcceptsOneVersionOrAny(): void
    {
        self::assertSame(7, RowVersionListener::expectedVersion('"7"'));
        self::assertSame(7, RowVersionListener::expectedVersion('W/"7"'));
        self::assertSame(12, RowVersionListener::expectedVersion(' 12 '));
        self::assertNull(RowVersionListener::expectedVersion('*'));
        foreach (['', '"0"', '"-1"', '"7", "8"', 'abc', '1234567890123'] as $invalid) {
            try {
                RowVersionListener::expectedVersion($invalid);
                self::fail("Se esperaba 400 con «{$invalid}»");
            } catch (ErrorApi $e) {
                self::assertSame([400, 'INVALID_IF_MATCH'], [$e->estado, $e->detalle]);
            }
        }
    }

    public function testEveryVersionedRouteIsAnEditOfAVersionedTable(): void
    {
        $marked = 0;
        foreach (glob(\dirname(__DIR__, 2).'/src/Controller/*.php') ?: [] as $file) {
            /** @var class-string $name */
            $name = 'App\\Controller\\'.basename($file, '.php');
            $class = new \ReflectionClass($name);
            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(Versioned::class) as $attribute) {
                    $versioned = $attribute->newInstance();
                    $where = $class->getShortName().'::'.$method->getName();
                    $methods = array_merge(...array_map(static fn (\ReflectionAttribute $r): array => $r->newInstance()->methods, $method->getAttributes(Route::class)));
                    self::assertNotSame([], array_intersect($methods, ['PATCH', 'PUT']), "{$where}: solo una edición lleva #[Versioned]");
                    $tables = null === $versioned->catalog ? [(string) $versioned->table]
                        : array_column(RegistroCatalogos::todos(), 'tabla');
                    foreach ($tables as $table) {
                        self::assertArrayHasKey($table, RowVersions::TABLES, "{$where}: {$table} no tiene ROW_VERSION");
                    }
                    ++$marked;
                }
            }
        }
        self::assertGreaterThanOrEqual(28, $marked, 'Las ediciones de los formularios llevan #[Versioned].');
    }

    public function testTheMigrationAddsTheColumnToEveryVersionedTable(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20261124000000.php';
        require_once \dirname(__DIR__, 2).'/migrations/Version20261125000000.php';
        $existing = (new \ReflectionClassConstant(\Migraciones\Version20261124000000::class, 'TABLAS'))->getValue();
        self::assertIsArray($existing);
        // Las tablas creadas después traen la columna desde su propio CREATE TABLE.
        $tables = [...$existing, ...\Migraciones\Version20261125000000::VERSIONADAS];
        $expected = array_keys(RowVersions::TABLES);
        sort($expected);
        sort($tables);
        self::assertSame($expected, $tables, 'El disparador TRG_RV_ de una tabla sin la columna quedaría inválido y rechazaría cada UPDATE.');
    }
}
