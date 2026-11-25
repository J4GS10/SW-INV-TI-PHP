<?php

declare(strict_types=1);

namespace App\Consola;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Registers the sample assets through the API: unique serial, a hostname for computers, invoice and supplier in
 * rotation, three years of warranty and the cost of its model. Components are then installed in their equipment.
 * Built by DemoDataCommand with the catalogue it just created: not a container service.
 */
#[Exclude]
final class AltaMuestra
{
    private int $secuencia = 0;

    /**
     * @param array<string, array{modelo: int, tipo: int, costo: string, moneda: string, clase: string}> $modelos
     * @param list<int>                                                                                  $proveedores
     */
    public function __construct(
        private readonly ApiInterna $api,
        private readonly array $modelos,
        private readonly array $proveedores,
        private readonly int $enBodega,
    ) {
    }

    /** @return string the new asset's id */
    public function crear(string $modelo, int $sede, string $fecha): string
    {
        $m = $this->modelos[$modelo];
        $n = ++$this->secuencia;
        $compra = new \DateTimeImmutable($fecha);
        $activo = $this->api->post('/core/activos', [
            'ACT_Serie_Fabricante' => \sprintf('DEMO-%s-%04d', strtoupper(strtok($modelo, '_') ?: 'X'), $n),
            'ACT_Hostname' => 'COMPUTO' === $m['clase'] ? \sprintf('EQ-%04d', $n) : null,
            'ACT_Fecha_Compra' => $fecha, 'ACT_Fin_Garantia' => $compra->modify('+3 years')->format('Y-m-d'),
            'ACT_Costo' => (float) $m['costo'], 'ACT_Moneda' => $m['moneda'],
            'ACT_Factura' => \sprintf('FAC-%s-%04d', $compra->format('Y'), 100 + $n), 'PRV_Proveedor' => $this->proveedores[$n % \count($this->proveedores)],
            'MOD_Modelo' => $m['modelo'], 'TAC_Tipo_Activo' => $m['tipo'], 'EOP_Estado_Operativo' => $this->enBodega, 'SED_Sede' => $sede,
        ]);

        return (string) $activo['ACT_Activo'];
    }

    public function instalar(string $equipo, string $componente): void
    {
        $this->api->post("/core/activos/{$equipo}/componentes", ['ACT_Componente' => $componente, 'motivo' => 'Configuración inicial del equipo'], 200);
    }

    public function creados(): int
    {
        return $this->secuencia;
    }
}
