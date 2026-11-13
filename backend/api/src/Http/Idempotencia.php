<?php

declare(strict_types=1);

namespace App\Http;

use App\Dominio\Reloj;
use App\Db\ClavesIdempotencia;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Almacén de `Idempotency-Key` (SYS_IDEMPOTENCY_KEY). Lo usa App\Http\IdempotencyListener
 * para TODA escritura autenticada que trae la cabecera (el frontend la envía siempre).
 *
 * Estados de una clave:
 *   PENDING   reservada; la petición está en curso.
 *   APPLIED   la operación ya confirmó su transacción (la marca va en el mismo commit:
 *             App\Db\IdempotencyCommitMiddleware) y falta guardar la respuesta.
 *   COMPLETED respuesta guardada: un reintento recibe la misma respuesta.
 * Un reintento en curso recibe 409; la misma clave con otro usuario, otra ruta u otro cuerpo, 409.
 * Una reserva PENDING de más de 2 minutos quedó de un proceso que murió sin confirmar nada:
 * se toma y se ejecuta. Una APPLIED así ya se aplicó: nunca se repite (409 IDEMPOTENCY_ALREADY_APPLIED).
 */
final readonly class Idempotencia
{
    public const string CABECERA = 'Idempotency-Key';
    public const int TTL_HORAS = 24;
    /** Más que el tiempo máximo de una petición (30 s): una reserva así de vieja ya no tiene dueño vivo. */
    public const int PENDIENTE_ABANDONADA_SEGUNDOS = 120;

    public function __construct(private ClavesIdempotencia $claves)
    {
    }

    /**
     * Reserva la clave de la petición, o devuelve la respuesta ya guardada.
     *
     * @return array{clave: string, ruta: string, hash: string, usuario: string}|JsonResponse
     */
    public function reservar(Request $request, string $usuario): array|JsonResponse
    {
        $clave = (string) $request->headers->get(self::CABECERA);
        if (1 !== preg_match('/^[A-Za-z0-9_\-]{16,128}$/', $clave)) {
            throw new ErrorApi(400, 'INVALID_IDEMPOTENCY_KEY (must match ^[A-Za-z0-9_-]{16,128}$)');
        }
        $cuerpo = $request->getContent();
        $p = ['clave' => $clave, 'ruta' => mb_substr($request->getPathInfo(), 0, 200), 'hash' => '' === $cuerpo ? 'empty' : hash('sha256', $cuerpo), 'usuario' => $usuario];

        for ($intento = 0; $intento < 2; ++$intento) {
            $fila = $this->claves->buscar($clave);
            if (false === $fila) {
                if ($this->claves->reservar($clave, $p['ruta'], $usuario, $p['hash'])) {
                    return $p;
                }
                continue; // otra petición ganó la reserva: se relee su estado
            }
            if ((string) $fila['IDK_Usuario'] !== $usuario || $fila['IDK_Endpoint'] !== $p['ruta']) {
                throw new ErrorApi(409, 'IDEMPOTENCY_KEY_CONFLICT_DIFFERENT_OWNER');
            }
            if ($fila['IDK_Request_Hash'] !== $p['hash']) {
                throw new ErrorApi(409, 'IDEMPOTENCY_KEY_CONFLICT_DIFFERENT_BODY');
            }
            $abandonada = Reloj::desdeBd((string) $fila['IDK_Creada_En']) < Reloj::ahora()->modify('-'.self::PENDIENTE_ABANDONADA_SEGUNDOS.' seconds');

            return match ($fila['IDK_Estado']) {
                'COMPLETED' => new JsonResponse(
                    null === $fila['IDK_Response_Body'] ? null : json_decode((string) $fila['IDK_Response_Body']),
                    (int) $fila['IDK_Response_Status'],
                ),
                'APPLIED' => throw new ErrorApi(409, $abandonada ? 'IDEMPOTENCY_ALREADY_APPLIED' : 'IDEMPOTENCY_REQUEST_IN_PROGRESS'),
                default => $abandonada && $this->tomarAbandonada($clave)
                    ? $p : throw new ErrorApi(409, 'IDEMPOTENCY_REQUEST_IN_PROGRESS'),
            };
        }
        throw new ErrorApi(409, 'IDEMPOTENCY_REQUEST_IN_PROGRESS');
    }

    /**
     * Guarda la respuesta. `$cuerpo` es el JSON de la respuesta, o null si no lo es (un archivo):
     * un reintento recibe entonces un cuerpo vacío con el mismo estado.
     *
     * @param array{clave: string, ruta: string, hash: string, usuario: string} $p
     */
    public function completar(array $p, int $estado, ?string $cuerpo): void
    {
        $this->claves->completar($p['clave'], $p['usuario'], $estado, $cuerpo);
    }

    /**
     * Libera la reserva de un comando que falló sin confirmar nada: se puede reintentar.
     *
     * @param array{clave: string, ruta: string, hash: string, usuario: string} $p
     */
    public function liberar(array $p): void
    {
        $this->claves->liberar($p['clave'], $p['usuario']);
    }

    /**
     * Una reserva PENDING vieja se toma con una actualización condicional: la gana una sola petición
     * (tras el UPDATE de la primera, la segunda ya no cumple la condición de antigüedad).
     */
    private function tomarAbandonada(string $clave): bool
    {
        return $this->claves->retomar($clave, Reloj::ahora()->modify('-'.self::PENDIENTE_ABANDONADA_SEGUNDOS.' seconds'));
    }
}
