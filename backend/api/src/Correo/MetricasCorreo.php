<?php

declare(strict_types=1);

namespace App\Correo;

/** Contadores de entrega por proceso (para /health/full y /metrics). */
final class MetricasCorreo
{
    private int $enviados = 0;
    private int $fallidos = 0;
    private ?bool $ultimaOk = null;
    private ?string $ultimoError = null;

    public function exito(): void
    {
        ++$this->enviados;
        $this->ultimaOk = true;
    }

    public function fallo(string $codigo): void
    {
        ++$this->fallidos;
        $this->ultimaOk = false;
        $this->ultimoError = $codigo;
    }

    /** @return array{configured: bool, status: string, sent_total: int, failed_total: int, last_delivery_ok: ?bool, last_error_type: ?string} */
    public function resumen(bool $configurado): array
    {
        return [
            'configured' => $configurado,
            'status' => match (true) {
                !$configurado => 'disabled',
                true === $this->ultimaOk => 'ok',
                false === $this->ultimaOk => 'down',
                default => 'unknown',
            },
            'sent_total' => $this->enviados,
            'failed_total' => $this->fallidos,
            'last_delivery_ok' => $this->ultimaOk,
            'last_error_type' => $this->ultimoError,
        ];
    }
}
