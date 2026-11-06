<?php

declare(strict_types=1);

namespace App\Integracion;

/** Qué hace la cuenta de servicio: nada, enviar correo, o correo y consultas al Active Directory. */
enum ModoIntegracion: string
{
    case DESACTIVADO = 'DESACTIVADO';
    case CORREO = 'CORREO';
    case CORREO_AD = 'CORREO_AD';

    public function conCorreo(): bool
    {
        return self::DESACTIVADO !== $this;
    }

    public function conAd(): bool
    {
        return self::CORREO_AD === $this;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
