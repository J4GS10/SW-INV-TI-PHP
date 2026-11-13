<?php

declare(strict_types=1);

namespace App\Catalogo;

use App\Catalogo\Infrastructure\CatalogoTecnicoStore;
use App\Crud\Operaciones;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Origen;
use App\Inventario\Infrastructure\DatosActivos;

/** Qué especificaciones pide cada tipo de activo (RAM y disco a una laptop, pulgadas a un monitor), en qué orden y con qué valores permitidos. */
final readonly class EspecificacionesPorTipo
{
    public function __construct(
        private CatalogoTecnicoStore $datos,
        private DatosActivos $activos,
        private SpecificationRules $reglas,
        private Operaciones $crud,
        private Transaccion $transaccion,
    ) {
    }

    /** @return array<int, list<array<string, mixed>>> tipo de activo => sus especificaciones, en orden */
    public function todas(): array
    {
        $porTipo = [];
        foreach ($this->datos->especificacionesPorTipo(null) as $f) {
            $porTipo[(string) (int) $f['TAC_Tipo_Activo']][] = $this->aApi($f);
        }

        return $porTipo;
    }

    /** @return list<array<string, mixed>> */
    public function de(int $tipo): array
    {
        return array_map($this->aApi(...), $this->datos->especificacionesPorTipo($tipo));
    }

    /**
     * Reemplaza la lista de especificaciones que pide el tipo, en el orden recibido, con los valores permitidos de cada una.
     *
     * @param list<array{TES_Tipo_Especificacion: int, obligatoria: bool, valores: list<string>}> $items
     *
     * @return list<array<string, mixed>> la lista como queda
     */
    public function fijar(int $tipo, array $items, Origen $origen): array
    {
        if (!$this->datos->existeTipoDeActivo($tipo)) {
            throw ErrorDominio::noEncontrado('ASSET_TYPE_NOT_FOUND');
        }
        $ids = array_column($items, 'TES_Tipo_Especificacion');
        if (\count(array_unique($ids)) !== \count($ids)) {
            throw ErrorDominio::noProcesable('DUPLICATED_SPECIFICATION');
        }
        foreach ($ids as $id) {
            if (!$this->activos->existeTipoEspecificacion($id)) {
                throw ErrorDominio::noEncontrado('SPECIFICATION_TYPE_NOT_FOUND');
            }
        }
        $this->transaccion->ejecutar(function () use ($tipo, $items, $origen): void {
            $this->reglas->removeOptionsOfType($tipo);
            $this->datos->quitarEspecificacionesDeTipo($tipo);
            foreach ($items as $orden => $item) {
                $this->datos->agregarEspecificacionATipo($tipo, $item['TES_Tipo_Especificacion'], $item['obligatoria'], ($orden + 1) * 10);
                $this->reglas->replaceOptions($tipo, $item['TES_Tipo_Especificacion'], $item['valores']);
            }
            $this->crud->auditar('UPDATE', 'INV_ESPECIFICACION_TIPO_ACTIVO', ['tipo' => $tipo, 'especificaciones' => $items], $origen);
        });

        return $this->de($tipo);
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private function aApi(array $f): array
    {
        return [
            'TES_Tipo_Especificacion' => (int) $f['TES_Tipo_Especificacion'],
            'nombre' => $f['TES_Nombre'],
            'unidad' => $f['TES_Unidad_Medida'],
            'obligatoria' => 1 === (int) $f['ETA_Obligatoria'],
            'valores' => $this->reglas->options((int) $f['TAC_Tipo_Activo'], (int) $f['TES_Tipo_Especificacion']),
            'tipo_dato' => $f['TES_Data_Type'],
            'minimo' => null === $f['TES_Min_Value'] ? null : (float) $f['TES_Min_Value'],
            'maximo' => null === $f['TES_Max_Value'] ? null : (float) $f['TES_Max_Value'],
            'orden' => (int) $f['ETA_Orden'],
        ];
    }
}
