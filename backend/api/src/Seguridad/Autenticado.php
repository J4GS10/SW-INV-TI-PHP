<?php

declare(strict_types=1);

namespace App\Seguridad;

/**
 * Exige un access token válido para la acción del controlador.
 *
 *   #[Autenticado]                                   cualquier usuario autenticado
 *   #[Autenticado(roles: Roles::ADMIN)]              además, uno de esos roles
 *   #[Autenticado(permitirRestringida: true)]        autoservicio (/me…): admite
 *                                                    sesiones con un paso pendiente
 *
 * Repetible y acumulable: el del controlador (grupo de roles del módulo) y el
 * de la acción (escritura, aprobación…) se exigen TODOS a la vez.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Autenticado
{
    /** @param list<string> $roles */
    public function __construct(
        public array $roles = [],
        public bool $permitirRestringida = false,
    ) {
    }
}
