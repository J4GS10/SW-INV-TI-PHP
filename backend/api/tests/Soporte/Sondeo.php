<?php

declare(strict_types=1);

namespace App\Tests\Soporte;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sondeo de entradas mal escritas (SONDEO_ENTRADAS=<archivo>): antes de cada petición de la
 * suite se envía la misma petición cambiando un solo dato por un valor inválido (letras en una
 * fecha o un monto, lista en lugar de texto, número enorme, texto de 5000 caracteres, id que no
 * es id…). Ninguna puede terminar en 5xx. Cada combinación (ruta, campo, valor) se prueba una
 * vez; las respuestas 5xx y las 2xx (dato inválido aceptado) quedan en el archivo, una línea JSON
 * por caso. Lo usa ClienteSondeo, el cliente HTTP de las pruebas.
 */
final class Sondeo
{
    /** @var array<string, true> */
    private static array $vistos = [];
    private static bool $dentro = false;

    public static function activo(): bool
    {
        return '' !== (string) getenv('SONDEO_ENTRADAS') && !self::$dentro;
    }

    /**
     * @param \Closure(Request): Response $enviar
     * @param \Closure(): void            $vaciarLimites
     */
    public static function antes(Request $original, \Closure $enviar, \Closure $vaciarLimites): void
    {
        $camino = $original->getPathInfo();
        $metodo = $original->getMethod();
        // Sin cuerpo JSON de objeto (multipart, binario) no se sondea el cuerpo; cerrar o revocar sesiones cortaría la prueba.
        if (str_contains($camino, '/logout') || str_contains($camino, '/revocar') || [] !== $original->files->all()) {
            return;
        }
        $contenido = (string) $original->getContent();
        $cuerpo = '' === trim($contenido) ? null : json_decode($contenido, true);
        if ('' !== trim($contenido) && !\is_array($cuerpo)) {
            return;
        }
        self::$dentro = true;
        try {
            $plantilla = $metodo.' '.self::plantilla($camino);
            $consulta = $original->query->all();
            $base = static fn (string $c, array $q, ?array $b): Request => self::copia($original, $c, $q, $b, $contenido);
            foreach (self::segmentosId($camino) as $i => $_) {
                foreach (['abc', '-1', '99999999999999999999', '1.5', ' '] as $malo) {
                    $partes = explode('/', $camino);
                    $partes[$i] = rawurlencode($malo);
                    self::probar($enviar, $vaciarLimites, $plantilla, "path[{$i}]", $malo, $metodo, $base(implode('/', $partes), $consulta, $cuerpo));
                }
            }
            foreach (array_keys($consulta) as $clave) {
                foreach (['abc', '-1', '', '2026-02-30', str_repeat('9', 40), str_repeat('x', 5000), ['x']] as $malo) {
                    self::probar($enviar, $vaciarLimites, $plantilla, "query.{$clave}", $malo, $metodo, $base($camino, [...$consulta, $clave => $malo], $cuerpo));
                }
            }
            if (\is_array($cuerpo) && 'GET' !== $metodo) {
                foreach (self::hojas($cuerpo) as [$ubicacion, $valor]) {
                    foreach (self::malos($valor) as $malo) {
                        self::probar($enviar, $vaciarLimites, $plantilla, 'body.'.implode('.', $ubicacion), $malo, $metodo,
                            $base($camino, $consulta, self::cambiar($cuerpo, $ubicacion, $malo)));
                    }
                }
            }
        } finally {
            self::$dentro = false;
        }
    }

    /**
     * @param array<string, mixed>         $consulta
     * @param array<array-key, mixed>|null $cuerpo
     */
    private static function copia(Request $original, string $camino, array $consulta, ?array $cuerpo, string $contenidoOriginal): Request
    {
        $contenido = null === $cuerpo ? $contenidoOriginal : ([] === $cuerpo ? '{}' : (string) json_encode($cuerpo));
        $uri = $original->getSchemeAndHttpHost().$camino.([] === $consulta ? '' : '?'.http_build_query($consulta));

        return Request::create($uri, $original->getMethod(), [], $original->cookies->all(), [], $original->server->all(), $contenido);
    }

    /**
     * @param \Closure(Request): Response $enviar
     * @param \Closure(): void            $vaciarLimites
     */
    private static function probar(\Closure $enviar, \Closure $vaciarLimites, string $plantilla, string $campo, mixed $malo, string $metodo, Request $peticion): void
    {
        $clave = $plantilla.'|'.preg_replace('/\.\d+(?=\.|$)/', '.n', $campo).'|'.json_encode($malo, \JSON_INVALID_UTF8_SUBSTITUTE);
        if (isset(self::$vistos[$clave])) {
            return;
        }
        self::$vistos[$clave] = true;
        $respuesta = $enviar($peticion);
        if (429 === $respuesta->getStatusCode()) {
            $vaciarLimites();
            $respuesta = $enviar($peticion);
        }
        $estado = $respuesta->getStatusCode();
        $tipo = match (true) {
            $estado >= 500 => 'FALLA',
            $estado < 300 && 'GET' !== $metodo => 'ACEPTADO',
            default => null,
        };
        if (null === $tipo) {
            return;
        }
        $linea = ['tipo' => $tipo, 'ruta' => $plantilla, 'campo' => $campo,
            'valor' => \is_string($malo) && \strlen($malo) > 60 ? 'texto de '.\strlen($malo) : $malo,
            'estado' => $estado, 'respuesta' => mb_substr((string) $respuesta->getContent(), 0, 300)];
        file_put_contents((string) getenv('SONDEO_ENTRADAS'),
            json_encode($linea, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE)."\n", \FILE_APPEND);
    }

    /**
     * Valores inválidos para el tipo de la hoja.
     *
     * @return list<mixed>
     */
    private static function malos(mixed $valor): array
    {
        $comunes = [['x'], ['a' => 1]];

        return match (true) {
            \is_bool($valor) => ['abc', 2, ...$comunes],
            \is_int($valor), \is_float($valor) => ['abc', '12abc', '', 1e30, -1e30, true, ...$comunes],
            \is_string($valor) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}/', $valor) => ['abc', '2026-02-30', '31/12/2026', '2026-13-01T25:61:00', 20261231, ...$comunes],
            \is_string($valor) && 1 === preg_match('/^-?\d+(\.\d+)?$/', $valor) => ['abc', '1,5', '1e3', 'NaN', str_repeat('9', 40), ...$comunes],
            \is_string($valor) && 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $valor) => ['abc', '00000000-0000-0000-0000-00000000000g', 123, ...$comunes],
            \is_string($valor) => [123, true, str_repeat('x', 5000), "a\u{0000}b", ...$comunes],
            \is_array($valor) && array_is_list($valor) => ['abc', 123, ['a' => 1], [[]], ['abc']],
            \is_array($valor) => ['abc', 123, ['x', 'y']],
            default => [],
        };
    }

    /**
     * Hojas del cuerpo: [ubicación, valor]. De las listas solo se recorre el primer elemento.
     *
     * @param array<array-key, mixed> $datos
     * @param list<string|int>        $base
     *
     * @return list<array{0: list<string|int>, 1: mixed}>
     */
    private static function hojas(array $datos, array $base = []): array
    {
        $hojas = [];
        $esLista = array_is_list($datos);
        foreach ($datos as $k => $v) {
            if ($esLista && 0 !== $k) {
                break;
            }
            if (null === $v) {
                continue;
            }
            $ubicacion = [...$base, $k];
            $hojas[] = [$ubicacion, $v];
            if (\is_array($v) && [] !== $v) {
                array_push($hojas, ...self::hojas($v, $ubicacion));
            }
        }

        return $hojas;
    }

    /**
     * @param array<array-key, mixed> $datos
     * @param list<string|int>        $ubicacion
     *
     * @return array<array-key, mixed>
     */
    private static function cambiar(array $datos, array $ubicacion, mixed $valor): array
    {
        $k = array_shift($ubicacion);
        $datos[$k] = [] === $ubicacion ? $valor : self::cambiar((array) $datos[$k], $ubicacion, $valor);

        return $datos;
    }

    private static function plantilla(string $camino): string
    {
        return (string) preg_replace(['#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=/|$)#i', '#/[0-9a-f]{32}(?=/|$)#i', '#/\d+(?=/|$)#'],
            ['/{uuid}', '/{uuid}', '/{n}'], $camino);
    }

    /** @return array<int, string> índice del segmento => segmento (ids numéricos o UUID) */
    private static function segmentosId(string $camino): array
    {
        return array_filter(explode('/', $camino), static fn (string $s): bool => 1 === preg_match('/^(\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32})$/i', $s));
    }
}
