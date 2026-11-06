<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Crud\Esquema;
use App\Dominio\Reloj;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * Cierre mensual para Contabilidad: valor del inventario al cierre de un mes y
 * lo que cambió en el mes, por sede y por moneda (no se convierten monedas):
 * activos (saldo inicial, altas, bajas, saldo final), consumibles (último saldo
 * del kardex), compras recibidas, licencias vigentes y mantenimientos cerrados.
 */
final readonly class CierreMensual
{
    public function __construct(private Connection $conexion, private Alcance $alcance, private \App\Repositorio\GobiernoRepositorio $gobierno)
    {
    }

    private static function dos(string $v): string
    {
        return Valoracion::redondear('' === $v ? '0' : $v, 2);
    }

    /** @return array<string, mixed> */
    public function cierre(int $anio, int $mes, ?int $sede): array
    {
        return $this->congelado($anio, $mes, $sede) ?? [...$this->calcular($anio, $mes, $sede), 'frozen' => false];
    }

    /**
     * Guarda la foto del mes terminado. Nadie la reescribe: un segundo intento se rechaza.
     *
     * @return array<string, mixed>
     */
    public function cerrar(int $anio, int $mes, ?int $sede, string $usuario, ?string $ip): array
    {
        $sede = $this->alcance->exigirSede($sede, requerida: false);
        $fin = (new \DateTimeImmutable(\sprintf('%04d-%02d-01', $anio, $mes)))->modify('last day of this month')->format('Y-m-d');
        if ($fin >= \App\Dominio\Reloj::ahora()->format('Y-m-d')) {
            throw new \App\Http\ErrorApi(409, 'MONTH_NOT_FINISHED');
        }
        if (null !== $this->congelado($anio, $mes, $sede)) {
            throw new \App\Http\ErrorApi(409, 'MONTH_ALREADY_CLOSED');
        }
        $foto = $this->calcular($anio, $mes, $sede);
        try {
            $this->conexion->executeStatement('INSERT INTO TRC_MONTH_END_CLOSE (MEC_YEAR, MEC_MONTH, MEC_SED_SITE, MEC_SNAPSHOT, MEC_USU_CLOSED_BY) VALUES (:a, :m, :s, :f, :u)',
                ['a' => $anio, 'm' => $mes, 's' => $sede, 'f' => json_encode($foto, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), 'u' => $usuario], ['f' => \Doctrine\DBAL\ParameterType::LARGE_OBJECT]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            throw new \App\Http\ErrorApi(409, 'MONTH_ALREADY_CLOSED');
        }
        $this->gobierno->registrarAuditoria('MONTH_END_CLOSE', 'TRC_MONTH_END_CLOSE', ['anio' => $anio, 'mes' => $mes, 'sede' => $sede], $usuario, $ip, sedeId: $sede);

        return (array) $this->congelado($anio, $mes, $sede);
    }

    /** @return array<string, mixed>|null */
    private function congelado(int $anio, int $mes, ?int $sede): ?array
    {
        $fila = $this->conexion->fetchAssociative('SELECT c.MEC_SNAPSHOT, c.MEC_CLOSED_AT, u."USU_Username" AS CLOSED_BY FROM TRC_MONTH_END_CLOSE c LEFT JOIN "INV_USUARIO" u ON u."USU_Usuario" = c.MEC_USU_CLOSED_BY '
            .'WHERE c.MEC_YEAR = :a AND c.MEC_MONTH = :m AND NVL(c.MEC_SED_SITE, 0) = :s', ['a' => $anio, 'm' => $mes, 's' => $sede ?? 0]);
        if (false === $fila) {
            return null;
        }

        return [...json_decode((string) $fila['MEC_SNAPSHOT'], true, 512, \JSON_THROW_ON_ERROR), 'frozen' => true, 'closed_at' => (string) $fila['MEC_CLOSED_AT'], 'closed_by' => $fila['CLOSED_BY']];
    }

    /** @return array<string, mixed> */
    private function calcular(int $anio, int $mes, ?int $sede): array
    {
        $inicio = \sprintf('%04d-%02d-01', $anio, $mes);
        $fin = (new \DateTimeImmutable($inicio))->modify('last day of this month')->format('Y-m-d');
        $anterior = (new \DateTimeImmutable($inicio))->modify('-1 day')->format('Y-m-d');
        $inicioDt = "{$anterior} 23:59:59.999999";
        $finDt = "{$fin} 23:59:59.999999";
        $sedes = [];
        foreach ($this->conexion->fetchAllNumeric('SELECT "SED_Sede", "SED_Nombre" FROM "INV_SEDE"') as [$id, $n]) {
            $sedes[(int) $id] = (string) $n;
        }
        $activos = $this->activos($inicio, $fin, $anterior, $sede);
        $datos = ['anio' => $anio, 'mes' => $mes, 'desde' => $inicio, 'hasta' => $fin, 'SED_Sede' => $sede,
            'activos' => $activos['resumen'], 'altas' => $activos['altas'], 'bajas' => $activos['bajas'],
            'consumibles' => $this->consumibles($inicioDt, $finDt, $sede), 'compras' => $this->compras($inicioDt, $finDt, $sede),
            'licencias' => $this->licencias($fin), 'mantenimiento' => $this->mantenimiento($inicioDt, $finDt, $sede)];
        foreach (['activos', 'altas', 'bajas', 'consumibles', 'compras', 'mantenimiento'] as $seccion) {
            foreach ($datos[$seccion] as $i => $fila) {
                $datos[$seccion][$i]['sede'] = null === $fila['SED_Sede'] ? null : ($sedes[$fila['SED_Sede']] ?? null);
            }
        }

        return $datos;
    }

    /** @return array{resumen: list<array<string, mixed>>, altas: list<array<string, mixed>>, bajas: list<array<string, mixed>>} */
    private function activos(string $inicio, string $fin, string $anterior, ?int $sede): array
    {
        [$alcance, $pa] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $filtro = $alcance.(null === $sede ? '' : ' AND a."SED_Sede" = :sede');
        $base = null === $sede ? $pa : [...$pa, 'sede' => $sede];
        $vigente = static fn (string $p): string => "a.\"ACT_Fecha_Compra\" <= TO_DATE(:{$p}, 'YYYY-MM-DD') AND (a.\"ACT_Baja_Fecha\" IS NULL OR a.\"ACT_Baja_Fecha\" > TO_DATE(:{$p}, 'YYYY-MM-DD'))";
        $entre = static fn (string $col): string => "a.\"{$col}\" BETWEEN TO_DATE(:ini, 'YYYY-MM-DD') AND TO_DATE(:fin, 'YYYY-MM-DD')";
        $grupos = [];
        $sumar = function (string $clave, string $cond, array $p) use (&$grupos, $filtro, $base): void {
            foreach ($this->conexion->fetchAllNumeric('SELECT a."SED_Sede", a."ACT_Moneda", COUNT(*), NVL(SUM(a."ACT_Costo"), 0), COUNT(a."ACT_Costo") FROM "INV_ACTIVO" a WHERE '
                .$cond.' AND '.$filtro.' GROUP BY a."SED_Sede", a."ACT_Moneda"', [...$base, ...$p]) as [$s, $moneda, $n, $valor, $conCosto]) {
                $k = ($s ?? '').'|'.$moneda;
                $grupos[$k] ??= ['SED_Sede' => null === $s ? null : (int) $s, 'moneda' => $moneda];
                $grupos[$k]["{$clave}_cantidad"] = ($grupos[$k]["{$clave}_cantidad"] ?? 0) + (int) $n;
                $grupos[$k]["{$clave}_valor"] = bcadd($grupos[$k]["{$clave}_valor"] ?? '0', (string) $valor, 2);
                $grupos[$k]["{$clave}_sin_costo"] = ($grupos[$k]["{$clave}_sin_costo"] ?? 0) + ((int) $n - (int) $conCosto);
            }
        };
        $sumar('inicial', $vigente('dia'), ['dia' => $anterior]);
        $sumar('altas', $entre('ACT_Fecha_Compra'), ['ini' => $inicio, 'fin' => $fin]);
        $sumar('bajas', $entre('ACT_Baja_Fecha')." AND a.\"ACT_Fecha_Compra\" <= TO_DATE(:fin, 'YYYY-MM-DD')", ['ini' => $inicio, 'fin' => $fin]);
        $sumar('final', $vigente('dia'), ['dia' => $fin]);
        $resumen = [];
        foreach ($grupos as $g) {
            $fila = ['SED_Sede' => $g['SED_Sede'], 'moneda' => $g['moneda']];
            foreach (['inicial', 'altas', 'bajas', 'final'] as $c) {
                $fila["{$c}_cantidad"] = $g["{$c}_cantidad"] ?? 0;
                $fila["{$c}_valor"] = self::dos($g["{$c}_valor"] ?? '0');
                $fila["{$c}_sin_costo"] = $g["{$c}_sin_costo"] ?? 0;
            }
            $resumen[] = $fila;
        }
        usort($resumen, static fn (array $a, array $b): int => [$a['SED_Sede'] ?? 0, $a['moneda'] ?? ''] <=> [$b['SED_Sede'] ?? 0, $b['moneda'] ?? '']);
        $detalle = fn (string $cond): array => array_map(static fn (array $f): array => ['codigo' => $f['ACT_Codigo_Interno'], 'serie' => $f['ACT_Serie_Fabricante'],
            'tipo' => $f['TAC_Nombre'], 'modelo' => $f['MOD_Nombre'], 'SED_Sede' => null === $f['SED_Sede'] ? null : (int) $f['SED_Sede'],
            'fecha_compra' => substr((string) $f['ACT_Fecha_Compra'], 0, 10), 'fecha_baja' => null === $f['ACT_Baja_Fecha'] ? null : substr((string) $f['ACT_Baja_Fecha'], 0, 10),
            'motivo_baja' => $f['ACT_Baja_Motivo'], 'costo' => null === $f['ACT_Costo'] ? null : Esquema::decimal((string) $f['ACT_Costo'], 2),
            'moneda' => $f['ACT_Moneda'], 'factura' => $f['ACT_Factura']],
            $this->conexion->fetchAllAssociative('SELECT a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", t."TAC_Nombre", m."MOD_Nombre", a."SED_Sede", a."ACT_Fecha_Compra", '
                .'a."ACT_Baja_Fecha", a."ACT_Baja_Motivo", a."ACT_Costo", a."ACT_Moneda", a."ACT_Factura" FROM "INV_ACTIVO" a JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" '
                .'JOIN "INV_MODELO" m ON m."MOD_Modelo" = a."MOD_Modelo" WHERE '.$cond.' AND '.$filtro.' ORDER BY a."ACT_Codigo_Interno"', [...$base, 'ini' => $inicio, 'fin' => $fin]));

        return ['resumen' => $resumen, 'altas' => $detalle($entre('ACT_Fecha_Compra')), 'bajas' => $detalle($entre('ACT_Baja_Fecha'))];
    }

    /** @return list<array<string, mixed>> */
    private function consumibles(string $inicio, string $fin, ?int $sede): array
    {
        [$alcance, $pa] = $this->alcance->lectura('INV_CONSUMIBLE', 'c');
        $filas = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT c."CON_Consumible", c."CON_Nombre", c."CON_Unidad", c."SED_Sede", c."CON_Moneda" FROM "INV_CONSUMIBLE" c WHERE '
            .$alcance.(null === $sede ? '' : ' AND c."SED_Sede" = :sede').' ORDER BY c."CON_Nombre"', null === $sede ? $pa : [...$pa, 'sede' => $sede]) as $c) {
            $saldo = function (string $momento) use ($c): array {
                $f = $this->conexion->fetchAssociative('SELECT "KDX_Saldo", "KDX_Saldo_Valor" FROM "INV_KARDEX" WHERE "CON_Consumible" = :c AND "KDX_Fecha" <= :m AND "KDX_Saldo" IS NOT NULL '
                    .'ORDER BY "KDX_Fecha" DESC, "KDX_Evento" DESC FETCH FIRST 1 ROWS ONLY', ['c' => $c['CON_Consumible'], 'm' => $momento]);

                return false === $f ? [0, '0'] : [(int) $f['KDX_Saldo'], (string) ($f['KDX_Saldo_Valor'] ?? '0')];
            };
            [$iniCant, $iniVal] = $saldo($inicio);
            [$finCant, $finVal] = $saldo($fin);
            $mov = ['CONSUMIBLE_ENTRADA' => [0, '0'], 'CONSUMIBLE_SALIDA' => [0, '0'], 'CONSUMIBLE_AJUSTE' => [0, '0']];
            foreach ($this->conexion->fetchAllNumeric('SELECT "KDX_Tipo", NVL(SUM("KDX_Cantidad"), 0), NVL(SUM(ABS("KDX_Cantidad") * NVL("KDX_Costo", 0)), 0) FROM "INV_KARDEX" '
                ."WHERE \"CON_Consumible\" = :c AND \"KDX_Fecha\" > :i AND \"KDX_Fecha\" <= :f AND \"KDX_Tipo\" IN ('CONSUMIBLE_ENTRADA', 'CONSUMIBLE_SALIDA', 'CONSUMIBLE_AJUSTE') "
                .'GROUP BY "KDX_Tipo"', ['c' => $c['CON_Consumible'], 'i' => $inicio, 'f' => $fin]) as [$tipo, $cant, $valor]) {
                $mov[$tipo] = [(int) $cant, (string) $valor];
            }
            if (0 === $iniCant && 0 === $finCant && 0 === $mov['CONSUMIBLE_ENTRADA'][0] && 0 === $mov['CONSUMIBLE_SALIDA'][0] && 0 === $mov['CONSUMIBLE_AJUSTE'][0]) {
                continue;
            }
            $filas[] = ['CON_Consumible' => (int) $c['CON_Consumible'], 'nombre' => $c['CON_Nombre'], 'unidad' => $c['CON_Unidad'],
                'SED_Sede' => null === $c['SED_Sede'] ? null : (int) $c['SED_Sede'], 'moneda' => $c['CON_Moneda'],
                'inicial_cantidad' => $iniCant, 'inicial_valor' => self::dos($iniVal), 'entradas_cantidad' => $mov['CONSUMIBLE_ENTRADA'][0],
                'entradas_valor' => self::dos($mov['CONSUMIBLE_ENTRADA'][1]), 'salidas_cantidad' => $mov['CONSUMIBLE_SALIDA'][0],
                'salidas_valor' => self::dos($mov['CONSUMIBLE_SALIDA'][1]), 'ajustes_cantidad' => $mov['CONSUMIBLE_AJUSTE'][0],
                'final_cantidad' => $finCant, 'final_valor' => self::dos($finVal)];
        }

        return $filas;
    }

    /** @return list<array<string, mixed>> */
    private function compras(string $inicio, string $fin, ?int $sede): array
    {
        [$alcance, $pa] = $this->alcance->lectura('INV_ORDEN_COMPRA', 'o');

        return array_map(static fn (array $f): array => ['orden' => $f[0], 'proveedor' => $f[1], 'moneda' => $f[2], 'SED_Sede' => null === $f[3] ? null : (int) $f[3],
            'fecha' => Reloj::iso(Reloj::desdeBd((string) $f[4])), 'valor' => self::dos((string) $f[5])],
            $this->conexion->fetchAllNumeric('SELECT o."OCO_Numero", p."PRV_Nombre", o."OCO_Moneda", o."SED_Sede", MAX(r."REC_Fecha"), '
                .'SUM(rl."RCL_Cantidad" * NVL(rl."RCL_Precio_Real", l."OCL_Precio_Unitario")) FROM "INV_ORDEN_COMPRA" o JOIN "INV_RECEPCION" r ON r."OCO_Orden" = o."OCO_Orden" '
                .'JOIN "INV_RECEPCION_LINEA" rl ON rl."REC_Recepcion" = r."REC_Recepcion" JOIN "INV_ORDEN_COMPRA_LINEA" l ON l."OCL_Linea" = rl."OCL_Linea" '
                .'JOIN "INV_PROVEEDOR" p ON p."PRV_Proveedor" = o."PRV_Proveedor" WHERE r."REC_Fecha" > :i AND r."REC_Fecha" <= :f AND '.$alcance
                .(null === $sede ? '' : ' AND o."SED_Sede" = :sede').' GROUP BY o."OCO_Numero", p."PRV_Nombre", o."OCO_Moneda", o."SED_Sede" ORDER BY o."OCO_Numero"',
                [...$pa, 'i' => $inicio, 'f' => $fin, ...(null === $sede ? [] : ['sede' => $sede])]));
    }

    /** @return list<array<string, mixed>> */
    private function licencias(string $fin): array
    {
        return array_map(static fn (array $f): array => ['software' => $f[0], 'cantidad' => (int) $f[1], 'costo' => null === $f[2] ? null : self::dos((string) $f[2]),
            'moneda' => $f[3], 'vencimiento' => null === $f[4] ? null : substr((string) $f[4], 0, 10)],
            $this->conexion->fetchAllNumeric('SELECT s."SOF_Nombre", l."LIC_Cantidad_Total", l."LIC_Costo", l."LIC_Moneda", l."LIC_Fecha_Vencimiento" FROM "INV_LICENCIA" l '
                ."JOIN \"INV_SOFTWARE\" s ON s.\"SOF_Software\" = l.\"SOF_Software\" WHERE l.\"LIC_Fecha_Vencimiento\" IS NULL OR l.\"LIC_Fecha_Vencimiento\" >= TO_DATE(:f, 'YYYY-MM-DD') "
                .'ORDER BY s."SOF_Nombre"', ['f' => $fin]));
    }

    /** @return list<array<string, mixed>> */
    private function mantenimiento(string $inicio, string $fin, ?int $sede): array
    {
        [$alcance, $pa] = $this->alcance->lectura('INV_ACTIVO', 'a');

        return array_map(static fn (array $f): array => ['SED_Sede' => null === $f[0] ? null : (int) $f[0], 'moneda' => $f[1], 'tickets' => (int) $f[2], 'valor' => self::dos((string) $f[3])],
            $this->conexion->fetchAllNumeric('SELECT a."SED_Sede", m."MAN_Moneda", COUNT(*), SUM(m."MAN_Costo_Total") FROM "INV_MANTENIMIENTO" m JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" '
                .'WHERE m."MAN_Fecha_Cierre" > :i AND m."MAN_Fecha_Cierre" <= :f AND '.$alcance.(null === $sede ? '' : ' AND a."SED_Sede" = :sede')
                .' GROUP BY a."SED_Sede", m."MAN_Moneda"', [...$pa, 'i' => $inicio, 'f' => $fin, ...(null === $sede ? [] : ['sede' => $sede])]));
    }
}
