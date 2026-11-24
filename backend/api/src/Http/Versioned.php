<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The route edits one record whose ROW_VERSION protects it from lost updates (App\Db\RowVersions):
 * with an If-Match header, the edit is refused with 412 if the record changed after it was loaded.
 *
 *   #[Versioned('INV_ACTIVO', 'ACT_Activo', parameter: 'activo', uuid: true)]
 *   #[Versioned(catalog: 'clave', parameter: 'item')]   generic catalogue: table from App\Catalogo\RegistroCatalogos
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Versioned
{
    public function __construct(
        public ?string $table = null,
        public ?string $key = null,
        public string $parameter = 'id',
        public bool $uuid = false,
        public ?string $catalog = null,
    ) {
        if ((null === $catalog) === (null === $table || null === $key)) {
            throw new \LogicException('Versioned needs either table and key, or catalog.');
        }
    }
}
