<?php

declare(strict_types=1);

namespace App\Tests\Soporte;

/** Cliente mínimo de la API de Mailpit (servidor SMTP de pruebas en la red de pruebas). */
final class Mailpit
{
    private const string API = 'http://mailpit:8025/api/v1';

    public static function vaciar(): void
    {
        self::pedir('DELETE', '/messages');
    }

    /**
     * @return list<array{ID: string, Subject: string, To: list<array{Address: string}>, From: array{Address: string, Name: string}}>
     *
     * @phpstan-impure
     */
    public static function mensajes(): array
    {
        return self::pedir('GET', '/messages')['messages'] ?? [];
    }

    /**
     * Mensajes completos enviados a un destinatario, más recientes primero.
     *
     * @return list<array<string, mixed>>
     *
     * @phpstan-impure
     */
    public static function para(string $correo): array
    {
        $resultado = self::pedir('GET', '/search?query='.rawurlencode("to:\"{$correo}\""));

        return array_map(static fn (array $m): array => self::mensaje((string) $m['ID']), $resultado['messages'] ?? []);
    }

    /** @return array<string, mixed> mensaje completo (HTML, Text, cabeceras) */
    public static function mensaje(string $id): array
    {
        return self::pedir('GET', "/message/{$id}");
    }

    /**
     * @param array{To: list<array{Address: string}>} $mensaje
     *
     * @return list<string>
     */
    public static function destinatarios(array $mensaje): array
    {
        return array_map(static fn (array $d): string => $d['Address'], $mensaje['To']);
    }

    /** @return array<string, mixed> */
    private static function pedir(string $metodo, string $ruta): array
    {
        $contexto = stream_context_create(['http' => ['method' => $metodo, 'timeout' => 5, 'ignore_errors' => true]]);
        $cuerpo = file_get_contents(self::API.$ruta, false, $contexto);
        if (false === $cuerpo) {
            throw new \RuntimeException('Mailpit no responde (¿se levantó con herramientas/pruebas.sh?).');
        }

        return '' === $cuerpo ? [] : (array) json_decode($cuerpo, true);
    }
}
