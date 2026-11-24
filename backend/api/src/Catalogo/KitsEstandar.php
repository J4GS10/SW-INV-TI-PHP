<?php

declare(strict_types=1);

namespace App\Catalogo;

use App\Catalogo\Infrastructure\CatalogoTecnicoStore;
use App\Crud\Operaciones;
use App\Db\LogicalDelete;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Origen;

/**
 * Kits estándar de puesto: lo que se entrega a quien ocupa un cargo o entra a un departamento (equipos por tipo
 * y licencias). Un kit es de un cargo o de un departamento, nunca de ambos, y solo uno puede estar activo para cada uno.
 */
final readonly class KitsEstandar
{
    public function __construct(
        private CatalogoTecnicoStore $datos,
        private Operaciones $crud,
        private Transaccion $transaccion,
        private LogicalDelete $borrado,
    ) {
    }

    /** @return list<array<string, mixed>> todos los kits, por nombre, con sus renglones: dos consultas, haya los kits que haya */
    public function listar(): array
    {
        $items = $this->datos->itemsDeTodosLosKits();

        return array_map(static fn (array $k): array => self::aApi($k, $items[(int) $k['KIT_Kit']] ?? []), $this->datos->kits());
    }

    /** @return array<string, mixed>|null el kit con sus renglones */
    public function obtener(int $id): ?array
    {
        $k = $this->datos->kit($id);

        return false === $k ? null : self::aApi($k, $this->datos->itemsDeKit($id));
    }

    /**
     * @param array<string, mixed>       $k
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private static function aApi(array $k, array $items): array
    {
        $entero = static fn (mixed $v): ?int => null === $v ? null : (int) $v;

        return [
            'KIT_Kit' => (int) $k['KIT_Kit'], 'KIT_Nombre' => $k['KIT_Nombre'], 'CAR_Cargo' => $entero($k['CAR_Cargo']),
            'DEP_Departamento' => $entero($k['DEP_Departamento']), 'KIT_Activo' => 1 === (int) $k['KIT_Activo'],
            'items' => array_map(static fn (array $i): array => ['KIT_Item' => (int) $i['KIT_Item'], 'TAC_Tipo_Activo' => $entero($i['TAC_Tipo_Activo']),
                'KIT_Cantidad' => (int) $i['KIT_Cantidad'], 'LIC_Licencia' => $entero($i['LIC_Licencia'])], $items),
            ...\App\Crud\Esquema::version($k),
        ];
    }

    /**
     * @param array{KIT_Nombre: string, CAR_Cargo: ?int, DEP_Departamento: ?int, KIT_Activo: bool, items: list<array<string, mixed>>} $datos
     *
     * @return array<string, mixed> el kit creado
     */
    public function crear(array $datos, Origen $origen): array
    {
        $this->validar($datos, null);
        $id = $this->transaccion->ejecutar(function () use ($datos, $origen): int {
            $id = $this->datos->crearKit($datos['KIT_Nombre'], $datos['CAR_Cargo'], $datos['DEP_Departamento'], $datos['KIT_Activo']);
            $this->datos->reemplazarItemsDeKit($id, $datos['items']);
            $this->crud->auditar('CREATE', 'INV_KIT_ESTANDAR', ['id' => $id, ...$datos], $origen);

            return $id;
        });

        return (array) $this->obtener($id);
    }

    /**
     * @param array{KIT_Nombre: string, CAR_Cargo: ?int, DEP_Departamento: ?int, KIT_Activo: bool, items: list<array<string, mixed>>} $datos
     *
     * @return array<string, mixed> el kit como queda
     */
    public function editar(int $id, array $datos, Origen $origen): array
    {
        $this->obtener($id) ?? throw ErrorDominio::noEncontrado('STANDARD_KIT_NOT_FOUND');
        $this->validar($datos, $id);
        $this->transaccion->ejecutar(function () use ($id, $datos, $origen): void {
            $this->datos->actualizarKit($id, $datos['KIT_Nombre'], $datos['CAR_Cargo'], $datos['DEP_Departamento'], $datos['KIT_Activo']);
            $this->datos->reemplazarItemsDeKit($id, $datos['items']);
            $this->crud->auditar('UPDATE', 'INV_KIT_ESTANDAR', ['id' => $id, ...$datos], $origen);
        });

        return (array) $this->obtener($id);
    }

    /** El kit se archiva; su composición queda en la bitácora (los renglones son vínculos y se quitan con él). */
    public function borrar(int $id, Origen $origen): void
    {
        $kit = $this->obtener($id) ?? throw ErrorDominio::noEncontrado('STANDARD_KIT_NOT_FOUND');
        $this->transaccion->ejecutar(function () use ($id, $kit, $origen): void {
            $this->borrado->archive('INV_KIT_ESTANDAR', 'KIT_Kit', $id);
            $this->crud->auditar('DELETE', 'INV_KIT_ESTANDAR', ['id' => $id, 'nombre' => $kit['KIT_Nombre'], 'items' => $kit['items'] ?? []], $origen);
        });
    }

    /** @param array{KIT_Nombre: string, CAR_Cargo: ?int, DEP_Departamento: ?int, KIT_Activo: bool, items: list<array<string, mixed>>} $datos */
    private function validar(array $datos, ?int $excepto): void
    {
        [$cargo, $departamento] = [$datos['CAR_Cargo'], $datos['DEP_Departamento']];
        if ((null === $cargo) === (null === $departamento)) {
            throw ErrorDominio::noProcesable('STANDARD_KIT_NEEDS_POSITION_OR_DEPARTMENT');
        }
        if (null !== $cargo && !$this->datos->existeCargo($cargo)) {
            throw ErrorDominio::noEncontrado('POSITION_NOT_FOUND');
        }
        if (null !== $departamento && !$this->datos->existeDepartamento($departamento)) {
            throw ErrorDominio::noEncontrado('DEPARTMENT_NOT_FOUND');
        }
        // Un solo kit activo por cargo o por departamento.
        if ($datos['KIT_Activo'] && $this->datos->hayKitActivoPara($cargo, $departamento, $excepto)) {
            throw ErrorDominio::conflicto('STANDARD_KIT_ALREADY_EXISTS');
        }
        if ([] === $datos['items']) {
            throw ErrorDominio::noProcesable('STANDARD_KIT_EMPTY');
        }
    }
}
