<?php

declare(strict_types=1);

namespace App\Directorio;

use App\Dominio\Reloj;

/** Conteos y avisos de una sincronización con el directorio. */
final class ResultadoSincronizacion
{
    public const string BAJAS_MASIVAS_RETENIDAS = 'AD_SYNC_MASS_DEACTIVATION_BLOCKED';

    public readonly string $fecha;
    public int $creados = 0;
    public int $actualizados = 0;
    public int $vinculados = 0;
    public int $desactivados = 0;
    public int $reactivados = 0;
    public int $jefesAsignados = 0;
    /** @var list<array{persona_id: string, nombre: string, email: string, activos: int}> */
    public array $pendientesConActivos = [];
    /** @var list<array{persona_id: string, nombre: string, email: string, motivo: string}> */
    public array $protegidos = [];
    /** @var list<array{email: ?string, detalle: string}> */
    public array $errores = [];
    public int $bajasRetenidas = 0;
    public ?string $advertencia = null;

    public function __construct(public readonly bool $simulacion, public readonly int $totalAd)
    {
        $this->fecha = (string) Reloj::iso(Reloj::ahora());
    }

    public function error(?string $correo, string $detalle): void
    {
        $this->errores[] = ['email' => $correo, 'detalle' => $detalle];
    }

    /**
     * Todo lo calculado, también los protegidos (se guarda en la bitácora).
     *
     * @return array<string, mixed>
     */
    public function aBitacora(): array
    {
        return [
            'fecha' => $this->fecha, 'dry_run' => $this->simulacion, 'total_ad' => $this->totalAd,
            'creados' => $this->creados, 'actualizados' => $this->actualizados, 'vinculados' => $this->vinculados,
            'desactivados' => $this->desactivados, 'reactivados' => $this->reactivados, 'jefes_asignados' => $this->jefesAsignados,
            'pendientes_con_activos' => $this->pendientesConActivos, 'protegidos' => $this->protegidos, 'errores' => $this->errores,
            'bajas_retenidas' => $this->bajasRetenidas, 'advertencia' => $this->advertencia,
        ];
    }

    /** @return array<string, mixed> */
    public function aApi(): array
    {
        return self::contrato($this->aBitacora());
    }

    /**
     * Forma pública de un resultado (también de uno guardado en la bitácora):
     * los campos del contrato, en su orden.
     *
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    public static function contrato(array $r): array
    {
        $entero = static fn (string $c): int => (int) ($r[$c] ?? 0);

        return [
            'fecha' => (string) ($r['fecha'] ?? ''), 'dry_run' => (bool) ($r['dry_run'] ?? false), 'total_ad' => $entero('total_ad'),
            'creados' => $entero('creados'), 'actualizados' => $entero('actualizados'), 'vinculados' => $entero('vinculados'),
            'desactivados' => $entero('desactivados'), 'reactivados' => $entero('reactivados'), 'jefes_asignados' => $entero('jefes_asignados'),
            'pendientes_con_activos' => array_map(static fn (array $p): array => [
                'persona_id' => (string) $p['persona_id'], 'nombre' => (string) $p['nombre'], 'email' => (string) $p['email'], 'activos' => (int) $p['activos'],
            ], (array) ($r['pendientes_con_activos'] ?? [])),
            'errores' => array_map(static fn (array $e): array => ['email' => $e['email'] ?? null, 'detalle' => (string) $e['detalle']],
                (array) ($r['errores'] ?? [])),
            'bajas_retenidas' => $entero('bajas_retenidas'),
            'advertencia' => $r['advertencia'] ?? null,
        ];
    }
}
