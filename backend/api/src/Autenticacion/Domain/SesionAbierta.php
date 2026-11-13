<?php

declare(strict_types=1);

namespace App\Autenticacion\Domain;

/**
 * A session that was just opened or renewed: what the client receives in the body, the refresh token
 * that must travel apart from it and, when the browser asked to be remembered, its mark.
 */
final readonly class SesionAbierta
{
    /**
     * @param array{access_token: string, token_type: string, expires_in: int, restriction: ?string} $cuerpo
     * @param ?string                                                                               $marcaEquipo mark of the remembered browser, with `$marcaExpira`
     */
    public function __construct(
        public string $usuarioId,
        public array $cuerpo,
        public string $refresh,
        public ?string $marcaEquipo = null,
        public ?\DateTimeImmutable $marcaExpira = null,
    ) {
    }
}
