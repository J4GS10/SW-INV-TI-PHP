<?php

declare(strict_types=1);

namespace App\Http;

use App\Dominio\Reloj;
use App\Seguridad\UsuarioActual;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Comandos protegidos con `Idempotency-Key` (SYS_IDEMPOTENCY_KEY): la clave se
 * reserva de forma atómica, el comando se ejecuta una sola vez y solo se guarda
 * un resultado confirmado. Un reintento recibe la misma respuesta; uno en curso,
 * 409; la misma clave con otro usuario, otra ruta u otro cuerpo, 409.
 */
final readonly class Idempotencia
{
    public const int TTL_HORAS = 24;
    /** Más que el tiempo máximo de una petición (30 s): una reserva así de vieja ya no tiene dueño vivo. */
    public const int PENDIENTE_ABANDONADA_SEGUNDOS = 120;

    public function __construct(private Connection $conexion, private UsuarioActual $actual)
    {
    }

    /**
     * Ejecuta `$comando` (que devuelve el cuerpo JSON) bajo la clave de la petición.
     *
     * @param \Closure(): mixed $comando
     */
    public function ejecutar(Request $request, \Closure $comando, int $estado = 200): JsonResponse
    {
        $clave = $request->headers->get('Idempotency-Key');
        if (null === $clave) {
            return new JsonResponse($comando(), $estado);
        }
        if (1 !== preg_match('/^[A-Za-z0-9_\-]{16,128}$/', $clave)) {
            throw new ErrorApi(400, 'INVALID_IDEMPOTENCY_KEY (must match ^[A-Za-z0-9_-]{16,128}$)');
        }
        $cuerpo = $request->getContent();
        $peticion = [
            'clave' => $clave,
            'ruta' => $request->getPathInfo(),
            'hash' => '' === $cuerpo ? 'empty' : hash('sha256', $cuerpo),
            'usuario' => $this->actual->usuario()->id,
        ];

        $guardado = $this->reservar($peticion);
        if (null !== $guardado) {
            return new JsonResponse($guardado['cuerpo'], $guardado['estado']);
        }
        try {
            $resultado = $comando();
        } catch (\Throwable $e) {
            $this->liberar($peticion);
            throw $e;
        }
        $this->completar($peticion, $resultado, $estado);

        return new JsonResponse($resultado, $estado);
    }

    /**
     * Reserva la clave o devuelve la respuesta ya completada.
     *
     * @param array{clave: string, ruta: string, hash: string, usuario: string} $p
     *
     * @return array{cuerpo: mixed, estado: int}|null
     */
    private function reservar(array $p): ?array
    {
        for ($intento = 0; $intento < 2; ++$intento) {
            $fila = $this->conexion->fetchAssociative(
                'SELECT "IDK_Endpoint", "IDK_Usuario", "IDK_Request_Hash", "IDK_Estado", "IDK_Response_Status", '
                .'"IDK_Response_Body", "IDK_Creada_En" FROM "SYS_IDEMPOTENCY_KEY" WHERE "IDK_Key" = :k',
                ['k' => $p['clave']],
            );
            if (false === $fila) {
                try {
                    $this->conexion->executeStatement(
                        'INSERT INTO "SYS_IDEMPOTENCY_KEY" ("IDK_Key", "IDK_Endpoint", "IDK_Usuario", "IDK_Request_Hash", '
                        ."\"IDK_Estado\", \"IDK_Response_Status\", \"IDK_Creada_En\") VALUES (:k, :ruta, :u, :h, 'PENDING', 202, :ahora)",
                        ['k' => $p['clave'], 'ruta' => mb_substr($p['ruta'], 0, 200), 'u' => $p['usuario'], 'h' => $p['hash'],
                            'ahora' => Reloj::aBd(Reloj::ahora())],
                    );

                    return null;
                } catch (UniqueConstraintViolationException) {
                    continue; // otra petición ganó la reserva: se relee su estado
                }
            }
            if ((string) $fila['IDK_Usuario'] !== $p['usuario'] || $fila['IDK_Endpoint'] !== mb_substr($p['ruta'], 0, 200)) {
                throw new ErrorApi(409, 'IDEMPOTENCY_KEY_CONFLICT_DIFFERENT_OWNER');
            }
            if ($fila['IDK_Request_Hash'] !== $p['hash']) {
                throw new ErrorApi(409, 'IDEMPOTENCY_KEY_CONFLICT_DIFFERENT_BODY');
            }
            if (Reloj::desdeBd((string) $fila['IDK_Creada_En']) < Reloj::ahora()->modify('-'.self::TTL_HORAS.' hours')) {
                $this->conexion->executeStatement('DELETE FROM "SYS_IDEMPOTENCY_KEY" WHERE "IDK_Key" = :k', ['k' => $p['clave']]);
                continue;
            }

            return match ($fila['IDK_Estado']) {
                'COMPLETED' => [
                    'cuerpo' => null === $fila['IDK_Response_Body'] ? new \stdClass() : json_decode((string) $fila['IDK_Response_Body']),
                    'estado' => (int) $fila['IDK_Response_Status'],
                ],
                'FAILED' => $this->reabrir($p['clave']),
                default => $this->tomarAbandonada($p['clave'], (string) $fila['IDK_Creada_En'])
                    ? null : throw new ErrorApi(409, 'IDEMPOTENCY_REQUEST_IN_PROGRESS'),
            };
        }
        throw new ErrorApi(409, 'IDEMPOTENCY_REQUEST_IN_PROGRESS');
    }

    /**
     * Una reserva PENDING más vieja que cualquier petición viva quedó de un proceso que murió (corte,
     * tiempo máximo): se toma con una actualización condicional (gana una sola petición). Fase 8.
     */
    private function tomarAbandonada(string $clave, string $creada): bool
    {
        $limite = Reloj::ahora()->modify('-'.self::PENDIENTE_ABANDONADA_SEGUNDOS.' seconds');
        if (Reloj::desdeBd($creada) >= $limite) {
            return false;
        }

        // Tras el UPDATE de la primera, la segunda ya no cumple la condición de antigüedad (Oracle la reevalúa).
        return 1 === $this->conexion->executeStatement(
            "UPDATE \"SYS_IDEMPOTENCY_KEY\" SET \"IDK_Creada_En\" = :ahora WHERE \"IDK_Key\" = :k AND \"IDK_Estado\" = 'PENDING' AND \"IDK_Creada_En\" < :limite",
            ['k' => $clave, 'limite' => Reloj::aBd($limite), 'ahora' => Reloj::aBd(Reloj::ahora())],
        );
    }

    private function reabrir(string $clave): null
    {
        $this->conexion->executeStatement(
            "UPDATE \"SYS_IDEMPOTENCY_KEY\" SET \"IDK_Estado\" = 'PENDING', \"IDK_Response_Status\" = 202, "
            .'"IDK_Response_Body" = NULL, "IDK_Creada_En" = :ahora WHERE "IDK_Key" = :k',
            ['k' => $clave, 'ahora' => Reloj::aBd(Reloj::ahora())],
        );

        return null;
    }

    /** @param array{clave: string, ruta: string, hash: string, usuario: string} $p */
    private function completar(array $p, mixed $resultado, int $estado): void
    {
        $this->conexion->executeStatement(
            "UPDATE \"SYS_IDEMPOTENCY_KEY\" SET \"IDK_Estado\" = 'COMPLETED', \"IDK_Response_Status\" = :estado, "
            .'"IDK_Response_Body" = :cuerpo, "IDK_Creada_En" = :ahora WHERE "IDK_Key" = :k',
            ['estado' => $estado, 'cuerpo' => json_encode($resultado, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
                'ahora' => Reloj::aBd(Reloj::ahora()), 'k' => $p['clave']],
            ['cuerpo' => ParameterType::LARGE_OBJECT],
        );
    }

    /**
     * Libera una reserva cuyo comando falló (se revirtió).
     *
     * @param array{clave: string, ruta: string, hash: string, usuario: string} $p
     */
    private function liberar(array $p): void
    {
        $this->conexion->executeStatement(
            "DELETE FROM \"SYS_IDEMPOTENCY_KEY\" WHERE \"IDK_Key\" = :k AND \"IDK_Estado\" = 'PENDING' AND \"IDK_Usuario\" = :u",
            ['k' => $p['clave'], 'u' => $p['usuario']],
        );
    }
}
