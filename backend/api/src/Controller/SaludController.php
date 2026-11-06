<?php

declare(strict_types=1);

namespace App\Controller;

use App\Adjuntos\Almacenes;
use App\Config\Ajustes;
use App\Correo\MetricasCorreo;
use App\Integracion\FuenteIntegracion;
use App\Seguridad\CacheAuth;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Salud y métricas. /health/full y /metrics son internos: el Caddy del
 * despliegue los bloquea en el borde (@internal_only).
 */
final readonly class SaludController
{
    public function __construct(
        private Connection $conexion,
        private Ajustes $ajustes,
        private CacheAuth $redis,
        private Almacenes $almacenes,
        private MetricasCorreo $metricasCorreo,
        private FuenteIntegracion $fuente,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/', methods: ['GET'])]
    public function raiz(): JsonResponse
    {
        return new JsonResponse(['service' => $this->ajustes->nombreProyecto, 'docs' => $this->ajustes->prefijoApi.'/docs']);
    }

    /** Liveness + ping a Oracle, para el balanceador y el healthcheck del contenedor. */
    #[Route('/health', methods: ['GET'])]
    public function salud(): JsonResponse
    {
        $bdOk = $this->baseDisponible();

        return new JsonResponse([
            'status' => $bdOk ? 'ok' : 'degraded',
            'database' => $bdOk ? 'ok' : 'down',
            'writer_probe_attempts' => 1,
            'version' => Ajustes::VERSION,
            'environment' => $this->ajustes->entorno,
        ], $bdOk ? 200 : 503);
    }

    /**
     * Salud extendida: base, Redis, almacenamiento y correo. Solo la base es
     * fatal (503); Redis, almacenamiento o correo caídos degradan con 200.
     */
    #[Route('/health/full', methods: ['GET'])]
    public function saludCompleta(): JsonResponse
    {
        $bdOk = $this->baseDisponible();
        $redis = $this->redis->disponible();
        if (false === $redis) {
            $this->logger->warning('health.redis_failed');
        }
        $almacenOk = $this->almacenes->actual()->disponible();
        if (!$almacenOk) {
            $this->logger->warning('health.storage_failed');
        }
        $correo = $this->metricasCorreo->resumen($this->fuente->obtener()->correo->lista());
        $degradado = false === $redis || !$almacenOk || 'down' === $correo['status'];

        return new JsonResponse([
            'status' => !$bdOk ? 'down' : ($degradado ? 'degraded' : 'ok'),
            'components' => [
                'database' => $bdOk ? 'ok' : 'down',
                'writer_database' => $bdOk ? 'ok' : 'down',
                'writer_probe_attempts' => 1,
                'redis' => match ($redis) { true => 'ok', null => 'disabled', false => 'down' },
                'storage' => $almacenOk ? 'ok' : 'down',
                'storage_backend' => $this->ajustes->almacenamiento,
                'smtp' => $correo['status'],
                'smtp_details' => $correo,
            ],
            'version' => Ajustes::VERSION,
            'environment' => $this->ajustes->entorno,
        ], $bdOk ? 200 : 503);
    }

    /** Métricas en formato de texto de Prometheus (sin librerías). */
    #[Route('/metrics', methods: ['GET'])]
    public function metricas(): Response
    {
        try {
            $activos = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_ACTIVO"');
            $usuarios = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_USUARIO" WHERE "USU_Estado" = 1');
            $revocados = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "SYS_TOKEN_REVOCADO"');
            $cola = $this->conexion->fetchAllKeyValue('SELECT "EOB_Estado", COUNT(*) FROM "SYS_EMAIL_OUTBOX" GROUP BY "EOB_Estado"');
        } catch (\Throwable $e) {
            $this->conexion->close();
            $this->logger->warning('metrics.query_failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);

            return new JsonResponse(['status' => 'metrics_unavailable'], 503);
        }
        $correo = $this->metricasCorreo->resumen($this->fuente->obtener()->correo->lista());
        $lineas = [
            '# HELP inv_activos_total Total de activos registrados',
            '# TYPE inv_activos_total gauge',
            "inv_activos_total {$activos}",
            '# HELP inv_usuarios_activos Total de usuarios con USU_Estado=true',
            '# TYPE inv_usuarios_activos gauge',
            "inv_usuarios_activos {$usuarios}",
            '# HELP inv_tokens_revocados Filas en SYS_TOKEN_REVOCADO (pendientes de purga)',
            '# TYPE inv_tokens_revocados gauge',
            "inv_tokens_revocados {$revocados}",
            '# HELP inv_email_outbox Correos en la cola persistente por estado',
            '# TYPE inv_email_outbox gauge',
        ];
        foreach (['PENDIENTE', 'ENVIADO', 'FALLIDO'] as $estado) {
            $lineas[] = "inv_email_outbox{estado=\"{$estado}\"} ".(int) ($cola[$estado] ?? 0);
        }
        array_push($lineas,
            '# HELP inv_storage_available Disponibilidad del object storage',
            '# TYPE inv_storage_available gauge',
            'inv_storage_available '.($this->almacenes->actual()->disponible() ? 1 : 0),
            '# HELP inv_smtp_delivery_errors_total Errores de entrega SMTP',
            '# TYPE inv_smtp_delivery_errors_total counter',
            "inv_smtp_delivery_errors_total {$correo['failed_total']}",
            '# HELP inv_smtp_delivery_sent_total Correos entregados por SMTP',
            '# TYPE inv_smtp_delivery_sent_total counter',
            "inv_smtp_delivery_sent_total {$correo['sent_total']}",
        );

        return new Response(implode("\n", $lineas)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    private function baseDisponible(): bool
    {
        try {
            $this->conexion->fetchOne('SELECT 1 FROM DUAL');

            return true;
        } catch (\Throwable $e) {
            // Una conexión rota se descarta: la siguiente petición abre otra.
            $this->conexion->close();
            $this->logger->warning('health.db_failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);

            return false;
        }
    }
}
