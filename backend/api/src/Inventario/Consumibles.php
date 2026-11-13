<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Inventario\Infrastructure\ConsumiblesStore;
use App\Inventario\Infrastructure\Kardex;
use App\Inventario\Infrastructure\Operador;
use App\Catalogo\CatalogoGenerico;
use App\Correo\Notificador;
use App\Crud\Esquema;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\ErrorDominio;
use App\Repositorio\ConsultasComunes;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;

/**
 * Consumibles: inventario por cantidad (tóner, cables, periféricos a granel),
 * valorizado por costo promedio ponderado. Toda variación de existencia deja
 * su movimiento (INV_MOVIMIENTO_CONSUMIBLE), su evento en el kardex y su
 * registro en la bitácora. Las salidas son atómicas: nunca dejan stock negativo.
 */
final readonly class Consumibles
{
    public function __construct(
        private ConsumiblesStore $datos,
        private ConsultasComunes $consultas,
        private Alcance $alcance,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
        private CatalogoGenerico $catalogos,
        private EstadosActivo $estados,
        private Transaccion $transaccion,
        private Notificador $notificador,
        private Operador $operador,
        private \App\Db\LogicalDelete $borrado,
    ) {
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return array<string, mixed> ConsumibleResponse
     */
    public static function aApi(array $c): array
    {
        $actual = (int) $c['CON_Stock_Actual'];
        $minimo = (int) $c['CON_Stock_Minimo'];

        return [
            'CON_Consumible' => (int) $c['CON_Consumible'],
            'CON_Nombre' => $c['CON_Nombre'],
            'CON_Descripcion' => $c['CON_Descripcion'],
            'CON_Categoria' => $c['CON_Categoria'],
            'CON_Unidad' => $c['CON_Unidad'],
            'CCO_Categoria' => null === $c['CCO_Categoria'] ? null : (int) $c['CCO_Categoria'],
            'UME_Unidad' => null === $c['UME_Unidad'] ? null : (int) $c['UME_Unidad'],
            'CON_Stock_Actual' => $actual,
            'CON_Stock_Minimo' => $minimo,
            'CON_Activo' => 1 === (int) $c['CON_Activo'],
            'SED_Sede' => null === $c['SED_Sede'] ? null : (int) $c['SED_Sede'],
            'CON_Costo_Unitario' => null === $c['CON_Costo_Unitario'] ? null : Esquema::decimal((string) $c['CON_Costo_Unitario'], 4),
            'CON_Moneda' => $c['CON_Moneda'],
            'bajo_stock' => $minimo > 0 && $actual <= $minimo,
        ];
    }

    /** @return array<string, mixed>|null */
    public function fila(int $id, bool $bloquear = false): ?array
    {
        $f = $this->datos->porId($id, $bloquear);

        return false === $f ? null : $f;
    }

    /** @return array<string, mixed> */
    public function filaO404(int $id, bool $bloquear = false): array
    {
        return $this->fila($id, $bloquear) ?? throw ErrorDominio::noEncontrado('CONSUMABLE_NOT_FOUND');
    }

    /** @return list<array<string, mixed>> */
    public function listar(bool $soloBajoStock = false): array
    {
        return array_map(self::aApi(...), $this->datos->visibles($soloBajoStock));
    }

    /**
     * Categoría y unidad del catálogo: su nombre queda también en el texto legible.
     *
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    private function textosDeCatalogo(array $d): array
    {
        if (null !== ($d['CCO_Categoria'] ?? null)) {
            $d['CON_Categoria'] = mb_substr($this->catalogos->nombreVigente('categorias-consumible', (int) $d['CCO_Categoria']), 0, 50);
        }
        if (null !== ($d['UME_Unidad'] ?? null)) {
            $d['CON_Unidad'] = mb_strtolower(mb_substr($this->catalogos->nombreVigente('unidades-medida', (int) $d['UME_Unidad']), 0, 20));
        }

        return $d;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public function crear(array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $usuario, $ip): array {
            $d['SED_Sede'] = $this->alcance->exigirSede($d['SED_Sede'], requerida: false);
            // El nombre es único en toda la empresa, no solo en el alcance.
            if ($this->alcance->elevado(fn (): bool => $this->datos->existeNombre($d['CON_Nombre']))) {
                throw ErrorDominio::conflicto('CONSUMABLE_ALREADY_EXISTS');
            }
            if (null !== $d['CON_Costo_Unitario'] && null === $d['CON_Moneda']) {
                $d['CON_Moneda'] = 'GTQ';
            }
            $d = $this->textosDeCatalogo($d);
            $id = $this->datos->insertar([
                'CON_Nombre' => $d['CON_Nombre'], 'CON_Descripcion' => $d['CON_Descripcion'], 'CON_Categoria' => $d['CON_Categoria'],
                'CON_Unidad' => $d['CON_Unidad'], 'CCO_Categoria' => $d['CCO_Categoria'], 'UME_Unidad' => $d['UME_Unidad'],
                'CON_Stock_Actual' => $d['CON_Stock_Actual'], 'CON_Stock_Minimo' => $d['CON_Stock_Minimo'], 'CON_Costo_Unitario' => $d['CON_Costo_Unitario'],
                'CON_Moneda' => $d['CON_Moneda'], 'CON_Activo' => 1, 'SED_Sede' => $d['SED_Sede'],
            ]);
            if ($d['CON_Stock_Actual'] > 0) {
                // Saldo inicial: el kardex del consumible cuadra desde cero.
                $this->kardex->registrar('CONSUMIBLE_ENTRADA', consumible: $id, referencia: $d['CON_Nombre'], cantidad: $d['CON_Stock_Actual'],
                    saldo: $d['CON_Stock_Actual'], costo: $d['CON_Costo_Unitario'], moneda: $d['CON_Moneda'],
                    saldoValor: Valoracion::valorSaldo($d['CON_Stock_Actual'], $d['CON_Costo_Unitario']),
                    destino: ['sede' => $d['SED_Sede']], motivo: 'Saldo inicial', usuario: $usuario);
            }
            $this->gobierno->registrarAuditoria('CREATE', 'INV_CONSUMIBLE', ['nombre' => $d['CON_Nombre'], 'stock_inicial' => $d['CON_Stock_Actual'],
                'sede' => $d['SED_Sede']], $usuario, $ip, sedeId: $d['SED_Sede']);

            return self::aApi($this->filaO404($id));
        });
    }

    /**
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    public function actualizar(int $id, array $cambios, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $cambios, $usuario, $ip): array {
            $c = $this->filaO404($id, true);
            $sedeAntes = null === $c['SED_Sede'] ? null : (int) $c['SED_Sede'];
            $sedeFinal = $sedeAntes;
            if (\array_key_exists('SED_Sede', $cambios) && $cambios['SED_Sede'] !== $sedeAntes) {
                $cambios['SED_Sede'] = $this->alcance->exigirSede($cambios['SED_Sede'], requerida: false);
                $sedeFinal = $cambios['SED_Sede'];
            }
            $snapshot = $cambios;
            $cambios = $this->textosDeCatalogo($cambios);
            if ([] !== $cambios) {
                $this->datos->actualizar($id, $cambios);
            }
            if ($sedeFinal !== $sedeAntes) {
                // Traslado de bodega: la existencia sale de una sede y entra en otra.
                $stock = (int) $c['CON_Stock_Actual'];
                $this->kardex->registrar('CONSUMIBLE_TRASLADO', consumible: $id, referencia: $c['CON_Nombre'], cantidad: $stock, saldo: $stock,
                    costo: $c['CON_Costo_Unitario'], moneda: $c['CON_Moneda'], saldoValor: Valoracion::valorSaldo($stock, $c['CON_Costo_Unitario']),
                    origen: ['sede' => $sedeAntes], destino: ['sede' => $sedeFinal], usuario: $usuario);
            }
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_CONSUMIBLE', ['id' => $id, 'cambios' => (object) $snapshot], $usuario, $ip, sedeId: $sedeFinal);

            return self::aApi($this->alcance->elevado(fn (): array => $this->filaO404($id)));
        });
    }

    public function eliminar(int $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $c = $this->filaO404($id);
            // Con historial de movimientos no se borra (preserva la trazabilidad).
            if ($this->datos->tieneMovimientos($id)) {
                throw ErrorDominio::conflicto('CANNOT_DELETE_CONSUMABLE_HAS_MOVEMENTS');
            }
            // Se archiva; sus compatibilidades (vínculos con modelos) se quitan con él.
            try {
                $this->borrado->archive('INV_CONSUMIBLE', 'CON_Consumible', $id);
            } catch (\App\Db\RecordInUse) {
                throw ErrorDominio::conflicto('CANNOT_DELETE_CONSUMABLE_HAS_MOVEMENTS');
            }
            $this->gobierno->registrarAuditoria('DELETE', 'INV_CONSUMIBLE', ['id' => $id, 'nombre' => $c['CON_Nombre']], $usuario, $ip,
                sedeId: null === $c['SED_Sede'] ? null : (int) $c['SED_Sede']);
        });
    }

    // ------------------------------------------------------------------
    // Movimientos de stock
    // ------------------------------------------------------------------

    /**
     * Entrada (compra, reabastecimiento) o salida (entrega a una persona o a un equipo).
     *
     * @param array{MOC_Cantidad: int, MOC_Motivo: ?string, PER_Persona: ?string, ACT_Activo: ?string, precio_unitario: ?string, moneda: ?string} $m
     *
     * @return array<string, mixed> MovimientoConsumibleResponse
     */
    public function mover(int $id, array $m, string $tipo, ?string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $m, $tipo, $usuario, $ip): array {
            $c = $this->filaO404($id, true);
            $sede = null === $c['SED_Sede'] ? null : (int) $c['SED_Sede'];
            if (1 !== (int) $c['CON_Activo']) {
                throw ErrorDominio::invalido('CONSUMABLE_INACTIVE');
            }
            if (null !== $m['PER_Persona'] && !$this->consultas->existePersona($m['PER_Persona'])) {
                throw ErrorDominio::noEncontrado('PERSON_NOT_FOUND');
            }
            $equipo = null;
            if (null !== $m['ACT_Activo']) {
                if ('SALIDA' !== $tipo) {
                    throw ErrorDominio::noProcesable('ASSET_ONLY_ON_STOCK_OUT');
                }
                $equipo = $this->equipoParaConsumo($m['ACT_Activo']);
            }
            $antes = (int) $c['CON_Stock_Actual'];
            $minimo = (int) $c['CON_Stock_Minimo'];
            $promedio = null === $c['CON_Costo_Unitario'] ? null : (string) $c['CON_Costo_Unitario'];
            $moneda = $c['CON_Moneda'];
            $costo = $promedio;
            if ('ENTRADA' === $tipo) {
                // Se calcula antes de mover el stock: una entrada en otra moneda se rechaza sin tocar nada.
                [$promedio, $moneda, $costo] = Valoracion::entrada($antes, $promedio, $moneda, $m['MOC_Cantidad'], $m['precio_unitario'], $m['moneda']);
                $this->datos->sumarStock($id, $m['MOC_Cantidad']);
            } elseif (!$this->datos->restarStock($id, $m['MOC_Cantidad'])) {
                throw ErrorDominio::conflicto('INSUFFICIENT_STOCK');
            }
            $despues = $this->datos->stock($id);
            $this->datos->guardarCosto($id, $promedio, $moneda);
            $mov = $this->insertarMovimiento($id, $tipo, $m['MOC_Cantidad'], $despues, $m['MOC_Motivo'], $m['PER_Persona'],
                null === $equipo ? null : (string) $equipo['ACT_Activo'], $usuario);
            $this->kardex->registrar('ENTRADA' === $tipo ? 'CONSUMIBLE_ENTRADA' : 'CONSUMIBLE_SALIDA', consumible: $id, referencia: $c['CON_Nombre'],
                cantidad: $m['MOC_Cantidad'], saldo: $despues, costo: $costo, moneda: $moneda, saldoValor: Valoracion::valorSaldo($despues, $promedio),
                destino: 'SALIDA' === $tipo ? ['persona' => $m['PER_Persona'], 'sede' => $sede, 'activo' => null === $equipo ? null : (string) $equipo['ACT_Activo'],
                    'area' => null === $equipo || null === $equipo['ARE_Area_Ubicacion'] ? null : (int) $equipo['ARE_Area_Ubicacion']] : ['sede' => $sede],
                origen: 'SALIDA' === $tipo ? ['sede' => $sede] : [], motivo: $m['MOC_Motivo'], usuario: $usuario);
            $this->gobierno->registrarAuditoria('ENTRADA' === $tipo ? 'STOCK_IN' : 'STOCK_OUT', 'INV_CONSUMIBLE', ['id' => $id, 'nombre' => $c['CON_Nombre'],
                'cantidad' => $m['MOC_Cantidad'], 'stock_resultante' => $despues, 'motivo' => $m['MOC_Motivo'],
                'equipo' => null === $equipo ? null : $equipo['ACT_Codigo_Interno']], $usuario, $ip, sedeId: $sede);

            // Alerta de stock bajo: solo al CRUZAR el umbral (evita repetirla si ya estaba bajo).
            if ($minimo > 0 && $antes > $minimo && $despues <= $minimo) {
                $op = $this->operador->de($usuario);
                $datos = ['codigo' => $c['CON_Nombre'], 'stock_actual' => $despues, 'stock_minimo' => $minimo, 'unidad' => $c['CON_Unidad'],
                    'categoria' => $c['CON_Categoria'] ?? ''];
                $this->transaccion->alConfirmar(fn () => $this->notificador->enviar('stock_bajo', $datos, responderA: $op['correo'],
                    operador: $op['nombre'], rolOperador: $op['rol']));
            }

            return [...$mov, 'bajo_minimo' => $minimo > 0 && $despues <= $minimo, 'CON_Stock_Minimo' => $minimo];
        });
    }

    /**
     * Ajuste por conteo físico: la existencia pasa a `$real`. Solo se ejecuta aprobado
     * (suba o baje: inflar la existencia oculta sustracciones); al aprobarlo se exige
     * que la existencia siga siendo la del momento de la solicitud.
     *
     * @param array<string, mixed>|null $aprobacion
     *
     * @return array<string, mixed>
     */
    public function ajustar(int $id, int $real, string $motivo, ?string $usuario, ?string $ip, ?array $aprobacion = null, ?int $stockEsperado = null): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $real, $motivo, $usuario, $ip, $aprobacion, $stockEsperado): array {
            $c = $this->filaO404($id, true);
            $antes = (int) $c['CON_Stock_Actual'];
            if (null !== $stockEsperado && $antes !== $stockEsperado) {
                throw ErrorDominio::conflicto('STOCK_CHANGED_SINCE_REQUEST');
            }
            if ($real === $antes) {
                throw ErrorDominio::invalido('STOCK_ALREADY_MATCHES');
            }
            if (null === $aprobacion) {
                throw ErrorDominio::conflicto('STOCK_ADJUST_REQUIRES_APPROVAL');
            }
            $this->datos->fijarStock($id, $real);
            $mov = $this->insertarMovimiento($id, 'AJUSTE', abs($real - $antes), $real, $motivo, null, null, $usuario);
            $sede = null === $c['SED_Sede'] ? null : (int) $c['SED_Sede'];
            $this->kardex->registrar('CONSUMIBLE_AJUSTE', consumible: $id, referencia: $c['CON_Nombre'], cantidad: $real - $antes, saldo: $real,
                costo: $c['CON_Costo_Unitario'], moneda: $c['CON_Moneda'], saldoValor: Valoracion::valorSaldo($real, $c['CON_Costo_Unitario']),
                destino: ['sede' => $sede], motivo: $motivo, usuario: $usuario,
                detalle: ['stock_antes' => $antes, 'stock_despues' => $real,
                    'aprobacion' => array_map(static fn (mixed $v): string => (string) $v, $aprobacion)]);
            $this->gobierno->registrarAuditoria('STOCK_ADJUST', 'INV_CONSUMIBLE', ['id' => $id, 'nombre' => $c['CON_Nombre'], 'antes' => $antes,
                'despues' => $real, 'motivo' => $motivo], $usuario, $ip, sedeId: $sede);

            return [...$mov, 'bajo_minimo' => false, 'CON_Stock_Minimo' => null];
        });
    }

    /** @return array<string, mixed> */
    private function insertarMovimiento(int $id, string $tipo, int $cantidad, int $resultante, ?string $motivo, ?string $persona, ?string $activo, ?string $usuario): array
    {
        $fecha = Reloj::aBd(Reloj::ahora());
        $mov = $this->datos->insertarMovimiento(['MOC_Tipo' => $tipo, 'MOC_Cantidad' => $cantidad,
            'MOC_Stock_Resultante' => $resultante, 'MOC_Motivo' => $motivo, 'MOC_Fecha' => $fecha, 'CON_Consumible' => $id, 'PER_Persona' => $persona,
            'ACT_Activo' => $activo, 'USU_Usuario' => $usuario]);

        return self::movimientoAApi(['MOC_Movimiento' => $mov, 'MOC_Tipo' => $tipo, 'MOC_Cantidad' => $cantidad, 'MOC_Stock_Resultante' => $resultante,
            'MOC_Motivo' => $motivo, 'MOC_Fecha' => $fecha, 'CON_Consumible' => $id, 'PER_Persona' => $persona, 'ACT_Activo' => $activo, 'USU_Usuario' => $usuario]);
    }

    /**
     * @param array<string, mixed> $m
     *
     * @return array<string, mixed>
     */
    public static function movimientoAApi(array $m): array
    {
        $uuid = static fn (mixed $v): ?string => null === $v ? null : Ident::aTexto((string) $v);

        return ['MOC_Movimiento' => (int) $m['MOC_Movimiento'], 'MOC_Tipo' => $m['MOC_Tipo'], 'MOC_Cantidad' => (int) $m['MOC_Cantidad'],
            'MOC_Stock_Resultante' => (int) $m['MOC_Stock_Resultante'], 'bajo_minimo' => false, 'CON_Stock_Minimo' => null,
            'MOC_Motivo' => $m['MOC_Motivo'], 'MOC_Fecha' => Reloj::iso(Reloj::desdeBd((string) $m['MOC_Fecha'])),
            'CON_Consumible' => (int) $m['CON_Consumible'], 'PER_Persona' => $uuid($m['PER_Persona']), 'ACT_Activo' => $uuid($m['ACT_Activo']),
            'USU_Usuario' => $uuid($m['USU_Usuario'])];
    }

    /**
     * El equipo que recibe el consumible: visible y no dado de baja.
     *
     * @return array<string, mixed>
     */
    private function equipoParaConsumo(string $activo): array
    {
        $e = $this->datos->equipoVisible($activo) ?: throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
        if ('baja' === mb_strtolower((string) $this->estados->nombre((int) $e['EOP_Estado_Operativo']))) {
            throw ErrorDominio::invalido('ASSET_DECOMMISSIONED');
        }

        return $e;
    }

    /** @return list<array<string, mixed>> */
    public function movimientos(int $id): array
    {
        $this->filaO404($id);
        return array_map(self::movimientoAApi(...), $this->datos->movimientosDe($id));
    }

    // ------------------------------------------------------------------
    // Compatibilidad con modelos de equipo y consumo por equipo
    // ------------------------------------------------------------------

    /** @return list<array{MOD_Modelo: int, modelo: string, marca: string}> */
    public function compatibles(int $id): array
    {
        $this->filaO404($id);

        return array_map(static fn (array $f): array => ['MOD_Modelo' => (int) $f['MOD_Modelo'], 'modelo' => $f['MOD_Nombre'], 'marca' => $f['MAR_Nombre']],
            $this->datos->modelosCompatibles($id));
    }

    /**
     * @param list<int> $modelos
     *
     * @return list<array<string, mixed>>
     */
    public function fijarCompatibles(int $id, array $modelos, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $modelos, $usuario, $ip): array {
            $c = $this->filaO404($id, true);
            $modelos = array_values(array_unique($modelos));
            sort($modelos);
            if ($this->datos->cuantosModelosExisten($modelos) !== \count($modelos)) {
                throw ErrorDominio::noEncontrado('MODEL_NOT_FOUND');
            }
            $antes = $this->datos->idsDeCompatibles($id);
            $this->datos->reemplazarCompatibles($id, $modelos);
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_CONSUMIBLE_COMPATIBLE', ['id' => $id, 'nombre' => $c['CON_Nombre'], 'antes' => $antes,
                'despues' => $modelos], $usuario, $ip, sedeId: null === $c['SED_Sede'] ? null : (int) $c['SED_Sede']);

            return $this->compatibles($id);
        });
    }

    /**
     * Consumibles instalados en un equipo (historial) y los compatibles con su modelo.
     *
     * @return array<string, mixed>
     */
    public function consumoDeEquipo(string $activo): array
    {
        $equipo = $this->datos->equipoVisible($activo) ?: throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
        $historial = $this->datos->consumoDeActivo($activo);
        $compatibles = $this->datos->compatiblesConModelo((int) $equipo['MOD_Modelo']);

        return [
            'historial' => array_map(static fn (array $h): array => ['MOC_Movimiento' => (int) $h['MOC_Movimiento'],
                'MOC_Fecha' => Reloj::iso(Reloj::desdeBd((string) $h['MOC_Fecha'])), 'CON_Consumible' => (int) $h['CON_Consumible'],
                'consumible' => $h['CON_Nombre'], 'unidad' => $h['CON_Unidad'], 'MOC_Cantidad' => (int) $h['MOC_Cantidad'],
                'MOC_Motivo' => $h['MOC_Motivo'], 'usuario' => $h['USU_Username']], $historial),
            'compatibles' => array_map(static fn (array $c): array => ['CON_Consumible' => (int) $c['CON_Consumible'], 'CON_Nombre' => $c['CON_Nombre'],
                'CON_Stock_Actual' => (int) $c['CON_Stock_Actual'], 'CON_Unidad' => $c['CON_Unidad'],
                'SED_Sede' => null === $c['SED_Sede'] ? null : (int) $c['SED_Sede']], $compatibles),
        ];
    }
}
