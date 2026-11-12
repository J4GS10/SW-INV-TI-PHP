<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Soporte\Capas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the layers apart (App\Tests\Soporte\Capas explains them). The files that broke a rule
 * when it was written are listed in architecture-baseline.php: that list can only shrink.
 *   - a file outside the list breaks a rule      → the test fails: the new code mixes layers;
 *   - a file in the list no longer breaks it     → the test fails: take it off the list, so it stays clean.
 * After a refactor, regenerate the list with:
 *   php tests/Unit/architecture-baseline.php --write
 */
final class ArchitectureTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function rules(): iterable
    {
        foreach (array_keys(Capas::REGLAS) as $rule) {
            yield $rule => [$rule];
        }
    }

    #[DataProvider('rules')]
    public function testNoNewFileBreaksTheRuleAndTheKnownOnesStayListed(string $rule): void
    {
        /** @var array<string, list<string>> $known */
        $known = require __DIR__.'/architecture-baseline.php';
        $now = Capas::infracciones(\dirname(__DIR__, 2).'/src')[$rule];

        self::assertSame([], array_values(array_diff($now, $known[$rule] ?? [])),
            "These files break «{$rule}» and are not in the list of known ones: move the dependency to its layer.");
        self::assertSame([], array_values(array_diff($known[$rule] ?? [], $now)),
            "These files no longer break «{$rule}»: remove them from tests/Unit/architecture-baseline.php.");
    }

    public function testEveryFileBelongsToALayer(): void
    {
        self::assertSame(['entrega', 'datos', 'dominio', 'negocio', 'negocio', 'datos'], array_map(Capas::capa(...), [
            'Controller/CoreController.php', 'GoodsReceipt/Infrastructure/GoodsReceiptRepository.php', 'Dominio/Ident.php',
            'Inventario/Movimientos.php', 'GoodsReceipt/Application/GoodsReceiptService.php', 'Db/LogicalDelete.php',
        ]));
    }
}
