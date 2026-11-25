<?php

declare(strict_types=1);

namespace App\Consola;

use App\Seguridad\Tokens;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The application's own API, called from the console as a signed-in user: every request goes through the
 * same authentication, validation, rules, kardex, audit and live updates as one from the browser. Between
 * requests the services are reset, as at the end of a real request.
 */
final class ApiInterna
{
    private string $token = '';

    public function __construct(
        private readonly HttpKernelInterface $kernel,
        #[Autowire(service: 'services_resetter')] private readonly ResetInterface $resetter,
        private readonly Tokens $tokens,
    ) {
    }

    /** The following requests are made as `$username` (an existing, active account). */
    public function como(string $username, string $rol): void
    {
        $this->token = $this->tokens->crearAcceso($username, $rol);
    }

    /** @return array<int|string, mixed> */
    public function get(string $ruta): array
    {
        return $this->pedir('GET', $ruta, null, 200);
    }

    /**
     * @param array<string, mixed> $cuerpo
     *
     * @return array<int|string, mixed>
     */
    public function post(string $ruta, array $cuerpo, int $esperado = 201): array
    {
        return $this->pedir('POST', $ruta, $cuerpo, $esperado);
    }

    /**
     * @param array<string, mixed>|null $cuerpo
     *
     * @return array<int|string, mixed>
     */
    private function pedir(string $metodo, string $ruta, ?array $cuerpo, int $esperado): array
    {
        $request = Request::create('/api/v1'.$ruta, $metodo, server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'REMOTE_ADDR' => '127.0.0.1',
        ], content: null === $cuerpo ? null : (string) json_encode($cuerpo, \JSON_UNESCAPED_UNICODE));
        $response = $this->kernel->handle($request);
        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }
        $this->resetter->reset();
        $contenido = (string) $response->getContent();
        if ($response->getStatusCode() !== $esperado) {
            throw new \RuntimeException("{$metodo} {$ruta}: {$response->getStatusCode()} ".mb_substr($contenido, 0, 500));
        }
        $datos = '' === $contenido ? [] : json_decode($contenido, true);

        return \is_array($datos) ? $datos : [];
    }
}
