<?php

declare(strict_types=1);

namespace App\Catalogo;

use App\Catalogo\EntidadesTecnicas as T;
use App\Catalogo\Infrastructure\CatalogoTecnicoStore;
use App\Crud\Operaciones;
use App\Db\RecordInUse;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Origen;

/**
 * Reglas del catálogo técnico que van más allá de guardar un registro: un tipo de activo nace con la plantilla
 * de especificaciones de su clase, solo admite los componentes que su clase permite, y los nombres no se repiten.
 */
final readonly class CatalogoTecnico
{
    /**
     * Estados de los que depende la máquina de estados de los activos: no se
     * pueden renombrar ni borrar.
     */
    private const array ESTADOS_DEL_SISTEMA = ['disponible', 'asignado', 'en reparación', 'en reparacion', 'baja', 'en bodega'];

    public function __construct(
        private CatalogoTecnicoStore $datos,
        private Operaciones $crud,
        private PlantillaEspecificaciones $plantilla,
        private Transaccion $transaccion,
    ) {
    }

    /**
     * Crea un tipo de activo con la plantilla de especificaciones de su clase; sin clase indicada se deduce del nombre.
     *
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed> el tipo con las reglas de su clase
     */
    public function crearTipo(array $d, Origen $origen): array
    {
        if ($this->datos->existeTipoDeActivoLlamado($d['TAC_Nombre'])) {
            throw ErrorDominio::invalido('ASSET_TYPE_ALREADY_EXISTS');
        }
        $d['TAC_Clase'] ??= ClasesActivo::porNombre($d['TAC_Nombre']);

        return self::conReglas($this->transaccion->ejecutar(function () use ($d, $origen): array {
            $id = $this->crud->repositorio->insertar(T::tipoActivo(), $d);
            $this->plantilla->aplicar((int) $id);
            $this->crud->auditar('CREATE', 'INV_TIPO_ACTIVO', ['nombre' => $d['TAC_Nombre']], $origen);

            return (array) $this->crud->repositorio->obtener(T::tipoActivo(), $id);
        }));
    }

    /**
     * Cambio parcial de un tipo; si cambia de clase, recibe la plantilla de la nueva.
     *
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed> el tipo con las reglas de su clase
     */
    public function cambiarTipo(int $id, array $cambios, Origen $origen): array
    {
        $this->tipo($id);

        return self::conReglas($this->transaccion->ejecutar(function () use ($id, $cambios, $origen): array {
            $this->crud->repositorio->actualizar(T::tipoActivo(), $id, $cambios);
            if (!empty($cambios['TAC_Clase'])) {
                $this->plantilla->aplicar($id);
            }
            $this->crud->auditar('UPDATE', 'INV_TIPO_ACTIVO', ['id' => $id, 'cambios' => (object) $cambios], $origen);

            return (array) $this->crud->repositorio->obtener(T::tipoActivo(), $id);
        }));
    }

    /** @return array<string, mixed> el tipo con las reglas de su clase */
    public function tipoConReglas(int $id): array
    {
        return self::conReglas($this->tipo($id));
    }

    /** @return list<array<string, mixed>> todos los tipos, cada uno con las reglas de su clase */
    public function tiposConReglas(): array
    {
        return array_map(self::conReglas(...), $this->crud->repositorio->listar(T::tipoActivo()));
    }

    /** @return list<int> los tipos que el tipo admite como componentes internos (vacío = cualquiera de clase Componente) */
    public function componentesDeTipo(int $id): array
    {
        $this->tipo($id);

        return $this->datos->componentesDe($id);
    }

    /**
     * @param list<int> $pedidos tipos que se quieren admitir como componentes; el propio tipo y los repetidos se ignoran
     *
     * @return list<int> los que quedaron, en orden
     */
    public function fijarComponentesDeTipo(int $id, array $pedidos, Origen $origen): array
    {
        $tipo = $this->tipo($id);
        $componentes = array_values(array_diff(array_unique($pedidos), [$id]));
        sort($componentes);
        if ([] !== $componentes && !ClasesActivo::reglas($tipo['TAC_Clase'])['componentes']) {
            throw ErrorDominio::noProcesable('ASSET_CLASS_NO_COMPONENTS');
        }
        foreach ($componentes as $c) {
            $this->tipo($c);
        }
        $this->transaccion->ejecutar(function () use ($id, $tipo, $componentes, $origen): void {
            $antes = $this->datos->componentesDe($id);
            $this->datos->reemplazarComponentes($id, $componentes);
            $this->crud->auditar('UPDATE', 'INV_TIPO_COMPONENTE', ['tipo' => $tipo['TAC_Nombre'], 'antes' => $antes, 'despues' => $componentes], $origen);
        });

        return $componentes;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed> la marca creada
     */
    public function crearMarca(array $d, Origen $origen): array
    {
        if ($this->datos->existeMarcaLlamada($d['MAR_Nombre'])) {
            throw ErrorDominio::invalido('BRAND_ALREADY_EXISTS');
        }

        return $this->crud->crear(T::marca(), $d, $origen, ['nombre' => $d['MAR_Nombre']]);
    }

    /**
     * Modelos con su marca en una sola lectura, para los selectores.
     *
     * @return list<array<string, mixed>>
     */
    public function modelosConMarca(?int $tipo, ?string $texto, int $limite): array
    {
        $entero = static fn (mixed $v): ?int => null === $v ? null : (int) $v;

        return array_map(static fn (array $f): array => [
            'MOD_Modelo' => (int) $f['MOD_Modelo'], 'MOD_Nombre' => $f['MOD_Nombre'], 'MAR_Marca' => (int) $f['MAR_Marca'],
            'MAR_Nombre' => $f['MAR_Nombre'], 'TCN_Tipo_Conexion' => $entero($f['TCN_Tipo_Conexion']),
            'TAC_Tipo_Activo' => $entero($f['TAC_Tipo_Activo']), 'MOD_Anio_Lanzamiento' => $entero($f['MOD_Anio_Lanzamiento']),
        ], $this->datos->modelosConMarca($tipo, $texto, $limite));
    }

    /**
     * La marca de un modelo y el tipo de activo con que más se registra entre los activos que el usuario puede ver.
     *
     * @return array{marca: ?array<string, mixed>, tipo_sugerido: ?array<string, mixed>}
     */
    public function contextoDeModelo(int $id): array
    {
        $modelo = $this->crud->obtenerO404(T::modelo(), $id, 'MODEL_NOT_FOUND');
        $marca = $this->crud->repositorio->obtener(T::marca(), $modelo['MAR_Marca']);
        $tipoId = $this->datos->tipoMasUsadoDeModelo($modelo['MOD_Modelo']);
        $tipo = null === $tipoId ? null : $this->crud->repositorio->obtener(T::tipoActivo(), $tipoId);

        return [
            'marca' => null === $marca ? null : ['MAR_Marca' => $marca['MAR_Marca'], 'nombre' => $marca['MAR_Nombre']],
            'tipo_sugerido' => null === $tipo ? null : ['TAC_Tipo_Activo' => $tipo['TAC_Tipo_Activo'], 'nombre' => $tipo['TAC_Nombre'],
                'prefijo' => $tipo['TAC_Prefijo'], 'origen' => 'activos_del_modelo'],
        ];
    }

    /**
     * Renombra un estado operativo; los que usa la máquina de estados de los activos no se tocan.
     *
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    public function actualizarEstadoOperativo(int $id, array $cambios, Origen $origen): array
    {
        $this->estadoEditable($id, 'CANNOT_MODIFY_SYSTEM_OPERATIONAL_STATUS');

        return $this->crud->actualizar(T::estadoOperativo(), $id, $cambios, $origen);
    }

    /** Borrado lógico de un estado operativo que no es del sistema ni está en uso. */
    public function eliminarEstadoOperativo(int $id, Origen $origen): void
    {
        $estado = $this->estadoEditable($id, 'CANNOT_DELETE_SYSTEM_OPERATIONAL_STATUS');
        try {
            $this->crud->eliminar(T::estadoOperativo(), $id, $origen, ['id' => $id, 'nombre' => $estado['EOP_Nombre']]);
        } catch (RecordInUse) {
            throw ErrorDominio::conflicto('CANNOT_DELETE_OPERATIONAL_STATUS_IN_USE');
        }
    }

    /** @return array<string, mixed> */
    private function estadoEditable(int $id, string $siEsDelSistema): array
    {
        $estado = $this->crud->obtenerO404(T::estadoOperativo(), $id, 'OPERATIONAL_STATUS_NOT_FOUND');
        if (in_array(mb_strtolower(trim((string) $estado['EOP_Nombre'])), self::ESTADOS_DEL_SISTEMA, true)) {
            throw ErrorDominio::conflicto($siEsDelSistema);
        }

        return $estado;
    }

    /** @return array<string, mixed> */
    private function tipo(int $id): array
    {
        return $this->crud->obtenerO404(T::tipoActivo(), $id, 'ASSET_TYPE_NOT_FOUND');
    }

    /**
     * @param array<string, mixed> $tipo
     *
     * @return array<string, mixed>
     */
    private static function conReglas(array $tipo): array
    {
        return [...$tipo, 'reglas' => ClasesActivo::reglas($tipo['TAC_Clase'])];
    }
}
