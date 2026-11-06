<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Entrada inválida: 422 `{"detail": "VALIDATION_ERROR", "errors": [...]}`, con
 * cada error en la forma que ya interpreta el frontend
 * (`{"type", "loc", "msg", "input"}`).
 */
final class ErrorValidacion extends \RuntimeException
{
    /** @var list<array<string, mixed>> */
    public readonly array $errores;

    /** @param list<array<string, mixed>> $errores */
    public function __construct(array $errores)
    {
        // En un `value_error` el backend anterior serializa la excepción de `ctx.error`
        // como objeto vacío: {"error": {}}. El código va en `msg` ("Value error, <CÓDIGO>").
        $this->errores = array_map(static fn (array $e): array => 'value_error' === ($e['type'] ?? null) && isset($e['ctx']['error'])
            ? [...$e, 'ctx' => [...$e['ctx'], 'error' => new \stdClass()]] : $e, $errores);
        parent::__construct('VALIDATION_ERROR', 422);
    }

    /**
     * Regla del modelo completo (`model_validator` del backend anterior): 422 con
     * `loc: ["body"]` y "Value error, <CÓDIGO>".
     */
    public static function deModelo(string $codigo, ?\Symfony\Component\HttpFoundation\Request $request = null): self
    {
        $cuerpo = null === $request ? null : json_decode($request->getContent(), true);

        return new self([['type' => 'value_error', 'loc' => ['body'], 'msg' => "Value error, {$codigo}", 'input' => $cuerpo, 'ctx' => ['error' => $codigo]]]);
    }

    /**
     * Campos obligatorios ausentes o vacíos en el cuerpo de la petición.
     *
     * @param array<string, mixed> $datos
     */
    public static function exigirCampos(array $datos, string ...$campos): void
    {
        $errores = [];
        foreach ($campos as $campo) {
            if (!isset($datos[$campo]) || '' === $datos[$campo]) {
                $errores[] = ['type' => 'missing', 'loc' => ['body', $campo], 'msg' => 'Field required', 'input' => null];
            }
        }
        if ([] !== $errores) {
            throw new self($errores);
        }
    }
}
