<?php

declare(strict_types=1);

namespace App\Http;

use App\Catalogo\RegistroCatalogos;
use App\Db\IdempotencyContext;
use App\Db\RowVersions;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lost-update protection for the routes marked #[Versioned]: the screen sends the ROW_VERSION it
 * loaded as If-Match ("7"), and the edit is refused with 412 if somebody saved the record in between
 * (App\Db\RowVersions). Without If-Match nothing is checked (batch edits, scripts); "*" means any version.
 *
 * It runs after the idempotency claim (App\Http\IdempotencyListener, priority -16): a retry whose first
 * attempt already applied gets the stored answer, and its version, now history, is not judged again.
 */
final readonly class RowVersionListener
{
    public const string HEADER = 'If-Match';

    public function __construct(private RowVersions $versions, private IdempotencyContext $idempotency)
    {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER, priority: -32)]
    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $marks = $event->getAttributes(Versioned::class);
        if (!$event->isMainRequest() || [] === $marks || !$request->headers->has(self::HEADER)) {
            return;
        }
        if ($request->headers->has(Idempotencia::CABECERA) && null === $this->idempotency->claim()) {
            return;
        }
        $expected = self::expectedVersion((string) $request->headers->get(self::HEADER));
        if (null === $expected) {
            return;
        }
        $mark = $marks[0];
        [$table, $key] = null === $mark->catalog
            ? [(string) $mark->table, (string) $mark->key]
            : self::catalogTable((string) $request->attributes->get($mark->catalog));
        $id = $mark->uuid ? Peticion::uuidDeRuta($request, $mark->parameter) : Peticion::enteroDeRuta($request, $mark->parameter);
        $this->versions->expect($table, $key, $id, $expected);
    }

    /**
     * `"7"`, `W/"7"` or `7` → 7; `*` → null (any version). Anything else is a client error: a list of
     * versions makes no sense for one record.
     */
    public static function expectedVersion(string $header): ?int
    {
        $value = trim($header);
        if ('*' === $value) {
            return null;
        }
        if (1 !== preg_match('/^(?:W\/)?"?([1-9]\d{0,11})"?$/', $value, $m)) {
            throw new ErrorApi(400, 'INVALID_IF_MATCH');
        }

        return (int) $m[1];
    }

    /** @return array{0: string, 1: string} */
    private static function catalogTable(string $catalog): array
    {
        $definition = RegistroCatalogos::definicion($catalog);

        return [(string) $definition['tabla'], (string) $definition['pk']];
    }
}
