<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Correo\Notificador;
use App\Crud\Esquema;
use App\Db\InsercionOracle;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Http\ErrorApi;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use App\Seguridad\CifradoCampos;
use Doctrine\DBAL\Connection;

/**
 * Compras: proveedores, órdenes de compra con sus líneas, recepciones (lazo
 * cerrado: la entrega suma stock, da de alta activos y registra licencias) y la
 * vista de garantías. Cancelar, cerrar con faltante y recibir un sobrante solo
 * se ejecutan aprobados por otra persona (App\Inventario\Aprobaciones).
 */
final readonly class Compras
{
    /** Tolerancia de precio en la recepción (RECEPCION_TOLERANCIA_PRECIO_PCT del backend anterior). */
    private const string TOLERANCIA_PCT = '2.0';
    private const string PROVEEDOR = '"PRV_Proveedor", "PRV_Nombre", "PRV_Identificacion_Fiscal", "PRV_Contacto", "PRV_Email", "PRV_Telefono", "PRV_Direccion", "PRV_Activo"';
    private const string ORDEN = 'o."OCO_Orden", o."OCO_Numero", o."OCO_Fecha", o."OCO_Estado", o."OCO_Moneda", o."OCO_Total", o."OCO_Notas", o."OCO_Motivo_Cierre", '
        .'o."PRV_Proveedor", o."SED_Sede", o."CREATED_AT", p."PRV_Nombre"';

    public function __construct(
        private Connection $conexion,
        private Alcance $alcance,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
        private Notificador $notificador,
        private Secuencias $secuencias,
        private EstadosActivo $estados,
        private Software $software,
        private Solicitudes $solicitudes,
        private CifradoCampos $cifrado,
    ) {
    }

    /**
     * Las claves de licencia de una recepción que espera aprobación viajan cifradas en APR_Payload:
     * las aprobaciones las listan quienes aprueban y nunca deben mostrar un secreto (fase 8).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function protegerClaves(array $payload): array
    {
        return [...self::mapearClaves($payload, $this->cifrado->cifrar(...)), 'claves_cifradas' => true];
    }

    /**
     * Inverso de protegerClaves al aplicar la aprobación (los payloads anteriores, sin la marca, quedan igual).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function abrirClaves(array $payload): array
    {
        if (true !== ($payload['claves_cifradas'] ?? false)) {
            return $payload;
        }
        unset($payload['claves_cifradas']);

        return self::mapearClaves($payload, $this->cifrado->descifrar(...));
    }

    /**
     * @param array<string, mixed>          $payload
     * @param \Closure(?string): ?string    $f
     *
     * @return array<string, mixed>
     */
    private static function mapearClaves(array $payload, \Closure $f): array
    {
        foreach (\is_array($payload['licencias'] ?? null) ? $payload['licencias'] : [] as $i => $l) {
            if (isset($l['LIC_Clave_Activacion'])) {
                $payload['licencias'][$i]['LIC_Clave_Activacion'] = $f((string) $l['LIC_Clave_Activacion']);
            }
            foreach (\is_array($l['claves'] ?? null) ? $l['claves'] : [] as $j => $c) {
                $payload['licencias'][$i]['claves'][$j]['LCL_Clave_Activacion'] = $f((string) $c['LCL_Clave_Activacion']);
            }
        }

        return $payload;
    }

    // =====================================================================
    // Proveedores
    // =====================================================================

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private static function proveedorAApi(array $f): array
    {
        return ['PRV_Nombre' => $f['PRV_Nombre'], 'PRV_Identificacion_Fiscal' => $f['PRV_Identificacion_Fiscal'], 'PRV_Contacto' => $f['PRV_Contacto'],
            'PRV_Email' => $f['PRV_Email'], 'PRV_Telefono' => $f['PRV_Telefono'], 'PRV_Direccion' => $f['PRV_Direccion'],
            'PRV_Proveedor' => (int) $f['PRV_Proveedor'], 'PRV_Activo' => 1 === (int) $f['PRV_Activo']];
    }

    /** @return list<array<string, mixed>> */
    public function proveedores(bool $soloActivos): array
    {
        return array_map(self::proveedorAApi(...), $this->conexion->fetchAllAssociative('SELECT '.self::PROVEEDOR.' FROM "INV_PROVEEDOR"'
            .($soloActivos ? ' WHERE "PRV_Activo" = 1' : '').' ORDER BY "PRV_Nombre", "PRV_Proveedor"'));
    }

    /** @return array<string, mixed> */
    public function proveedor(int $id): array
    {
        $f = $this->conexion->fetchAssociative('SELECT '.self::PROVEEDOR.' FROM "INV_PROVEEDOR" WHERE "PRV_Proveedor" = :id', ['id' => $id]);

        return false === $f ? throw new ErrorApi(404, 'SUPPLIER_NOT_FOUND') : self::proveedorAApi($f);
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public function crearProveedor(array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $usuario, $ip): array {
            if (false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_PROVEEDOR" WHERE "PRV_Nombre" = :n FETCH FIRST 1 ROWS ONLY', ['n' => $d['PRV_Nombre']])) {
                throw new ErrorApi(409, 'SUPPLIER_ALREADY_EXISTS');
            }
            $id = (int) InsercionOracle::insertar($this->conexion, 'INV_PROVEEDOR', [...$d, 'PRV_Activo' => 1], 'PRV_Proveedor');
            $this->gobierno->registrarAuditoria('CREATE', 'INV_PROVEEDOR', ['nombre' => $d['PRV_Nombre']], $usuario, $ip);

            return $this->proveedor($id);
        });
    }

    /**
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    public function cambiarProveedor(int $id, array $cambios, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $cambios, $usuario, $ip): array {
            $this->proveedor($id);
            if ([] !== $cambios) {
                $sets = [];
                foreach (array_keys($cambios) as $c) {
                    $sets[] = "\"{$c}\" = :{$c}";
                }
                $this->conexion->executeStatement('UPDATE "INV_PROVEEDOR" SET '.implode(', ', $sets).', "UPDATED_AT" = :upd WHERE "PRV_Proveedor" = :id',
                    [...array_map(static fn (mixed $v): mixed => \is_bool($v) ? (int) $v : $v, $cambios), 'upd' => Reloj::aBd(Reloj::ahora()), 'id' => $id]);
            }
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_PROVEEDOR', ['id' => $id, 'cambios' => (object) $cambios], $usuario, $ip);

            return $this->proveedor($id);
        });
    }

    public function eliminarProveedor(int $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $p = $this->proveedor($id);
            if (false !== $this->alcance->elevado(fn (): mixed => $this->conexion->fetchOne(
                'SELECT 1 FROM "INV_ORDEN_COMPRA" WHERE "PRV_Proveedor" = :id FETCH FIRST 1 ROWS ONLY', ['id' => $id]))) {
                throw new ErrorApi(409, 'CANNOT_DELETE_SUPPLIER_HAS_ORDERS');
            }
            $this->conexion->executeStatement('DELETE FROM "INV_PROVEEDOR" WHERE "PRV_Proveedor" = :id', ['id' => $id]);
            $this->gobierno->registrarAuditoria('DELETE', 'INV_PROVEEDOR', ['id' => $id, 'nombre' => $p['PRV_Nombre']], $usuario, $ip);
        });
    }

    // =====================================================================
    // Órdenes de compra
    // =====================================================================

    /** @return array<string, mixed>|null */
    public function filaOrden(int $id, bool $bloquear = false): ?array
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ORDEN_COMPRA', 'o');
        if ($bloquear) {
            $this->conexion->fetchOne('SELECT 1 FROM "INV_ORDEN_COMPRA" WHERE "OCO_Orden" = :id FOR UPDATE', ['id' => $id]);
        }
        $f = $this->conexion->fetchAssociative('SELECT '.self::ORDEN.' FROM "INV_ORDEN_COMPRA" o LEFT JOIN "INV_PROVEEDOR" p ON p."PRV_Proveedor" = o."PRV_Proveedor" '
            .'WHERE o."OCO_Orden" = :id AND '.$alcance, [...$p, 'id' => $id]);

        return false === $f ? null : $f;
    }

    /** @return array<string, mixed> */
    public function filaOrdenO404(int $id, bool $bloquear = false): array
    {
        return $this->filaOrden($id, $bloquear) ?? throw new ErrorApi(404, 'PURCHASE_ORDER_NOT_FOUND');
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed> OrdenResponse
     */
    private static function ordenAApi(array $o): array
    {
        return ['OCO_Orden' => (int) $o['OCO_Orden'], 'OCO_Numero' => $o['OCO_Numero'], 'OCO_Fecha' => substr((string) $o['OCO_Fecha'], 0, 10),
            'OCO_Estado' => $o['OCO_Estado'], 'OCO_Moneda' => $o['OCO_Moneda'], 'OCO_Total' => Esquema::decimal((string) $o['OCO_Total'], 2),
            'OCO_Notas' => $o['OCO_Notas'], 'OCO_Motivo_Cierre' => $o['OCO_Motivo_Cierre'], 'PRV_Proveedor' => (int) $o['PRV_Proveedor'],
            'SED_Sede' => null === $o['SED_Sede'] ? null : (int) $o['SED_Sede'],
            'proveedor' => null === $o['PRV_Nombre'] ? null : ['PRV_Proveedor' => (int) $o['PRV_Proveedor'], 'PRV_Nombre' => $o['PRV_Nombre']],
            'created_at' => Reloj::iso(Reloj::desdeBd((string) $o['CREATED_AT']))];
    }

    /** @return list<array<string, mixed>> */
    public function lineas(int $orden): array
    {
        return $this->conexion->fetchAllAssociative('SELECT "OCL_Linea", "OCL_Descripcion", "OCL_Cantidad", "OCL_Precio_Unitario", "OCL_Subtotal", "OCL_Tipo", '
            .'"OCL_Cantidad_Recibida", "ACT_Activo", "CON_Consumible", "LIC_Licencia" FROM "INV_ORDEN_COMPRA_LINEA" WHERE "OCO_Orden" = :o ORDER BY "OCL_Linea"', ['o' => $orden]);
    }

    /**
     * @param array<string, mixed> $l
     *
     * @return array<string, mixed> LineaResponse
     */
    private static function lineaAApi(array $l): array
    {
        $recibida = (int) $l['OCL_Cantidad_Recibida'];

        return ['OCL_Linea' => (int) $l['OCL_Linea'], 'OCL_Descripcion' => $l['OCL_Descripcion'], 'OCL_Cantidad' => (int) $l['OCL_Cantidad'],
            'OCL_Precio_Unitario' => Esquema::decimal((string) $l['OCL_Precio_Unitario'], 2), 'OCL_Subtotal' => Esquema::decimal((string) $l['OCL_Subtotal'], 2),
            'OCL_Tipo' => $l['OCL_Tipo'], 'OCL_Cantidad_Recibida' => $recibida,
            'ACT_Activo' => null === $l['ACT_Activo'] ? null : Ident::aTexto((string) $l['ACT_Activo']),
            'CON_Consumible' => null === $l['CON_Consumible'] ? null : (int) $l['CON_Consumible'],
            'LIC_Licencia' => null === $l['LIC_Licencia'] ? null : (int) $l['LIC_Licencia'],
            'OCL_Pendiente' => max((int) $l['OCL_Cantidad'] - $recibida, 0)];
    }

    /** @return array<string, mixed> OrdenDetalleResponse */
    public function orden(int $id): array
    {
        return [...self::ordenAApi($this->filaOrdenO404($id)), 'lineas' => array_map(self::lineaAApi(...), $this->lineas($id))];
    }

    /** @return list<array<string, mixed>> */
    public function ordenes(?string $estado): array
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ORDEN_COMPRA', 'o');
        if (null !== $estado && '' !== $estado) {
            $alcance .= ' AND o."OCO_Estado" = :estado';
            $p['estado'] = $estado;
        }

        return array_map(self::ordenAApi(...), $this->conexion->fetchAllAssociative('SELECT '.self::ORDEN.' FROM "INV_ORDEN_COMPRA" o '
            .'LEFT JOIN "INV_PROVEEDOR" p ON p."PRV_Proveedor" = o."PRV_Proveedor" WHERE '.$alcance.' ORDER BY o."OCO_Fecha" DESC, o."OCO_Orden" DESC', $p));
    }

    /**
     * @param array<string, mixed>       $d
     * @param list<array<string, mixed>> $lineas
     *
     * @return array<string, mixed>
     */
    public function crearOrden(array $d, array $lineas, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $lineas, $usuario, $ip): array {
            $sede = $this->alcance->exigirSede($d['SED_Sede'], requerida: false);
            $this->proveedor((int) $d['PRV_Proveedor']);
            // El número de orden es único en toda la empresa, no solo en el alcance.
            if ($this->alcance->elevado(fn (): bool => false !== $this->conexion->fetchOne(
                'SELECT 1 FROM "INV_ORDEN_COMPRA" WHERE "OCO_Numero" = :n FETCH FIRST 1 ROWS ONLY', ['n' => $d['OCO_Numero']]))) {
                throw new ErrorApi(409, 'PURCHASE_ORDER_NUMBER_EXISTS');
            }
            // Las líneas solo pueden referenciar activos y consumibles visibles.
            [$aAct, $pAct] = $this->alcance->lectura('INV_ACTIVO', 'a');
            [$aCon, $pCon] = $this->alcance->lectura('INV_CONSUMIBLE', 'c');
            foreach ($lineas as $l) {
                if (null !== $l['ACT_Activo'] && false === $this->conexion->fetchOne('SELECT 1 FROM "INV_ACTIVO" a WHERE a."ACT_Activo" = :x AND '.$aAct, [...$pAct, 'x' => $l['ACT_Activo']])) {
                    throw new ErrorApi(404, 'ASSET_NOT_FOUND');
                }
                if (null !== $l['CON_Consumible'] && false === $this->conexion->fetchOne('SELECT 1 FROM "INV_CONSUMIBLE" c WHERE c."CON_Consumible" = :x AND '.$aCon,
                    [...$pCon, 'x' => $l['CON_Consumible']])) {
                    throw new ErrorApi(404, 'CONSUMABLE_NOT_FOUND');
                }
            }
            $total = '0';
            foreach ($lineas as $i => $l) {
                $lineas[$i]['subtotal'] = Valoracion::redondear(bcmul((string) $l['OCL_Precio_Unitario'], (string) $l['OCL_Cantidad'], 6), 2);
                $total = bcadd($total, $lineas[$i]['subtotal'], 2);
            }
            $id = (int) InsercionOracle::insertar($this->conexion, 'INV_ORDEN_COMPRA', ['OCO_Numero' => $d['OCO_Numero'], 'OCO_Fecha' => $d['OCO_Fecha'],
                'OCO_Estado' => 'BORRADOR', 'OCO_Moneda' => $d['OCO_Moneda'], 'OCO_Total' => $total, 'OCO_Notas' => $d['OCO_Notas'],
                'PRV_Proveedor' => $d['PRV_Proveedor'], 'USU_Usuario' => $usuario, 'SED_Sede' => $sede], 'OCO_Orden');
            foreach ($lineas as $l) {
                InsercionOracle::insertar($this->conexion, 'INV_ORDEN_COMPRA_LINEA', ['OCL_Descripcion' => $l['OCL_Descripcion'], 'OCL_Cantidad' => $l['OCL_Cantidad'],
                    'OCL_Precio_Unitario' => $l['OCL_Precio_Unitario'], 'OCL_Subtotal' => $l['subtotal'], 'OCL_Tipo' => $l['OCL_Tipo'], 'OCL_Cantidad_Recibida' => 0,
                    'OCO_Orden' => $id, 'ACT_Activo' => $l['ACT_Activo'], 'CON_Consumible' => $l['CON_Consumible'], 'LIC_Licencia' => $l['LIC_Licencia']], 'OCL_Linea');
            }
            $this->gobierno->registrarAuditoria('CREATE', 'INV_ORDEN_COMPRA', ['numero' => $d['OCO_Numero'], 'proveedor_id' => (int) $d['PRV_Proveedor'],
                'moneda' => $d['OCO_Moneda'], 'sede' => $sede, 'total' => $total, 'lineas' => \count($lineas)], $usuario, $ip, sedeId: $sede);

            return $this->alcance->elevado(fn (): array => $this->orden($id));
        });
    }

    /**
     * Cancela un borrador (solo aprobada). Recibida, cerrada y cancelada son finales.
     *
     * @param array<string, mixed>|null $aprobacion
     */
    public function cancelar(int $id, ?string $motivo, string $usuario, ?string $ip, ?array $aprobacion): void
    {
        if (null === $aprobacion) {
            throw new ErrorApi(409, 'ORDER_CANCEL_REQUIRES_APPROVAL');
        }
        $this->transaccion->ejecutar(function () use ($id, $motivo, $usuario, $ip, $aprobacion): void {
            $o = $this->filaOrdenO404($id, true);
            if ('CANCELADA' === $o['OCO_Estado']) {
                throw new ErrorApi(409, 'ORDER_ALREADY_CANCELLED');
            }
            if ('PARCIAL' === $o['OCO_Estado']) {
                throw new ErrorApi(409, 'ORDER_HAS_RECEIPTS_CLOSE_WITH_SHORTAGE');
            }
            if ('BORRADOR' !== $o['OCO_Estado']) {
                throw new ErrorApi(409, 'ORDER_STATE_TRANSITION_NOT_ALLOWED');
            }
            $this->conexion->executeStatement("UPDATE \"INV_ORDEN_COMPRA\" SET \"OCO_Estado\" = 'CANCELADA'"
                .(null !== $motivo && '' !== $motivo ? ', "OCO_Motivo_Cierre" = :m' : '').' WHERE "OCO_Orden" = :id',
                null !== $motivo && '' !== $motivo ? ['m' => $motivo, 'id' => $id] : ['id' => $id]);
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_ORDEN_COMPRA', ['id' => $id, 'diff' => ['OCO_Estado' => ['antes' => $o['OCO_Estado'],
                'despues' => 'CANCELADA']], 'motivo' => $motivo, 'aprobacion' => self::textos($aprobacion)], $usuario, $ip,
                sedeId: null === $o['SED_Sede'] ? null : (int) $o['SED_Sede']);
        });
    }

    /**
     * Cierra una orden PARCIAL aceptando que lo pendiente no llegará (solo aprobada).
     *
     * @param array<string, mixed>|null $aprobacion
     */
    public function cerrarConFaltante(int $id, string $motivo, string $usuario, ?string $ip, ?array $aprobacion): void
    {
        if (null === $aprobacion) {
            throw new ErrorApi(409, 'ORDER_CLOSE_REQUIRES_APPROVAL');
        }
        $this->transaccion->ejecutar(function () use ($id, $motivo, $usuario, $ip, $aprobacion): void {
            $o = $this->filaOrdenO404($id, true);
            if ('PARCIAL' !== $o['OCO_Estado']) {
                throw new ErrorApi(409, 'ONLY_PARTIAL_ORDERS_CAN_BE_CLOSED');
            }
            $faltantes = [];
            foreach ($this->lineas($id) as $l) {
                if ((int) $l['OCL_Cantidad_Recibida'] < (int) $l['OCL_Cantidad']) {
                    $faltantes[] = ['linea' => (int) $l['OCL_Linea'], 'descripcion' => $l['OCL_Descripcion'],
                        'pendiente' => (int) $l['OCL_Cantidad'] - (int) $l['OCL_Cantidad_Recibida']];
                }
            }
            $this->conexion->executeStatement("UPDATE \"INV_ORDEN_COMPRA\" SET \"OCO_Estado\" = 'CERRADA_CON_FALTANTE', \"OCO_Motivo_Cierre\" = :m WHERE \"OCO_Orden\" = :id",
                ['m' => $motivo, 'id' => $id]);
            $this->gobierno->registrarAuditoria('CLOSE_WITH_SHORTAGE', 'INV_ORDEN_COMPRA', ['id' => $id, 'numero' => $o['OCO_Numero'], 'motivo' => $motivo,
                'faltantes' => $faltantes, 'aprobacion' => self::textos($aprobacion)], $usuario, $ip, sedeId: null === $o['SED_Sede'] ? null : (int) $o['SED_Sede']);
            $this->avisarDiferencias((string) $o['OCO_Numero'], 'cierre_con_faltante', $faltantes, [], $motivo);
        });
    }

    /** @return list<array<string, mixed>> RecepcionResponse */
    public function recepciones(int $orden): array
    {
        $this->filaOrdenO404($orden);
        $recepciones = $this->conexion->fetchAllAssociative('SELECT "REC_Recepcion", "OCO_Orden", "REC_Fecha", "REC_Documento", "REC_Observacion", '
            .'"REC_Resultado", "USU_Usuario" FROM "INV_RECEPCION" WHERE "OCO_Orden" = :o ORDER BY "REC_Fecha", "REC_Recepcion"', ['o' => $orden]);
        $salida = [];
        foreach ($recepciones as $r) {
            $salida[] = ['REC_Recepcion' => (int) $r['REC_Recepcion'], 'OCO_Orden' => (int) $r['OCO_Orden'], 'REC_Fecha' => Reloj::iso(Reloj::desdeBd((string) $r['REC_Fecha'])),
                'REC_Documento' => $r['REC_Documento'], 'REC_Observacion' => $r['REC_Observacion'], 'REC_Resultado' => $r['REC_Resultado'],
                'USU_Usuario' => null === $r['USU_Usuario'] ? null : Ident::aTexto((string) $r['USU_Usuario']),
                'lineas' => array_map(static fn (array $l): array => ['OCL_Linea' => (int) $l['OCL_Linea'], 'RCL_Cantidad' => (int) $l['RCL_Cantidad'],
                    'RCL_Precio_Real' => null === $l['RCL_Precio_Real'] ? null : Esquema::decimal((string) $l['RCL_Precio_Real'], 2),
                    'RCL_Diferencia' => $l['RCL_Diferencia'], 'RCL_Motivo' => $l['RCL_Motivo']],
                    $this->conexion->fetchAllAssociative('SELECT "OCL_Linea", "RCL_Cantidad", "RCL_Precio_Real", "RCL_Diferencia", "RCL_Motivo" FROM "INV_RECEPCION_LINEA" '
                        .'WHERE "REC_Recepcion" = :r ORDER BY "RCL_Recepcion_Linea"', ['r' => (int) $r['REC_Recepcion']]))];
        }

        return $salida;
    }

    // =====================================================================
    // Recepción: documento de entrada con control de diferencias
    // =====================================================================

    /**
     * Registra una ENTREGA (una orden puede recibirse en varias). Cada línea
     * acepta a lo sumo lo pendiente; un sobrante o un precio fuera de tolerancia
     * exigen justificación, y un sobrante además la aprobación de otra persona.
     * Todo en una transacción: si algo falla, no entra nada.
     *
     * @param array<string, mixed>      $r          RecepcionOrden validada
     * @param array<string, mixed>|null $aprobacion
     *
     * @return array<string, mixed> RecepcionResultado
     */
    public function recibir(int $id, array $r, string $usuario, ?string $ip, ?array $aprobacion = null): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $r, $usuario, $ip, $aprobacion): array {
            $o = $this->filaOrdenO404($id, true);
            if (!\in_array($o['OCO_Estado'], ['BORRADOR', 'PARCIAL'], true)) {
                throw new ErrorApi(409, 'ORDER_NOT_RECEIVABLE');
            }
            if ([] === $r['consumibles'] && [] === $r['activos'] && [] === $r['licencias'] && [] === $r['otros']) {
                throw new ErrorApi(400, 'EMPTY_RECEIPT');
            }
            $numero = (string) $o['OCO_Numero'];
            $moneda = (string) $o['OCO_Moneda'];
            $sede = null === $o['SED_Sede'] ? null : (int) $o['SED_Sede'];
            $lineas = [];
            foreach ($this->lineas($id) as $l) {
                $lineas[(int) $l['OCL_Linea']] = $l;
            }
            $justificacion = [];
            foreach ($r['diferencias'] as $d) {
                $justificacion[$d['OCL_Linea']] = $d;
            }
            $documento = ['RECEPCION', "OC {$numero}".(null !== $r['documento'] && '' !== $r['documento'] ? " · {$r['documento']}" : '')];

            // --- 0. Validaciones por línea: tipo, cantidad y precio ---
            $porLinea = [];
            $acumular = static function (int $linea, string $tipo, int $cantidad, ?string $precio) use (&$porLinea, $lineas): void {
                $l = $lineas[$linea] ?? throw new ErrorApi(400, 'LINE_NOT_IN_ORDER');
                if (null !== $l['OCL_Tipo'] && $l['OCL_Tipo'] !== $tipo) {
                    throw new ErrorApi(400, "LINE_TYPE_MISMATCH:{$linea}");
                }
                $porLinea[$linea] ??= ['tipo' => $tipo, 'cantidad' => 0, 'precios' => []];
                if ($porLinea[$linea]['tipo'] !== $tipo) {
                    throw new ErrorApi(400, "LINE_TYPE_MISMATCH:{$linea}");
                }
                $porLinea[$linea]['cantidad'] += $cantidad;
                if (null !== $precio) {
                    $porLinea[$linea]['precios'][] = $precio;
                }
            };
            foreach ($r['consumibles'] as $c) {
                $acumular($c['OCL_Linea'], 'CONSUMIBLE', $c['cantidad'], $c['precio_unitario']);
                $l = $lineas[$c['OCL_Linea']];
                if (null !== $l['CON_Consumible'] && (int) $l['CON_Consumible'] !== $c['CON_Consumible']) {
                    throw new ErrorApi(400, "CONSUMABLE_DOES_NOT_MATCH_LINE:{$c['OCL_Linea']}");
                }
            }
            foreach ($r['activos'] as $a) {
                $acumular($a['OCL_Linea'], 'ACTIVO', 1, $a['ACT_Costo']);
            }
            foreach ($r['licencias'] as $l) {
                $acumular($l['OCL_Linea'], 'LICENCIA', (int) $l['cantidad'], $l['precio_unitario']);
            }
            // Fletes, instalación o servicios: solo se da conformidad (sin inventario).
            foreach ($r['otros'] as $x) {
                $acumular($x['OCL_Linea'], 'OTRO', $x['cantidad'], $x['precio_unitario']);
            }
            $tolerancia = bcdiv(self::TOLERANCIA_PCT, '100', 6);
            $diferencias = [];
            foreach ($porLinea as $lineaId => &$item) {
                $l = $lineas[$lineaId];
                $pendiente = (int) $l['OCL_Cantidad'] - (int) $l['OCL_Cantidad_Recibida'];
                $item['diferencia'] = null;
                if ($item['cantidad'] > $pendiente) {
                    $just = $justificacion[$lineaId] ?? null;
                    if (null === $just || !$just['aceptar_sobrante']) {
                        throw new ErrorApi(400, "RECEIPT_EXCEEDS_PENDING:{$lineaId}:".max($pendiente, 0));
                    }
                    $item['diferencia'] = 'SOBRANTE';
                    $diferencias[] = ['OCL_Linea' => $lineaId, 'tipo' => 'SOBRANTE', 'motivo' => $just['motivo'],
                        'detalle' => "{$l['OCL_Descripcion']}: pendiente ".max($pendiente, 0).", recibido {$item['cantidad']}"];
                }
                $precioOrden = (string) $l['OCL_Precio_Unitario'];
                $margen = bcmul($precioOrden, $tolerancia, 6);
                $fuera = array_values(array_filter($item['precios'], static fn (string $p): bool => 1 === bccomp(ltrim(bcsub($p, $precioOrden, 6), '-'), $margen, 6)));
                if ([] !== $fuera) {
                    $just = $justificacion[$lineaId] ?? throw new ErrorApi(400, "PRICE_DIFFERENCE_REQUIRES_JUSTIFICATION:{$lineaId}");
                    $item['diferencia'] ??= 'PRECIO';
                    $diferencias[] = ['OCL_Linea' => $lineaId, 'tipo' => 'PRECIO', 'motivo' => $just['motivo'],
                        'detalle' => "{$l['OCL_Descripcion']}: orden ".Esquema::decimal($precioOrden, 2)." {$moneda}, real {$fuera[0]} {$moneda}"];
                }
                $item['precio_real'] = $item['precios'][0] ?? null;
            }
            unset($item);
            $series = array_map(static fn (array $a): string => mb_strtolower(trim($a['ACT_Serie_Fabricante'])), $r['activos']);
            if (\count($series) !== \count(array_unique($series))) {
                throw new ErrorApi(400, 'DUPLICATE_SERIAL_NUMBER_IN_RECEIPT');
            }
            $pendientes = function () use ($id): array {
                $salida = [];
                foreach ($this->lineas($id) as $l) {
                    if ((int) $l['OCL_Cantidad_Recibida'] < (int) $l['OCL_Cantidad']) {
                        $salida[] = ['OCL_Linea' => (int) $l['OCL_Linea'], 'descripcion' => $l['OCL_Descripcion'], 'tipo' => $l['OCL_Tipo'],
                            'pedido' => (int) $l['OCL_Cantidad'], 'recibido' => (int) $l['OCL_Cantidad_Recibida'],
                            'pendiente' => (int) $l['OCL_Cantidad'] - (int) $l['OCL_Cantidad_Recibida']];
                    }
                }

                return $salida;
            };

            // Separación de funciones: un sobrante no entra hasta que otra persona lo aprueba.
            $sobrantes = array_values(array_filter($diferencias, static fn (array $d): bool => 'SOBRANTE' === $d['tipo']));
            if ([] !== $sobrantes && null === $aprobacion) {
                // Con la orden bloqueada: un reintento no abre otra solicitud (aprobar dos duplicaría el ingreso).
                $this->solicitudes->exigirSinPendiente('RECEPCION_SOBRANTE', orden: $id);
                $solicitud = $this->solicitudes->crear('RECEPCION_SOBRANTE', $this->protegerClaves($r['payload']), $usuario,
                    mb_substr("Sobrante en la OC {$numero}: ".implode('; ', array_column($sobrantes, 'detalle')), 0, 500),
                    mb_substr(implode('; ', array_map(static fn (array $d): string => (string) $d['motivo'], $sobrantes)), 0, 500),
                    orden: $id, sede: $sede, ip: $ip);

                return ['OCO_Orden' => $id, 'OCO_Estado' => $o['OCO_Estado'], 'REC_Recepcion' => null, 'consumibles_reabastecidos' => 0, 'activos_creados' => 0,
                    'licencias_creadas' => 0, 'licencias_claves_creadas' => 0, 'activos_codigos' => [], 'pendientes' => $pendientes(),
                    'diferencias' => self::diferenciasAApi($diferencias), 'aprobacion_pendiente' => $solicitud];
            }
            $detalleAprobacion = null === $aprobacion ? null : $aprobacion;

            // --- 1. Consumibles: sumar stock (entrada auditada) ---
            $reabastecidos = 0;
            foreach ($r['consumibles'] as $c) {
                $con = $this->conexion->fetchAssociative('SELECT "CON_Consumible", "CON_Nombre", "CON_Stock_Actual", "CON_Costo_Unitario", "CON_Moneda", "SED_Sede" '
                    .'FROM "INV_CONSUMIBLE" WHERE "CON_Consumible" = :c FOR UPDATE', ['c' => $c['CON_Consumible']]) ?: throw new ErrorApi(404, 'CONSUMABLE_NOT_FOUND');
                $precio = $c['precio_unitario'] ?? (string) $lineas[$c['OCL_Linea']]['OCL_Precio_Unitario'];
                [$promedio, $monedaVal, $costo] = Valoracion::entrada((int) $con['CON_Stock_Actual'],
                    null === $con['CON_Costo_Unitario'] ? null : (string) $con['CON_Costo_Unitario'], $con['CON_Moneda'], $c['cantidad'], $precio, $moneda);
                $saldo = (int) $con['CON_Stock_Actual'] + $c['cantidad'];
                $this->conexion->executeStatement('UPDATE "INV_CONSUMIBLE" SET "CON_Stock_Actual" = "CON_Stock_Actual" + :n, "CON_Costo_Unitario" = :p, '
                    .'"CON_Moneda" = :m WHERE "CON_Consumible" = :c', ['n' => $c['cantidad'], 'p' => $promedio, 'm' => $monedaVal, 'c' => $c['CON_Consumible']]);
                InsercionOracle::insertar($this->conexion, 'INV_MOVIMIENTO_CONSUMIBLE', ['MOC_Tipo' => 'ENTRADA', 'MOC_Cantidad' => $c['cantidad'],
                    'MOC_Stock_Resultante' => $saldo, 'MOC_Motivo' => "Recepción OC {$numero}", 'MOC_Fecha' => Reloj::aBd(Reloj::ahora()),
                    'CON_Consumible' => $c['CON_Consumible'], 'USU_Usuario' => $usuario], 'MOC_Movimiento');
                $conSede = null === $con['SED_Sede'] ? null : (int) $con['SED_Sede'];
                $this->kardex->registrar('CONSUMIBLE_ENTRADA', consumible: (int) $con['CON_Consumible'], referencia: $con['CON_Nombre'], cantidad: $c['cantidad'],
                    saldo: $saldo, costo: $costo, moneda: $monedaVal, saldoValor: Valoracion::valorSaldo($saldo, $promedio), destino: ['sede' => $conSede],
                    documento: $documento, usuario: $usuario, motivo: "Recepción OC {$numero}", detalle: $detalleAprobacion);
                $this->conexion->executeStatement('UPDATE "INV_ORDEN_COMPRA_LINEA" SET "CON_Consumible" = :c WHERE "OCL_Linea" = :l',
                    ['c' => $c['CON_Consumible'], 'l' => $c['OCL_Linea']]);
                $this->gobierno->registrarAuditoria('STOCK_IN', 'INV_CONSUMIBLE', ['id' => (int) $c['CON_Consumible'], 'cantidad' => $c['cantidad'],
                    'stock_resultante' => $saldo, 'motivo' => "Recepción OC {$numero}"], $usuario, $ip, sedeId: $conSede);
                ++$reabastecidos;
            }

            // --- 2. Activos: alta + enlace a la línea (estado En Bodega) ---
            $disponible = [] === $r['activos'] ? null : ($this->estados->idDe(EstadosActivo::BODEGA)
                ?? throw new ErrorApi(500, 'SYSTEM_CONFIGURATION_ERROR_MISSING_STATUS_BODEGA'));
            $codigos = [];
            foreach ($r['activos'] as $a) {
                $tipo = $this->conexion->fetchAssociative('SELECT "TAC_Prefijo" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $a['TAC_Tipo_Activo']])
                    ?: throw new ErrorApi(404, 'ASSET_TYPE_NOT_FOUND');
                if (false === $this->conexion->fetchOne('SELECT 1 FROM "INV_MODELO" WHERE "MOD_Modelo" = :m', ['m' => $a['MOD_Modelo']])) {
                    throw new ErrorApi(404, 'MODEL_NOT_FOUND');
                }
                $codigo = $a['ACT_Codigo_Interno'];
                if (null === $codigo) {
                    if (null === $tipo['TAC_Prefijo'] || '' === $tipo['TAC_Prefijo']) {
                        throw new ErrorApi(400, 'ASSET_TYPE_HAS_NO_PREFIX_CONFIGURED_FOR_AUTO_GENERATION');
                    }
                    $codigo = $this->secuencias->siguiente("ASSET_{$tipo['TAC_Prefijo']}", (string) $tipo['TAC_Prefijo']);
                }
                [$codigoExiste, $serieExiste] = $this->alcance->elevado(fn (): array => [
                    false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_ACTIVO" WHERE "ACT_Codigo_Interno" = :c FETCH FIRST 1 ROWS ONLY', ['c' => $codigo]),
                    false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_ACTIVO" WHERE "ACT_Serie_Fabricante" = :s FETCH FIRST 1 ROWS ONLY', ['s' => $a['ACT_Serie_Fabricante']]),
                ]);
                if ($codigoExiste) {
                    throw new ErrorApi(400, 'ASSET_CODE_ALREADY_EXISTS');
                }
                if ($serieExiste) {
                    throw new ErrorApi(400, 'SERIAL_NUMBER_ALREADY_EXISTS');
                }
                if (null !== $a['ACT_Fin_Garantia'] && $a['ACT_Fin_Garantia'] < $a['ACT_Fecha_Compra']) {
                    throw new ErrorApi(400, 'WARRANTY_DATE_CANNOT_BE_BEFORE_PURCHASE_DATE');
                }
                $costo = $a['ACT_Costo'] ?? (string) $lineas[$a['OCL_Linea']]['OCL_Precio_Unitario'];
                $activoId = Ident::nuevo();
                $this->conexion->executeStatement('INSERT INTO "INV_ACTIVO" ("ACT_Activo", "ACT_Codigo_Interno", "ACT_Serie_Fabricante", "ACT_Hostname", "ACT_Fecha_Compra", '
                    .'"ACT_Fin_Garantia", "ACT_Costo", "ACT_Moneda", "MOD_Modelo", "TAC_Tipo_Activo", "EOP_Estado_Operativo", "SED_Sede", "ACT_Instalado", "PRV_Proveedor") VALUES '
                    ."(:id, :codigo, :serie, :host, TO_DATE(:compra, 'YYYY-MM-DD'), TO_DATE(:garantia, 'YYYY-MM-DD'), :costo, :moneda, :modelo, :tipo, :estado, :sede, 0, :proveedor)",
                    ['id' => $activoId, 'codigo' => $codigo, 'serie' => $a['ACT_Serie_Fabricante'], 'host' => $a['ACT_Hostname'], 'compra' => $a['ACT_Fecha_Compra'],
                        'garantia' => $a['ACT_Fin_Garantia'], 'costo' => $costo, 'moneda' => $moneda, 'modelo' => $a['MOD_Modelo'], 'tipo' => $a['TAC_Tipo_Activo'],
                        // F-14: el activo nace con el proveedor de la orden (garantías y reclamos).
                        'estado' => $disponible, 'sede' => $sede, 'proveedor' => (int) $o['PRV_Proveedor']]);
                // Enlazar la línea al activo (alimenta garantías ↔ proveedor).
                $this->conexion->executeStatement('UPDATE "INV_ORDEN_COMPRA_LINEA" SET "ACT_Activo" = :a WHERE "OCL_Linea" = :l AND "ACT_Activo" IS NULL',
                    ['a' => $activoId, 'l' => $a['OCL_Linea']]);
                InsercionOracle::insertar($this->conexion, 'INV_ORDEN_COMPRA_LINEA_ACTIVO', ['OCL_Linea' => $a['OCL_Linea'], 'ACT_Activo' => $activoId], 'OLA_Orden_Linea_Activo');
                $this->kardex->registrar('RECEPCION', activo: ['ACT_Activo' => $activoId, 'ACT_Codigo_Interno' => $codigo, 'ACT_Serie_Fabricante' => $a['ACT_Serie_Fabricante'],
                    'SED_Sede' => $sede], destino: ['sede' => $sede], costo: $costo, moneda: $moneda, documento: $documento, usuario: $usuario,
                    motivo: "Recepción OC {$numero}", detalle: $detalleAprobacion);
                $this->gobierno->registrarAuditoria('CREATE', 'INV_ACTIVO', ['codigo' => $codigo, 'serie' => $a['ACT_Serie_Fabricante'],
                    'origen' => "Recepción OC {$numero}", 'sede' => $sede], $usuario, $ip, sedeId: $sede);
                $codigos[] = $codigo;
            }

            // --- 3. Licencias: lote agregado + claves individuales cifradas ---
            $licencias = 0;
            $claves = 0;
            foreach ($r['licencias'] as $l) {
                $precio = $l['precio_unitario'] ?? (string) $lineas[$l['OCL_Linea']]['OCL_Precio_Unitario'];
                $licencia = $this->software->crearLicenciaEnTransaccion(['SOF_Software' => $l['SOF_Software'], 'TLI_Tipo_Licencia' => $l['TLI_Tipo_Licencia'],
                    'LIC_Cantidad_Total' => (int) $l['cantidad'], 'LIC_Fecha_Vencimiento' => $l['LIC_Fecha_Vencimiento'],
                    'LIC_Clave_Activacion' => $l['LIC_Clave_Activacion'], 'LIC_Costo' => bcmul($precio, (string) (int) $l['cantidad'], 2), 'LIC_Moneda' => $moneda],
                    $l['claves'], [], $usuario, $ip, "Recepcion OC {$numero}", $documento, $sede);
                $this->conexion->executeStatement('UPDATE "INV_ORDEN_COMPRA_LINEA" SET "LIC_Licencia" = :lic WHERE "OCL_Linea" = :l', ['lic' => $licencia, 'l' => $l['OCL_Linea']]);
                ++$licencias;
                $claves += \count($l['claves']);
            }

            // --- 4. Documento de entrada, acumulados y estado de la orden ---
            foreach ($porLinea as $lineaId => $item) {
                $this->conexion->executeStatement('UPDATE "INV_ORDEN_COMPRA_LINEA" SET "OCL_Cantidad_Recibida" = "OCL_Cantidad_Recibida" + :n, '
                    .'"OCL_Tipo" = NVL("OCL_Tipo", :t) WHERE "OCL_Linea" = :l', ['n' => $item['cantidad'], 't' => $item['tipo'], 'l' => $lineaId]);
            }
            $faltan = $pendientes();
            $estado = [] !== $faltan ? 'PARCIAL' : 'RECIBIDA';
            $observacion = implode(' · ', array_filter([$r['observacion'],
                null === $aprobacion ? null : "Sobrante aprobado (solicitud #{$aprobacion['aprobacion']})"])) ?: null;
            $recepcion = (int) InsercionOracle::insertar($this->conexion, 'INV_RECEPCION', ['OCO_Orden' => $id, 'REC_Fecha' => Reloj::aBd(Reloj::ahora()),
                'REC_Documento' => $r['documento'], 'REC_Observacion' => null === $observacion ? null : mb_substr($observacion, 0, 500),
                'REC_Resultado' => [] !== $faltan ? 'PARCIAL' : 'COMPLETA', 'USU_Usuario' => $usuario], 'REC_Recepcion');
            foreach ($porLinea as $lineaId => $item) {
                $just = $justificacion[$lineaId] ?? null;
                InsercionOracle::insertar($this->conexion, 'INV_RECEPCION_LINEA', ['REC_Recepcion' => $recepcion, 'OCL_Linea' => $lineaId,
                    'RCL_Cantidad' => $item['cantidad'], 'RCL_Precio_Real' => $item['precio_real'], 'RCL_Diferencia' => $item['diferencia'],
                    'RCL_Motivo' => null !== $just && null !== $item['diferencia'] ? $just['motivo'] : null], 'RCL_Recepcion_Linea');
            }
            $this->conexion->executeStatement('UPDATE "INV_ORDEN_COMPRA" SET "OCO_Estado" = :e WHERE "OCO_Orden" = :id', ['e' => $estado, 'id' => $id]);
            $diferenciasApi = self::diferenciasAApi($diferencias);
            $this->gobierno->registrarAuditoria('RECEIVE', 'INV_ORDEN_COMPRA', ['id' => $id, 'numero' => $numero, 'recepcion' => $recepcion,
                'documento' => $r['documento'], 'diff' => ['OCO_Estado' => ['antes' => $o['OCO_Estado'], 'despues' => $estado]],
                'consumibles_reabastecidos' => $reabastecidos, 'activos_creados' => \count($codigos), 'licencias_creadas' => $licencias,
                'licencias_claves_creadas' => $claves, 'pendientes' => $faltan, 'diferencias' => $diferenciasApi,
                'aprobacion' => null === $aprobacion ? null : self::textos($aprobacion)], $usuario, $ip, sedeId: $sede);
            $this->avisarDiferencias($numero, 'recepcion', [] !== $diferencias ? $faltan : [], $diferenciasApi);

            return ['OCO_Orden' => $id, 'OCO_Estado' => $estado, 'REC_Recepcion' => $recepcion, 'consumibles_reabastecidos' => $reabastecidos,
                'activos_creados' => \count($codigos), 'licencias_creadas' => $licencias, 'licencias_claves_creadas' => $claves, 'activos_codigos' => $codigos,
                'pendientes' => $faltan, 'diferencias' => $diferenciasApi, 'aprobacion_pendiente' => null];
        });
    }

    /**
     * @param list<array<string, mixed>> $d
     *
     * @return list<array<string, mixed>>
     */
    private static function diferenciasAApi(array $d): array
    {
        return array_map(static fn (array $x): array => ['OCL_Linea' => $x['OCL_Linea'], 'tipo' => $x['tipo'], 'detalle' => $x['detalle'], 'motivo' => $x['motivo']], $d);
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, string>
     */
    private static function textos(array $a): array
    {
        return array_map(static fn (mixed $v): string => (string) $v, $a);
    }

    /**
     * Aviso a los administradores (faltantes, sobrantes, precios) tras el commit.
     *
     * @param list<array<string, mixed>> $faltantes
     * @param list<array<string, mixed>> $diferencias
     */
    private function avisarDiferencias(string $numero, string $motivo, array $faltantes, array $diferencias, ?string $texto = null): void
    {
        if ([] === $faltantes && [] === $diferencias) {
            return;
        }
        $contexto = ['numero' => $numero, 'motivo_aviso' => $motivo, 'faltantes' => $faltantes, 'diferencias' => $diferencias, 'motivo' => $texto ?? ''];
        $this->transaccion->alConfirmar(fn () => $this->notificador->enviar('recepcion_diferencias', $contexto));
    }

    // =====================================================================
    // Garantías
    // =====================================================================

    /** @return list<array<string, mixed>> GarantiaItem, alertas primero */
    public function garantias(int $dias, bool $soloAlertas): array
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $filas = $this->conexion->fetchAllAssociative('SELECT a."ACT_Activo", a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", a."ACT_Fecha_Compra", '
            .'a."ACT_Fin_Garantia", pr."PRV_Nombre" FROM "INV_ACTIVO" a LEFT JOIN "INV_ORDEN_COMPRA_LINEA_ACTIVO" la ON la."ACT_Activo" = a."ACT_Activo" '
            .'LEFT JOIN "INV_ORDEN_COMPRA_LINEA" l ON (l."OCL_Linea" = la."OCL_Linea" OR l."ACT_Activo" = a."ACT_Activo") '
            .'LEFT JOIN "INV_ORDEN_COMPRA" o ON o."OCO_Orden" = l."OCO_Orden" LEFT JOIN "INV_PROVEEDOR" pr ON pr."PRV_Proveedor" = o."PRV_Proveedor" '
            .'WHERE '.$alcance.' ORDER BY a."ACT_Codigo_Interno"', $p);
        $hoy = new \DateTimeImmutable(Reloj::ahora()->format('Y-m-d'), new \DateTimeZone('UTC'));
        $umbral = $hoy->modify("+{$dias} days");
        $vistos = [];
        $items = [];
        foreach ($filas as $f) {
            $id = (string) $f['ACT_Activo'];
            if (isset($vistos[$id])) {
                continue;
            }
            $vistos[$id] = true;
            $fin = null === $f['ACT_Fin_Garantia'] ? null : new \DateTimeImmutable(substr((string) $f['ACT_Fin_Garantia'], 0, 10), new \DateTimeZone('UTC'));
            $restantes = null === $fin ? null : (int) $hoy->diff($fin)->format('%r%a');
            $estado = match (true) {
                null === $fin => 'sin_garantia',
                $fin < $hoy => 'vencida',
                $fin <= $umbral => 'por_vencer',
                default => 'vigente',
            };
            if ($soloAlertas && !\in_array($estado, ['por_vencer', 'vencida'], true)) {
                continue;
            }
            $items[] = ['ACT_Activo' => Ident::aTexto($id), 'ACT_Codigo_Interno' => $f['ACT_Codigo_Interno'], 'ACT_Serie_Fabricante' => $f['ACT_Serie_Fabricante'],
                'ACT_Fecha_Compra' => substr((string) $f['ACT_Fecha_Compra'], 0, 10), 'ACT_Fin_Garantia' => $fin?->format('Y-m-d'),
                'dias_restantes' => $restantes, 'estado_garantia' => $estado, 'proveedor' => $f['PRV_Nombre']];
        }
        $prioridad = ['vencida' => 0, 'por_vencer' => 1, 'vigente' => 2, 'sin_garantia' => 3];
        usort($items, static fn (array $a, array $b): int => [$prioridad[$a['estado_garantia']], $a['dias_restantes'] ?? 10 ** 9]
            <=> [$prioridad[$b['estado_garantia']], $b['dias_restantes'] ?? 10 ** 9]);

        return $items;
    }

    /**
     * Resumen de garantías vencidas o por vencer a los administradores.
     *
     * @return array{total: int, enviado: bool}
     */
    public function notificarGarantias(int $dias): array
    {
        $alertas = $this->garantias($dias, true);
        if ([] === $alertas) {
            return ['total' => 0, 'enviado' => false];
        }
        $this->notificador->enviar('garantia_por_vencer', ['total' => \count($alertas), 'dias' => $dias, 'items' => array_map(static fn (array $a): array => [
            'codigo' => $a['ACT_Codigo_Interno'], 'fin' => $a['ACT_Fin_Garantia'] ?? '—',
            'estado' => 'vencida' === $a['estado_garantia'] ? 'Vencida' : 'Por vencer', 'dias' => $a['dias_restantes']], $alertas)]);

        return ['total' => \count($alertas), 'enviado' => true];
    }
}
