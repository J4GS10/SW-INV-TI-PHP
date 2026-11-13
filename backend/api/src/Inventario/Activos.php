<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Inventario\Infrastructure\Kardex;
use App\Inventario\Infrastructure\ActivosStore;
use App\Inventario\Infrastructure\DatosActivos;
use App\Inventario\Infrastructure\MantenimientosStore;
use App\Inventario\Infrastructure\Secuencias;
use App\Crud\Esquema;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;

/**
 * Activos (INV_ACTIVO): alta con código correlativo, edición con reglas de
 * estado y sede, especificaciones, componentes y la baja (que solo se ejecuta
 * aprobada). Cada cambio deja su evento en el kardex y en la bitácora.
 */
final readonly class Activos
{
    public const array COLUMNAS = ['ACT_Activo', 'ACT_Codigo_Interno', 'ACT_Serie_Fabricante', 'ACT_Hostname', 'ACT_Fecha_Compra',
        'ACT_Fin_Garantia', 'ACT_Costo', 'ACT_Moneda', 'PRV_Proveedor', 'ACT_Factura', 'MOD_Modelo', 'TAC_Tipo_Activo',
        'EOP_Estado_Operativo', 'ACT_Activo_Padre', 'SED_Sede', 'ARE_Area_Ubicacion', 'ACT_Baja_Motivo', 'ACT_Baja_Destino',
        'ACT_Baja_Fecha', 'ACT_Baja_Documento', 'ACT_Baja_Observacion', 'ACT_Instalado', 'ACT_Responsable', 'ACT_Pending_Completion'];
    public const array MONEDAS = ['GTQ', 'USD', 'CHF'];
    /** Columnas por las que se puede ordenar la búsqueda: lista cerrada, nunca texto del cliente en el SQL. */
    private const array ORDEN_BUSQUEDA = ['code' => 'ACT_Codigo_Interno', 'serial' => 'ACT_Serie_Fabricante', 'host' => 'ACT_Hostname'];

    /** Sin orden válido, el de siempre: lo más reciente primero. El código desempata para que la paginación sea estable. */
    public static function ordenBusqueda(?string $columna, ?string $direccion): string
    {
        if (null === $columna || !isset(self::ORDEN_BUSQUEDA[$columna])) {
            return 'a."ACT_Fecha_Compra" DESC, a."ACT_Codigo_Interno"';
        }
        $sentido = 'desc' === $direccion ? 'DESC' : 'ASC';

        return 'a."'.self::ORDEN_BUSQUEDA[$columna].'" '.$sentido.' NULLS LAST, a."ACT_Codigo_Interno"';
    }

    public function __construct(
        private ActivosStore $datos,
        private DatosActivos $datosComunes,
        private MantenimientosStore $mantenimientos,
        private Alcance $alcance,
        private Kardex $kardex,
        private EstadosActivo $estados,
        private ReglasClase $reglas,
        private Secuencias $secuencias,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
        private \App\Catalogo\SpecificationRules $specificationRules,
    ) {
    }

    public static function select(string $alias = 'a'): string
    {
        return implode(', ', array_map(static fn (string $c): string => "{$alias}.\"{$c}\"", self::COLUMNAS));
    }

    // =================================================================
    // Lectura
    // =================================================================

    /**
     * Fila del activo visible para el usuario (capa 1 + VPD), o null.
     *
     * @return array<string, mixed>|null
     */
    public function fila(string $hex): ?array
    {
        $f = $this->datos->visible($hex);

        return false === $f ? null : $f;
    }

    /**
     * Locks several assets in a single order. Two batches over the same assets, sent in opposite orders,
     * wait for each other instead of ending in a deadlock.
     *
     * @param list<string> $ids hex
     */
    public function bloquearEnOrden(array $ids): void
    {
        $ordenados = array_values(array_unique($ids));
        sort($ordenados, \SORT_STRING);
        foreach ($ordenados as $id) {
            $this->datos->bloquear($id);
        }
    }

    /** @return array<string, mixed> */
    public function filaO404(string $hex, string $codigo = 'ASSET_NOT_FOUND'): array
    {
        return $this->fila($hex) ?? throw ErrorDominio::noEncontrado($codigo);
    }

    /**
     * ActivoResponse: datos y especificaciones.
     *
     * @param array<string, mixed>            $f
     * @param list<array<string, mixed>>|null $especificaciones
     *
     * @return array<string, mixed>
     */
    public function aApi(array $f, ?array $especificaciones = null): array
    {
        $uuid = static fn (mixed $v): ?string => null === $v ? null : Ident::aTexto((string) $v);
        $entero = static fn (mixed $v): ?int => null === $v ? null : (int) $v;
        $fecha = static fn (mixed $v): ?string => null === $v ? null : substr((string) $v, 0, 10);

        return [
            'ACT_Codigo_Interno' => $f['ACT_Codigo_Interno'],
            'pending_completion' => 1 === (int) ($f['ACT_Pending_Completion'] ?? 0),
            'ACT_Serie_Fabricante' => $f['ACT_Serie_Fabricante'],
            'ACT_Hostname' => $f['ACT_Hostname'],
            'ACT_Fecha_Compra' => $fecha($f['ACT_Fecha_Compra']),
            'ACT_Fin_Garantia' => $fecha($f['ACT_Fin_Garantia']),
            'ACT_Costo' => null === $f['ACT_Costo'] ? null : Esquema::decimal((string) $f['ACT_Costo'], 2),
            'ACT_Moneda' => $f['ACT_Moneda'],
            'PRV_Proveedor' => $entero($f['PRV_Proveedor']),
            'ACT_Factura' => $f['ACT_Factura'],
            'MOD_Modelo' => (int) $f['MOD_Modelo'],
            'TAC_Tipo_Activo' => (int) $f['TAC_Tipo_Activo'],
            'EOP_Estado_Operativo' => (int) $f['EOP_Estado_Operativo'],
            'ACT_Activo_Padre' => $uuid($f['ACT_Activo_Padre']),
            'SED_Sede' => $entero($f['SED_Sede']),
            'ACT_Activo' => Ident::aTexto((string) $f['ACT_Activo']),
            'ARE_Area_Ubicacion' => $entero($f['ARE_Area_Ubicacion']),
            'ACT_Baja_Motivo' => $f['ACT_Baja_Motivo'],
            'ACT_Baja_Destino' => $f['ACT_Baja_Destino'],
            'ACT_Baja_Fecha' => $fecha($f['ACT_Baja_Fecha']),
            'ACT_Baja_Documento' => $f['ACT_Baja_Documento'],
            'ACT_Baja_Observacion' => $f['ACT_Baja_Observacion'],
            'ACT_Instalado' => 1 === (int) $f['ACT_Instalado'],
            'ACT_Responsable' => $uuid($f['ACT_Responsable']),
            'especificaciones' => $especificaciones ?? $this->especificacionesDe([(string) $f['ACT_Activo']])[(string) $f['ACT_Activo']] ?? [],
        ];
    }

    /**
     * Especificaciones (forma corta) de varios activos.
     *
     * @param list<string> $ids hex
     *
     * @return array<string, list<array{TES_Tipo_Especificacion: int, ESP_Valor: string, ESP_Especificacion: int}>>
     */
    public function especificacionesDe(array $ids): array
    {
        $porActivo = array_fill_keys($ids, []);
        foreach (array_chunk($ids, 500) as $lote) {
            foreach ($this->datos->especificacionesDe($lote) as $e) {
                $porActivo[(string) $e['ACT_Activo']][] = ['TES_Tipo_Especificacion' => (int) $e['TES_Tipo_Especificacion'],
                    'ESP_Valor' => $e['ESP_Valor'], 'ESP_Especificacion' => (int) $e['ESP_Especificacion']];
            }
        }

        return $porActivo;
    }

    /**
     * Varias filas → ActivoResponse (una sola consulta de especificaciones).
     *
     * @param list<array<string, mixed>> $filas
     *
     * @return list<array<string, mixed>>
     */
    public function variosAApi(array $filas, bool $detalle = false): array
    {
        $specs = $this->especificacionesDe(array_map(static fn (array $f): string => (string) $f['ACT_Activo'], $filas));

        return array_map(fn (array $f): array => $detalle ? $this->conRelaciones($f, $specs[(string) $f['ACT_Activo']])
            : $this->aApi($f, $specs[(string) $f['ACT_Activo']]), $filas);
    }

    /**
     * ActivoDetailResponse: además, modelo (con marca), tipo y estado anidados.
     *
     * @param array<string, mixed>            $f
     * @param list<array<string, mixed>>|null $especificaciones
     *
     * @return array<string, mixed>
     */
    public function conRelaciones(array $f, ?array $especificaciones = null): array
    {
        $r = $this->datos->relaciones((string) $f['ACT_Activo']) ?: [];

        return [...$this->aApi($f, $especificaciones),
            'modelo' => null === ($r['MOD_Nombre'] ?? null) ? null : ['MOD_Modelo' => (int) $f['MOD_Modelo'], 'MOD_Nombre' => $r['MOD_Nombre'],
                'marca' => null === $r['MAR_Marca'] ? null : ['MAR_Marca' => (int) $r['MAR_Marca'], 'MAR_Nombre' => $r['MAR_Nombre']]],
            'tipo_activo' => null === ($r['TAC_Nombre'] ?? null) ? null : ['TAC_Tipo_Activo' => (int) $f['TAC_Tipo_Activo'], 'TAC_Nombre' => $r['TAC_Nombre']],
            'estado_operativo' => null === ($r['EOP_Nombre'] ?? null) ? null
                : ['EOP_Estado_Operativo' => (int) $f['EOP_Estado_Operativo'], 'EOP_Nombre' => $r['EOP_Nombre']],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listar(int $saltar, int $limite): array
    {
        return $this->variosAApi($this->datos->pagina($saltar, $limite));
    }

    /**
     * Busca por código interno exacto (sin espacios alrededor).
     *
     * @return array<string, mixed>
     */
    public function porCodigo(string $codigo): array
    {
        $f = $this->datos->visiblePorCodigo(trim($codigo));

        return false === $f ? throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND') : $this->conRelaciones($f);
    }

    /**
     * Búsqueda paginada (más recientes por fecha de compra primero).
     *
     * @param array{q: ?string, modelo_id: ?int, tipo_activo_id: ?int, estado_operativo_id: ?int, sede_id: ?int,
     *     fecha_compra_start: ?string, fecha_compra_end: ?string, page: int, per_page: int, sort?: ?string, sort_dir?: ?string} $f
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function buscar(array $f): array
    {
        [$total, $filas] = $this->datos->buscar($f);

        return ['items' => $this->conCustodio($this->variosAApi($filas, true)), 'total' => $total, 'page' => $f['page'], 'per_page' => $f['per_page']];
    }

    /**
     * Quién tiene cada equipo, para los roles que pueden ver personas.
     *
     * @param list<array<string, mixed>> $activos
     *
     * @return list<array<string, mixed>>
     */
    private function conCustodio(array $activos): array
    {
        $rol = $this->alcance->actual()?->rol;
        if ([] === $activos || !\in_array($rol, \App\Dominio\Roles::LECTURA_PERSONAS, true)) {
            return $activos;
        }
        $nombres = [];
        foreach ($this->datos->custodiosDe(array_map(static fn (array $a): string => Ident::aHex((string) $a['ACT_Activo']), $activos)) as $f) {
            $nombres[(string) $f['ACT_Activo']] = trim($f['PER_Primer_Nombre'].' '.$f['PER_Primer_Apellido']);
        }

        return array_map(static fn (array $a): array => [...$a, 'custodio' => $nombres[Ident::aHex((string) $a['ACT_Activo'])] ?? null], $activos);
    }

    /**
     * ¿Tiene el activo una custodia vigente? (asignación sin devolver)
     *
     * @return array<string, mixed>|null
     */
    public function movimientoVigente(string $hex): ?array
    {
        $m = $this->datos->movimientoVigente($hex);

        return false === $m ? null : $m;
    }

    public function mantenimientoAbierto(string $hex): bool
    {
        return $this->mantenimientos->tieneTicketAbierto($hex);
    }

    // =================================================================
    // Alta y edición
    // =================================================================

    /**
     * @param array<string, mixed>                                                      $d columnas validadas
     * @param list<array{TES_Tipo_Especificacion: int, ESP_Valor: string, motivo?: ?string}> $especificaciones
     *
     * @return array<string, mixed> ActivoResponse
     */
    public function crear(array $d, array $especificaciones, string $usuario): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $especificaciones, $usuario): array {
            $tipo = $this->datos->tipo($d['TAC_Tipo_Activo']) ?: throw ErrorDominio::noEncontrado('ASSET_TYPE_NOT_FOUND');
            // Los insumos se registran como consumibles (por cantidad), no como activos.
            $this->reglas->exigirNoInsumo((int) $d['TAC_Tipo_Activo']);
            if (!$this->datos->existeModelo($d['MOD_Modelo'])) {
                throw ErrorDominio::noEncontrado('MODEL_NOT_FOUND');
            }
            $estado = $this->estados->nombre((int) $d['EOP_Estado_Operativo']) ?? throw ErrorDominio::noEncontrado('OPERATIONAL_STATUS_NOT_FOUND');
            // Un activo nuevo entra al almacén: "Asignado", "En Reparación" o "Baja" solo se alcanzan con su operación.
            if (!\in_array(EstadosActivo::canonico($estado), EstadosActivo::DE_ALMACEN, true)) {
                throw ErrorDominio::invalido('INITIAL_STATE_MUST_BE_AVAILABLE_OR_STORAGE');
            }
            $d['SED_Sede'] = $this->alcance->exigirSede($d['SED_Sede'], requerida: false);
            if (null !== ($d['ACT_Hostname'] ?? null) && '' !== $d['ACT_Hostname']) {
                $this->exigirHostnameLibre((string) $d['ACT_Hostname'], null);
            }
            if (null !== $d['ACT_Activo_Padre'] && null === $this->fila($d['ACT_Activo_Padre'])) {
                throw ErrorDominio::noEncontrado('PARENT_ASSET_NOT_FOUND');
            }
            if (null === $d['ACT_Codigo_Interno']) {
                $prefijo = $tipo['TAC_Prefijo'];
                if (null === $prefijo || '' === $prefijo) {
                    throw ErrorDominio::invalido('ASSET_TYPE_HAS_NO_PREFIX_CONFIGURED_FOR_AUTO_GENERATION');
                }
                $d['ACT_Codigo_Interno'] = $this->secuencias->siguiente("ASSET_{$prefijo}", (string) $prefijo);
            }
            // Unicidad en toda la empresa, no solo en el alcance.
            [$codigo, $serie] = $this->alcance->elevado(fn (): array => [
                $this->datos->existeValor('ACT_Codigo_Interno', (string) $d['ACT_Codigo_Interno']),
                $this->datos->existeValor('ACT_Serie_Fabricante', (string) $d['ACT_Serie_Fabricante']),
            ]);
            if ($codigo) {
                throw ErrorDominio::invalido('ASSET_CODE_ALREADY_EXISTS');
            }
            if ($serie) {
                throw ErrorDominio::invalido('SERIAL_NUMBER_ALREADY_EXISTS');
            }
            if (null !== $d['ACT_Fin_Garantia'] && $d['ACT_Fin_Garantia'] < $d['ACT_Fecha_Compra']) {
                throw ErrorDominio::invalido('WARRANTY_DATE_CANNOT_BE_BEFORE_PURCHASE_DATE');
            }

            $id = Ident::nuevo();
            $this->insertar($id, $d);
            foreach ($especificaciones as $e) {
                $this->datosComunes->agregarEspecificacion($id, $e['TES_Tipo_Especificacion'],
                    $this->specificationRules->normalize((int) $d['TAC_Tipo_Activo'], (int) $e['TES_Tipo_Especificacion'], (string) $e['ESP_Valor']));
            }
            $activo = $this->datos->porId($id);
            $this->kardex->registrar('ALTA', activo: $activo, usuario: $usuario, destino: ['sede' => $d['SED_Sede']],
                costo: $d['ACT_Costo'], moneda: $d['ACT_Moneda'], motivo: 'Alta manual en el inventario');
            if (null !== $d['ACT_Activo_Padre']) {
                $this->kardex->registrar('COMPONENTE_INSTALADO', activo: $activo, usuario: $usuario,
                    destino: ['activo' => $d['ACT_Activo_Padre'], 'sede' => $d['SED_Sede']], motivo: 'Registrado como componente al darlo de alta');
            }
            $this->gobierno->registrarAuditoria('CREATE', 'INV_ACTIVO',
                ['codigo' => $d['ACT_Codigo_Interno'], 'serie' => $d['ACT_Serie_Fabricante'], 'sede' => $d['SED_Sede']], $usuario, sedeId: $d['SED_Sede']);

            return $this->aApi($activo);
        });
    }

    /** @param array<string, mixed> $d */
    private function insertar(string $id, array $d): void
    {
        $columnas = ['ACT_Activo' => $id, 'ACT_Instalado' => 0];
        foreach (['ACT_Codigo_Interno', 'ACT_Serie_Fabricante', 'ACT_Hostname', 'ACT_Costo', 'ACT_Moneda', 'PRV_Proveedor', 'ACT_Factura',
            'MOD_Modelo', 'TAC_Tipo_Activo', 'EOP_Estado_Operativo', 'ACT_Activo_Padre', 'SED_Sede'] as $c) {
            $columnas[$c] = $d[$c];
        }
        $this->datos->insertar($columnas, $d['ACT_Fecha_Compra'], $d['ACT_Fin_Garantia']);
    }

    /**
     * Edición parcial: solo los campos enviados.
     *
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed> ActivoResponse
     */
    public function actualizar(string $id, array $cambios, string $usuario): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $cambios, $usuario): array {
            // Bloqueada antes de leer: una edición, una entrega o una baja a la vez sobre el mismo activo.
            $this->bloquearEnOrden([$id]);
            $actual = $this->filaO404($id);
            $hay = static fn (string $c): bool => \array_key_exists($c, $cambios);
            if (null !== ($cambios['MOD_Modelo'] ?? null)
                && !$this->datos->existeModelo($cambios['MOD_Modelo'])) {
                throw ErrorDominio::noEncontrado('MODEL_NOT_FOUND');
            }
            if (null !== ($cambios['TAC_Tipo_Activo'] ?? null)
                && false === $this->datos->tipo($cambios['TAC_Tipo_Activo'])) {
                throw ErrorDominio::noEncontrado('ASSET_TYPE_NOT_FOUND');
            }
            // Un activo con otro estado solo cambia con su operación (asignar, reparar, dar de baja…).
            $estadoNuevo = $cambios['EOP_Estado_Operativo'] ?? null;
            if (null !== $estadoNuevo && (int) $estadoNuevo !== (int) $actual['EOP_Estado_Operativo']) {
                $nombre = $this->estados->nombre((int) $estadoNuevo) ?? throw ErrorDominio::noEncontrado('OPERATIONAL_STATUS_NOT_FOUND');
                if (!\in_array(EstadosActivo::canonico($nombre), EstadosActivo::DE_ALMACEN, true)
                    || !\in_array($this->estados->canonicoDeId((int) $actual['EOP_Estado_Operativo']), EstadosActivo::DE_ALMACEN, true)
                    || null !== $this->movimientoVigente($id)) {
                    throw ErrorDominio::invalido('STATE_CHANGE_REQUIRES_OPERATION');
                }
            }
            $compra = $cambios['ACT_Fecha_Compra'] ?? null ?: substr((string) $actual['ACT_Fecha_Compra'], 0, 10);
            $garantia = $hay('ACT_Fin_Garantia') ? $cambios['ACT_Fin_Garantia'] : (null === $actual['ACT_Fin_Garantia'] ? null : substr((string) $actual['ACT_Fin_Garantia'], 0, 10));
            if ($hay('ACT_Fecha_Compra') && null === $cambios['ACT_Fecha_Compra']) {
                throw ErrorDominio::invalido('PURCHASE_DATE_REQUIRED');
            }
            if (null !== $garantia && '' !== $compra && $garantia < $compra) {
                throw ErrorDominio::invalido('WARRANTY_DATE_CANNOT_BE_BEFORE_PURCHASE_DATE');
            }
            // Cambio de sede: dentro del alcance y sin custodia vigente.
            $sedeAntes = null === $actual['SED_Sede'] ? null : (int) $actual['SED_Sede'];
            $sedeFinal = $sedeAntes;
            if ($hay('SED_Sede') && null === $cambios['SED_Sede'] && null !== $sedeAntes) {
                throw ErrorDominio::invalido('SEDE_REQUIRED');
            }
            if ($hay('SED_Sede') && $cambios['SED_Sede'] !== $sedeAntes) {
                $cambios['SED_Sede'] = $this->alcance->exigirSede($cambios['SED_Sede'], requerida: false);
                if (null !== $this->movimientoVigente($id)) {
                    throw ErrorDominio::invalido('CANNOT_CHANGE_SEDE_OF_ASSIGNED_ASSET');
                }
                $sedeFinal = $cambios['SED_Sede'];
            }
            foreach (['ACT_Codigo_Interno' => 'NEW_ASSET_CODE_ALREADY_EXISTS', 'ACT_Serie_Fabricante' => 'NEW_SERIAL_NUMBER_ALREADY_EXISTS'] as $c => $error) {
                if (null !== ($cambios[$c] ?? null) && '' !== $cambios[$c] && $cambios[$c] !== $actual[$c]
                    && $this->alcance->elevado(fn (): bool => $this->datos->existeValor($c, (string) $cambios[$c]))) {
                    throw ErrorDominio::invalido($error);
                }
            }

            if (null !== ($cambios['ACT_Hostname'] ?? null) && '' !== $cambios['ACT_Hostname']) {
                $this->exigirHostnameLibre((string) $cambios['ACT_Hostname'], $id);
            }
            $this->escribirCambios($id, $cambios);
            $activo = $this->datos->porId($id);
            if (null !== $estadoNuevo && (int) $estadoNuevo !== (int) $actual['EOP_Estado_Operativo']) {
                $this->kardex->registrar('CAMBIO_ESTADO', activo: $activo, usuario: $usuario, motivo: 'Cambio de estado de almacén',
                    detalle: ['estado_antes' => (int) $actual['EOP_Estado_Operativo'], 'estado_despues' => (int) $estadoNuevo]);
            }
            $this->registrarCambiosEnKardex($actual, $activo, $cambios, $usuario);
            if ($sedeFinal !== $sedeAntes) {
                $this->kardex->registrar('TRASLADO_SEDE', activo: $activo, usuario: $usuario, origen: ['sede' => $sedeAntes],
                    destino: ['sede' => $sedeFinal], motivo: 'Cambio de sede');
            }
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_ACTIVO', ['activo_id' => Ident::aTexto($id), 'cambios' => (object) $cambios],
                $usuario, sedeId: $sedeFinal);

            return $this->aApi($activo);
        });
    }

    /**
     * Costo, serie y nombre de host también dejan rastro en el kardex; guardar lo mismo no genera eventos.
     *
     * @param array<string, mixed> $antes
     * @param array<string, mixed> $despues
     * @param array<string, mixed> $cambios
     */
    private function registrarCambiosEnKardex(array $antes, array $despues, array $cambios, string $usuario): void
    {
        if (\array_key_exists('ACT_Costo', $cambios) && 0 !== bccomp((string) ($antes['ACT_Costo'] ?? '0'), (string) ($despues['ACT_Costo'] ?? '0'), 2)) {
            $this->kardex->registrar('CAMBIO_COSTO', activo: $despues, usuario: $usuario, costo: $despues['ACT_Costo'], moneda: $despues['ACT_Moneda'],
                motivo: 'Cambio de costo', detalle: ['antes' => $antes['ACT_Costo'], 'despues' => $despues['ACT_Costo']]);
        }
        $datos = [];
        foreach (['ACT_Serie_Fabricante' => 'serie', 'ACT_Hostname' => 'hostname'] as $columna => $nombre) {
            if (\array_key_exists($columna, $cambios) && (string) $antes[$columna] !== (string) $despues[$columna]) {
                $datos[$nombre] = ['antes' => $antes[$columna], 'despues' => $despues[$columna]];
            }
        }
        if ([] !== $datos) {
            $this->kardex->registrar('CAMBIO_DATOS', activo: $despues, usuario: $usuario, motivo: 'Cambio de datos del equipo', detalle: $datos);
        }
    }

    /** Un nombre de host no se repite entre activos vigentes, en ninguna sede. */
    private function exigirHostnameLibre(string $hostname, ?string $excepto): void
    {
        $existe = $this->alcance->elevado(fn (): bool => $this->datos->hostnameEnUso($hostname, $excepto));
        if ($existe) {
            throw ErrorDominio::conflicto('HOSTNAME_ALREADY_EXISTS');
        }
    }

    /**
     * UPDATE de las columnas dadas (las fechas, como DATE).
     *
     * @param array<string, mixed> $cambios
     */
    public function escribirCambios(string $id, array $cambios): void
    {
        $this->datos->actualizar($id, $cambios);
    }
}
