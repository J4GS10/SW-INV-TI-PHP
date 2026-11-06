<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\PruebaApi;

/** /health/full y /metrics: mismo contrato que el backend anterior (JSON, texto de Prometheus y códigos). */
final class SaludTest extends PruebaApi
{
    public function testSaludCompletaConLosComponentesDelContrato(): void
    {
        @mkdir(\dirname(__DIR__, 2).'/var/adjuntos_pruebas', 0o777, true);
        [$estado, $r] = $this->pedir('GET', '/health/full', token: '');
        self::assertSame(200, $estado);
        self::assertSame(['status', 'components', 'version', 'environment'], array_keys($r));
        self::assertSame(['database', 'writer_database', 'writer_probe_attempts', 'redis', 'storage', 'storage_backend', 'smtp', 'smtp_details'],
            array_keys($r['components']));
        self::assertSame(['ok', 'ok', 1, 'disabled', 'ok', 'local'], [$r['components']['database'], $r['components']['writer_database'],
            $r['components']['writer_probe_attempts'], $r['components']['redis'], $r['components']['storage'], $r['components']['storage_backend']]);
        self::assertSame(['configured', 'status', 'sent_total', 'failed_total', 'last_delivery_ok', 'last_error_type'], array_keys($r['components']['smtp_details']));
        self::assertTrue($r['components']['smtp_details']['configured']);
        self::assertSame('ok', $r['status'], 'Sin Redis configurado no es una falla.');
    }

    public function testMetricasEnFormatoPrometheus(): void
    {
        $respuesta = $this->archivo('GET', '/metrics', token: '');
        self::assertSame(200, $respuesta->getStatusCode());
        self::assertSame('text/plain; version=0.0.4; charset=utf-8', $respuesta->headers->get('Content-Type'));
        $texto = (string) $respuesta->getContent();
        self::assertStringEndsWith("\n", $texto);
        $activos = D::contar('SELECT COUNT(*) FROM "INV_ACTIVO"');
        self::assertStringContainsString("# TYPE inv_activos_total gauge\ninv_activos_total {$activos}\n", $texto);
        foreach (['PENDIENTE', 'ENVIADO', 'FALLIDO'] as $estado) {
            self::assertMatchesRegularExpression('/^inv_email_outbox\{estado="'.$estado.'"\} \d+$/m', $texto);
        }
        $metricas = array_values(array_filter(explode("\n", $texto), static fn (string $l): bool => '' !== $l && !str_starts_with($l, '#')));
        self::assertSame(['inv_activos_total', 'inv_usuarios_activos', 'inv_tokens_revocados', 'inv_email_outbox', 'inv_email_outbox', 'inv_email_outbox',
            'inv_storage_available', 'inv_smtp_delivery_errors_total', 'inv_smtp_delivery_sent_total'],
            array_map(static fn (string $l): string => (string) preg_replace('/[{ ].*$/', '', $l), $metricas));
    }
}
