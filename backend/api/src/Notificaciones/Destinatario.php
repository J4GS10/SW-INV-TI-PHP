<?php

declare(strict_types=1);

namespace App\Notificaciones;

/** Un correo y por qué lo recibe (afectado, jefe, admins, grupo:<nombre>, extra). */
final readonly class Destinatario
{
    public function __construct(public string $correo, public string $origen)
    {
    }

    /** @return array{email: string, origen: string} */
    public function aApi(): array
    {
        return ['email' => $this->correo, 'origen' => $this->origen];
    }
}
