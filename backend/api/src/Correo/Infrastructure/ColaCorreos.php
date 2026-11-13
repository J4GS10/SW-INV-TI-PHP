<?php

declare(strict_types=1);

namespace App\Correo\Infrastructure;

use App\Correo\CorreoEnCola;
use App\Dominio\Reloj;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Persistencia de la cola SYS_EMAIL_OUTBOX para el worker. Varios consumidores
 * (réplicas del worker) drenan la cola sin enviar
 * dos veces el mismo correo: el reclamo toma el lote con FOR UPDATE SKIP LOCKED
 * y fija un arriendo (próximo intento = ahora + arriendo) en una transacción
 * corta; nadie más lo toma hasta que el arriendo vence.
 */
final readonly class ColaCorreos
{
    public function __construct(private Connection $conexion)
    {
    }

    /**
     * Deja un correo PENDIENTE en la cola, listo para el primer intento.
     *
     * @param array{id: string, plantilla: string, asunto: string, html: string, para: ?string, afectados: ?string, cc: bool, resolver: bool, reply: ?string} $correo `para` y `afectados` en JSON
     */
    public function encolar(array $correo, \DateTimeImmutable $ahora): void
    {
        $this->conexion->executeStatement(
            'INSERT INTO "SYS_EMAIL_OUTBOX" ("EOB_Id", "EOB_Plantilla", "EOB_Asunto", "EOB_Html", "EOB_Para", '
            .'"EOB_Afectados", "EOB_Cc_Admins", "EOB_Resolver", "EOB_Reply_To", "EOB_Estado", "EOB_Intentos", '
            .'"EOB_Proximo_Intento", "EOB_Creado_En") VALUES (:id, :plantilla, :asunto, :html, :para, :afectados, '
            .":cc, :resolver, :reply, 'PENDIENTE', 0, :ahora, :ahora)",
            [...$correo, 'cc' => $correo['cc'] ? 1 : 0, 'resolver' => $correo['resolver'] ? 1 : 0, 'ahora' => Reloj::aBd($ahora)],
            ['html' => ParameterType::LARGE_OBJECT, 'para' => ParameterType::LARGE_OBJECT, 'afectados' => ParameterType::LARGE_OBJECT],
        );
    }

    /**
     * Reclama hasta `$lote` correos vencidos: suma un intento y fija el arriendo.
     *
     * @return list<string> ids reclamados
     */
    public function reclamar(int $lote, int $arriendoSegundos, int $maxIntentos = \PHP_INT_MAX): array
    {
        return $this->conexion->transactional(function () use ($lote, $arriendoSegundos, $maxIntentos): array {
            $ahora = Reloj::ahora();
            // Agotó los intentos sin cerrarse (el proceso murió al entregarlo): no se reclama sin fin, queda FALLIDO (fase 8).
            $this->conexion->executeStatement(
                'UPDATE "SYS_EMAIL_OUTBOX" SET "EOB_Estado" = :fallido, "EOB_Ultimo_Error" = NVL("EOB_Ultimo_Error", :motivo) '
                .'WHERE "EOB_Estado" = :pendiente AND "EOB_Intentos" >= :max AND "EOB_Proximo_Intento" <= :ahora',
                ['fallido' => CorreoEnCola::FALLIDO, 'motivo' => 'Intentos agotados sin confirmar la entrega', 'pendiente' => CorreoEnCola::PENDIENTE,
                    'max' => $maxIntentos, 'ahora' => Reloj::aBd($ahora)],
            );
            // Oracle no admite FETCH FIRST con FOR UPDATE (ORA-02014): el lote se
            // elige en una subconsulta y el bloqueo, saltando lo que otro tomó, afuera.
            $ids = $this->conexion->fetchFirstColumn(
                'SELECT "EOB_Id" FROM "SYS_EMAIL_OUTBOX" WHERE "EOB_Id" IN ('
                .'SELECT "EOB_Id" FROM "SYS_EMAIL_OUTBOX" WHERE "EOB_Estado" = :pendiente AND "EOB_Proximo_Intento" <= :ahora AND "EOB_Intentos" < :max '
                .'ORDER BY "EOB_Proximo_Intento" FETCH FIRST '.$lote.' ROWS ONLY) FOR UPDATE SKIP LOCKED',
                ['pendiente' => CorreoEnCola::PENDIENTE, 'ahora' => Reloj::aBd($ahora), 'max' => $maxIntentos],
            );
            $ids = array_map('strval', $ids);
            if ([] !== $ids) {
                $this->conexion->executeStatement(
                    'UPDATE "SYS_EMAIL_OUTBOX" SET "EOB_Proximo_Intento" = :arriendo, "EOB_Intentos" = "EOB_Intentos" + 1 WHERE "EOB_Id" IN (:ids)',
                    ['arriendo' => Reloj::aBd($ahora->modify("+{$arriendoSegundos} seconds")), 'ids' => $ids],
                    ['ids' => ArrayParameterType::STRING],
                );
            }

            return $ids;
        });
    }

    public function cargar(string $id): ?CorreoEnCola
    {
        $fila = $this->conexion->fetchAssociative('SELECT * FROM "SYS_EMAIL_OUTBOX" WHERE "EOB_Id" = :id', ['id' => $id]);

        return false === $fila ? null : CorreoEnCola::desdeFila($fila);
    }

    /** Guarda el estado del correo tras un intento (en su propia transacción). */
    public function guardar(CorreoEnCola $c): void
    {
        $this->conexion->executeStatement(
            'UPDATE "SYS_EMAIL_OUTBOX" SET "EOB_Para" = :para, "EOB_Resolver" = :resolver, "EOB_Estado" = :estado, '
            .'"EOB_Intentos" = :intentos, "EOB_Proximo_Intento" = :proximo, "EOB_Ultimo_Error" = :error, "EOB_Enviado_En" = :enviado '
            .'WHERE "EOB_Id" = :id',
            [
                'para' => json_encode($c->para, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
                'resolver' => $c->resolver ? 1 : 0,
                'estado' => $c->estado,
                'intentos' => $c->intentos,
                'proximo' => Reloj::aBd($c->proximoIntento),
                'error' => null === $c->ultimoError ? null : mb_substr($c->ultimoError, 0, 500),
                'enviado' => Reloj::aBd($c->enviadoEn),
                'id' => $c->id,
            ],
            ['para' => ParameterType::LARGE_OBJECT],
        );
    }

    /**
     * Correos PENDIENTES que esperaban por una clave rechazada (cortacircuitos):
     * se programan para ahora.
     */
    public function reanudarRetenidos(): int
    {
        return $this->conexion->executeStatement(
            'UPDATE "SYS_EMAIL_OUTBOX" SET "EOB_Proximo_Intento" = :ahora WHERE "EOB_Estado" = :pendiente AND "EOB_Ultimo_Error" LIKE \'AUTH_%\'',
            ['ahora' => Reloj::aBd(Reloj::ahora()), 'pendiente' => CorreoEnCola::PENDIENTE],
        );
    }

    /** @return array<string, int> state => how many e-mails of the queue are in it */
    public function conteosPorEstado(): array
    {
        return array_map('intval', $this->conexion->fetchAllKeyValue('SELECT "EOB_Estado", COUNT(*) FROM "SYS_EMAIL_OUTBOX" GROUP BY "EOB_Estado"'));
    }

    /** @return mixed when the oldest e-mail still waiting was queued; null when none waits */
    public function pendienteMasAntiguo(): mixed
    {
        $fecha = $this->conexion->fetchOne("SELECT MIN(\"EOB_Creado_En\") FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Estado\" = 'PENDIENTE'");

        return false === $fecha ? null : $fecha;
    }

    /** @return list<array<string, mixed>> the last e-mails that could not be delivered, newest first */
    public function ultimosFallidos(int $cuantos): array
    {
        return $this->conexion->fetchAllAssociative(
            'SELECT "EOB_Id", "EOB_Plantilla", "EOB_Asunto", "EOB_Para", "EOB_Intentos", "EOB_Ultimo_Error", "EOB_Creado_En" '
            ."FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Estado\" = 'FALLIDO' ORDER BY \"EOB_Creado_En\" DESC FETCH FIRST ".$cuantos.' ROWS ONLY');
    }

    /** Puts back in the queue one failed e-mail (or, without `$id`, all of them); returns how many. The worker takes them on its next poll. */
    public function reencolarFallidos(?string $id, \DateTimeImmutable $ahora): int
    {
        $sql = "UPDATE \"SYS_EMAIL_OUTBOX\" SET \"EOB_Estado\" = 'PENDIENTE', \"EOB_Intentos\" = 0, \"EOB_Proximo_Intento\" = :ahora WHERE \"EOB_Estado\" = 'FALLIDO'";
        $parametros = ['ahora' => Reloj::aBd($ahora)];
        if (null !== $id) {
            $sql .= ' AND "EOB_Id" = :id';
            $parametros['id'] = $id;
        }

        return (int) $this->conexion->executeStatement($sql, $parametros);
    }
}
