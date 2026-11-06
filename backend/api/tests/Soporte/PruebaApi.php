<?php

declare(strict_types=1);

namespace App\Tests\Soporte;

use App\Seguridad\Tokens;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/** Base de las pruebas HTTP: cliente, tokens y peticiones JSON (caja negra sobre la API). */
abstract class PruebaApi extends WebTestCase
{
    protected KernelBrowser $cliente;
    protected string $token = '';

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
    }

    /** @param array{username: string} $u */
    protected function tokenDe(array $u): string
    {
        return static::getContainer()->get(Tokens::class)->crearAcceso($u['username'], 'x');
    }

    /**
     * @param array<string, mixed>|null $cuerpo
     * @param array<string, string>     $cabeceras
     *
     * @return array{0: int, 1: mixed}
     */
    protected function pedir(string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null, array $cabeceras = []): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        $t = $token ?? $this->token;
        if ('' !== $t) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$t;
        }
        foreach ($cabeceras as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $this->cliente->request($metodo, $ruta, server: $server, content: null === $cuerpo ? '' : self::json($cuerpo));
        $r = $this->cliente->getResponse();

        return [$r->getStatusCode(), json_decode((string) $r->getContent(), true)];
    }

    /**
     * Cuerpo JSON de la prueba: `[]` es el objeto vacío `{}` (una lista en la raíz
     * es otro caso: FastAPI la rechaza con 422 `model_attributes_type`).
     *
     * @param array<string, mixed> $cuerpo
     */
    protected static function json(array $cuerpo): string
    {
        return [] === $cuerpo ? '{}' : (string) json_encode($cuerpo);
    }

    /**
     * Petición cuya respuesta es un archivo (o un formulario multipart con
     * archivos): devuelve la respuesta completa, con cabeceras y bytes.
     *
     * @param array<string, mixed>|null  $cuerpo   JSON (si no hay formulario)
     * @param array<string, string>      $campos   campos del formulario multipart
     * @param array<string, array{0: string, 1: string}> $archivos campo => [nombre del archivo, contenido]
     */
    protected function archivo(string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null, array $campos = [], array $archivos = []): Response
    {
        $server = [];
        $t = $token ?? $this->token;
        if ('' !== $t) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$t;
        }
        $subidas = [];
        foreach ($archivos as $campo => [$nombre, $contenido]) {
            $temporal = (string) tempnam(sys_get_temp_dir(), 'sub');
            file_put_contents($temporal, $contenido);
            $subidas[$campo] = new UploadedFile($temporal, $nombre, null, null, true);
        }
        if (null !== $cuerpo) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $this->cliente->request($metodo, $ruta, $campos, $subidas, $server, null === $cuerpo ? null : self::json($cuerpo));

        return $this->cliente->getResponse();
    }

    /**
     * @param array<string, mixed>|null $cuerpo
     *
     * @return array{0: int, 1: mixed}
     */
    protected function detalle(string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null): array
    {
        [$e, $r] = $this->pedir($metodo, $ruta, $cuerpo, $token);

        return [$e, \is_array($r) ? ($r['detail'] ?? null) : null];
    }

    /**
     * Exige el código HTTP y devuelve el cuerpo.
     *
     * @param array<string, mixed>|null $cuerpo
     */
    protected function ok(int $esperado, string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null): mixed
    {
        [$e, $r] = $this->pedir($metodo, $ruta, $cuerpo, $token);
        self::assertSame($esperado, $e, "{$metodo} {$ruta}: ".json_encode($r, \JSON_UNESCAPED_UNICODE));

        return $r;
    }
}
