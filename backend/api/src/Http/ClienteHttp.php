<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/** Datos del cliente para auditoría y límites (misma política que el backend anterior). */
final class ClienteHttp
{
    /**
     * IP real: X-Real-IP (la fija el proxy con la IP del peer) > ÚLTIMO valor de
     * X-Forwarded-For (el que añadió el proxy más cercano; el primero lo puede
     * inyectar el cliente) > IP del socket.
     */
    public static function ip(Request $request): ?string
    {
        $real = trim((string) $request->headers->get('x-real-ip', ''));
        if ('' !== $real) {
            return $real;
        }
        $saltos = array_values(array_filter(array_map('trim', explode(',', (string) $request->headers->get('x-forwarded-for', '')))));
        if ([] !== $saltos) {
            return $saltos[\count($saltos) - 1];
        }

        return $request->server->get('REMOTE_ADDR');
    }

    public static function userAgent(Request $request): ?string
    {
        $ua = $request->headers->get('user-agent');

        return null === $ua || '' === $ua ? null : mb_substr($ua, 0, 255);
    }
}
