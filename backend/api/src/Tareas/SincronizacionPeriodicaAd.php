<?php

declare(strict_types=1);

namespace App\Tareas;

use App\Directorio\SincronizacionDirectorio;
use App\Integracion\FuenteIntegracion;

/**
 * Sincronización periódica con el AD. Se revisa cada minuto y el intervalo y
 * la activación se leen de la configuración efectiva en cada vuelta: se cambian
 * desde la interfaz sin reiniciar. Con varias réplicas (o el backend anterior),
 * el cerrojo `lock:job:ad_sync` evita ejecuciones dobles en el intervalo.
 */
final class SincronizacionPeriodicaAd implements TareaPeriodica
{
    private float $ultima;

    public function __construct(
        private readonly FuenteIntegracion $fuente,
        private readonly SincronizacionDirectorio $sincronizacion,
        private readonly Cerrojo $cerrojo,
    ) {
        $this->ultima = self::reloj();
    }

    public function nombre(): string
    {
        return 'ad_sync';
    }

    public function cadaSegundos(): int
    {
        return 60;
    }

    public function ejecutar(): ?array
    {
        $config = $this->fuente->obtener()->ad;
        $intervalo = $config->intervaloMinutos * 60;
        if (!$config->lista() || $intervalo <= 0 || self::reloj() - $this->ultima < $intervalo) {
            return null;
        }
        $this->ultima = self::reloj();
        if (!$this->cerrojo->adquirir('lock:job:'.$this->nombre(), max($intervalo - 5, 30))) {
            return null;
        }
        $r = $this->sincronizacion->sincronizar();

        return ['creados' => $r['creados'], 'desactivados' => $r['desactivados']];
    }

    /** Reloj monótono en segundos (no salta con cambios de hora). */
    private static function reloj(): float
    {
        return hrtime(true) / 1e9;
    }
}
