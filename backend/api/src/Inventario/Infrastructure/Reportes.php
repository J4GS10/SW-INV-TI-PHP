<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure;

use App\Inventario\Movimientos;
use App\Config\Ajustes;
use App\Crud\Esquema;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * Reportes de trazabilidad sobre el kardex unificado y la bandeja «Requiere su
 * atención». Todo respeta el alcance por sede (capa 1 aquí, VPD en la base).
 */
final readonly class Reportes
{
    /** Lo que cada rol puede atender o consultar (los demás roles ven todo). */
    private const array ATENCION_POR_ROL = [
        'TECNICO' => ['INGRESO_POR_CONFIRMAR', 'ACTIVO_POR_COMPLETAR', 'TICKET_ANTIGUO', 'PRESTAMO_VENCIDO', 'ASIGNADO_SIN_CUSTODIO', 'ALMACEN_CON_CUSTODIO', 'STOCK_BAJO', 'PERSONA_INACTIVA_CON_EQUIPOS',
            'FALTANTE_PENDIENTE', 'RESPONSABLE_INACTIVO'],
        'AUDITOR' => ['ASIGNADO_SIN_CUSTODIO', 'ALMACEN_CON_CUSTODIO', 'BAJA_CON_CUSTODIA', 'PERSONA_INACTIVA_CON_EQUIPOS', 'DATOS_INCOMPLETOS', 'CUENTA_SIN_ROTAR',
            'FALTANTE_PENDIENTE', 'INSUMOS_POR_RECLASIFICAR'],
        'COMPRAS' => ['STOCK_BAJO'],
        'CONSULTA' => [],
    ];
    private const int ALERTA_LICENCIAS_DIAS = 60;
    private const int ROTACION_CUENTAS_DIAS = 180;

    public function __construct(private Connection $conexion, private Alcance $alcance, private Kardex $kardex, private Ajustes $ajustes)
    {
    }

    // ------------------------------------------------------------------
    // Kardex legible
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $filtros
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function kardex(array $filtros, int $limite, int $desplazamiento): array
    {
        [$eventos, $total] = $this->kardex->listar($this->kardex->filtros($filtros), $limite, $desplazamiento);

        return ['total' => $total, 'items' => $this->legibles($eventos)];
    }

    /**
     * Todos los eventos del filtro, en orden cronológico y con nombres legibles (exportes).
     *
     * @param array<string, mixed> $filtros
     *
     * @return list<array<string, mixed>>
     */
    public function kardexCompleto(array $filtros): array
    {
        return $this->legibles($this->kardex->todos($this->kardex->filtros($filtros)));
    }

    /**
     * Cada evento con el nombre de las personas, áreas, sedes, equipos y del usuario que lo registró.
     *
     * @param list<array<string, mixed>> $eventos
     *
     * @return list<array<string, mixed>>
     */
    private function legibles(array $eventos): array
    {
        $n = $this->nombres($eventos);

        return array_map(static fn (array $e): array => [...$e,
            'origen_persona' => $n['persona'][$e['KDX_Origen_Persona'] ?? ''] ?? null, 'origen_area' => $n['area'][$e['KDX_Origen_Area'] ?? ''] ?? null,
            'origen_sede' => $n['sede'][$e['KDX_Origen_Sede'] ?? ''] ?? null, 'origen_activo' => $n['activo'][$e['KDX_Origen_Activo'] ?? ''] ?? null,
            'destino_persona' => $n['persona'][$e['KDX_Destino_Persona'] ?? ''] ?? null, 'destino_area' => $n['area'][$e['KDX_Destino_Area'] ?? ''] ?? null,
            'destino_sede' => $n['sede'][$e['KDX_Destino_Sede'] ?? ''] ?? null, 'destino_activo' => $n['activo'][$e['KDX_Destino_Activo'] ?? ''] ?? null,
            'usuario' => $n['usuario'][$e['USU_Usuario'] ?? ''] ?? null], $eventos);
    }

    /**
     * Nombres legibles (en lote) de personas, áreas, sedes, equipos y usuarios.
     *
     * @param list<array<string, mixed>> $eventos
     *
     * @return array<string, array<string, string>>
     */
    private function nombres(array $eventos): array
    {
        $ids = static function (array $campos) use ($eventos): array {
            $v = [];
            foreach ($eventos as $e) {
                foreach ($campos as $c) {
                    if (null !== $e[$c]) {
                        $v[(string) $e[$c]] = true;
                    }
                }
            }

            return array_keys($v);
        };
        $consulta = function (string $sql, array $valores, bool $uuid) {
            if ([] === $valores) {
                return [];
            }
            $p = [];
            foreach (array_values($valores) as $i => $v) {
                $p["v{$i}"] = $uuid ? Ident::aHex((string) $v) : (int) $v;
            }
            $salida = [];
            foreach ($this->conexion->fetchAllNumeric(str_replace(':lista', ':'.implode(', :', array_keys($p)), $sql), $p) as [$id, $nombre]) {
                $salida[$uuid ? Ident::aTexto((string) $id) : (string) (int) $id] = (string) $nombre;
            }

            return $salida;
        };

        return [
            'persona' => $consulta('SELECT "PER_Persona", "PER_Primer_Nombre" || \' \' || "PER_Primer_Apellido" FROM "INV_PERSONA" WHERE "PER_Persona" IN (:lista)',
                $ids(['KDX_Origen_Persona', 'KDX_Destino_Persona']), true),
            'area' => $consulta('SELECT "ARE_Area", "ARE_Nombre" FROM "INV_AREA" WHERE "ARE_Area" IN (:lista)', $ids(['KDX_Origen_Area', 'KDX_Destino_Area']), false),
            'sede' => $consulta('SELECT "SED_Sede", "SED_Nombre" FROM "INV_SEDE" WHERE "SED_Sede" IN (:lista)', $ids(['KDX_Origen_Sede', 'KDX_Destino_Sede']), false),
            'activo' => $consulta('SELECT "ACT_Activo", "ACT_Codigo_Interno" FROM "INV_ACTIVO" WHERE "ACT_Activo" IN (:lista)', $ids(['KDX_Origen_Activo', 'KDX_Destino_Activo']), true),
            'usuario' => $consulta('SELECT "USU_Usuario", "USU_Username" FROM "INV_USUARIO" WHERE "USU_Usuario" IN (:lista)', $ids(['USU_Usuario']), true),
        ];
    }

    /**
     * Recalcula el sello encadenado de todo el kardex (sin alcance: es la cadena completa).
     *
     * @return array<string, mixed>
     */
    public function verificarKardex(): array
    {
        return $this->alcance->elevado(function (): array {
            $total = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_KARDEX"');
            $problemas = array_map(static fn (array $f): array => ['evento' => (int) $f['EV'], 'secuencia' => null === $f['SEC'] ? null : (int) $f['SEC'], 'problema' => $f['PROBLEMA']],
                $this->conexion->fetchAllAssociative('SELECT ev AS "EV", sec AS "SEC", problema AS "PROBLEMA" FROM (SELECT k."KDX_Evento" AS ev, k."KDX_Secuencia" AS sec, '
                    ."CASE WHEN k.\"KDX_Secuencia\" IS NULL THEN 'SIN_SELLO' "
                    ."WHEN DECODE(k.\"KDX_Hash\", INV_KARDEX_PKG.hash_evento(k.\"KDX_Evento\"), 0, 1) = 1 THEN 'EVENTO_ALTERADO' "
                    ."WHEN DECODE(k.\"KDX_Hash_Anterior\", LAG(k.\"KDX_Hash\") OVER (ORDER BY k.\"KDX_Secuencia\"), 0, 1) = 1 THEN 'CADENA_ROTA' "
                    ."WHEN k.\"KDX_Secuencia\" <> NVL(LAG(k.\"KDX_Secuencia\") OVER (ORDER BY k.\"KDX_Secuencia\"), 0) + 1 THEN 'EVENTO_FALTANTE' END AS problema "
                    .'FROM "INV_KARDEX" k) WHERE problema IS NOT NULL ORDER BY sec NULLS FIRST FETCH FIRST 50 ROWS ONLY'));
            $ultimo = $this->conexion->fetchAssociative('SELECT "KDX_Secuencia", "KDX_Hash", "KDX_Fecha" FROM "INV_KARDEX" WHERE "KDX_Secuencia" IS NOT NULL '
                .'ORDER BY "KDX_Secuencia" DESC FETCH FIRST 1 ROWS ONLY');

            return ['total' => $total, 'integro' => [] === $problemas, 'problemas' => $problemas,
                'ultima_secuencia' => false === $ultimo ? 0 : (int) $ultimo['KDX_Secuencia'], 'ultimo_hash' => false === $ultimo ? null : $ultimo['KDX_Hash'],
                'ultima_fecha' => false === $ultimo ? null : Reloj::iso(Reloj::desdeBd((string) $ultimo['KDX_Fecha']))];
        });
    }

    // ------------------------------------------------------------------
    // Custodia a una fecha
    // ------------------------------------------------------------------

    /** @return list<array<string, mixed>> CustodiaItem */
    public function custodia(?string $fecha, ?string $persona, ?int $area, ?int $sede): array
    {
        $corte = null === $fecha ? Reloj::aBd(Reloj::ahora()) : "{$fecha} 23:59:59.999999";
        [$aMov, $p] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');
        $sql = 'm."MOV_Fecha_Asignacion" <= :corte AND (m."MOV_Fecha_Devolucion" IS NULL OR m."MOV_Fecha_Devolucion" > :corte) AND '.$aMov;
        $p['corte'] = $corte;
        foreach (['persona' => [$persona, 'm."PER_Persona"'], 'area' => [$area, 'm."ARE_Area"'], 'sede' => [$sede, 'a."SED_Sede"']] as $k => [$v, $col]) {
            if (null !== $v && 0 !== $v) {
                $sql .= " AND {$col} = :f_{$k}";
                $p["f_{$k}"] = $v;
            }
        }

        return array_map(static fn (array $f): array => ['ACT_Activo' => Ident::aTexto((string) $f['ACT_Activo']), 'activo_codigo' => $f['ACT_Codigo_Interno'],
            'activo_serie' => $f['ACT_Serie_Fabricante'], 'activo_tipo' => $f['TAC_Nombre'], 'PER_Persona' => Ident::aTexto((string) $f['PER_Persona']),
            'persona' => $f['PER_Primer_Nombre'].' '.$f['PER_Primer_Apellido'], 'ARE_Area' => (int) $f['ARE_Area'], 'area' => $f['ARE_Nombre'],
            'SED_Sede' => null === $f['SED_Sede'] ? null : (int) $f['SED_Sede'], 'desde' => Reloj::iso(Reloj::desdeBd((string) $f['MOV_Fecha_Asignacion'])),
            'tipo' => $f['TMO_Nombre'], 'devolucion_prevista' => null === $f['MOV_Fecha_Prevista_Devolucion'] ? null : substr((string) $f['MOV_Fecha_Prevista_Devolucion'], 0, 10)],
            $this->conexion->fetchAllAssociative('SELECT m."ACT_Activo", m."PER_Persona", m."ARE_Area", m."MOV_Fecha_Asignacion", m."MOV_Fecha_Prevista_Devolucion", '
                .'a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", a."SED_Sede", p."PER_Primer_Nombre", p."PER_Primer_Apellido", ar."ARE_Nombre", t."TAC_Nombre", tm."TMO_Nombre" '
                .'FROM "INV_MOVIMIENTO" m JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" JOIN "INV_PERSONA" p ON p."PER_Persona" = m."PER_Persona" '
                .'LEFT JOIN "INV_AREA" ar ON ar."ARE_Area" = m."ARE_Area" LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" '
                .'LEFT JOIN "INV_TIPO_MOVIMIENTO" tm ON tm."TMO_Tipo_Movimiento" = m."TMO_Tipo_Movimiento" WHERE '.$sql
                .' ORDER BY p."PER_Primer_Apellido", p."PER_Primer_Nombre", a."ACT_Codigo_Interno"', $p));
    }

    // ------------------------------------------------------------------
    // Entradas y salidas, resumen por motivos
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function entradasSalidas(?string $desde, ?string $hasta, ?int $sede): array
    {
        [$sql, $p] = $this->kardex->filtros(['categoria' => 'ENTRADAS_SALIDAS', 'desde' => $desde, 'hasta' => $hasta, 'sede' => $sede]);
        $resumen = [];
        foreach ($this->conexion->fetchAllNumeric('SELECT k."KDX_Tipo", k."KDX_Moneda", COUNT(*), NVL(SUM(k."KDX_Cantidad"), 0), '
            .'NVL(SUM(k."KDX_Costo" * NVL(k."KDX_Cantidad", 1)), 0) FROM "INV_KARDEX" k WHERE '.$sql.' GROUP BY k."KDX_Tipo", k."KDX_Moneda"', $p) as [$tipo, $moneda, $n, $cantidad, $valor]) {
            $resumen[$tipo] ??= ['tipo' => $tipo, 'eventos' => 0, 'cantidad' => 0, 'valor_por_moneda' => [],
                'clase' => \in_array($tipo, Kardex::EVENTOS_ENTRADA, true) ? 'ENTRADA' : 'SALIDA'];
            $resumen[$tipo]['eventos'] += (int) $n;
            $resumen[$tipo]['cantidad'] += (int) $cantidad;
            if (null !== $moneda && 0.0 !== (float) $valor) {
                $resumen[$tipo]['valor_por_moneda'][$moneda] = (float) $valor;
            }
        }

        return ['desde' => $desde, 'hasta' => $hasta, 'sede' => $sede,
            'por_tipo' => array_map(static fn (array $r): array => [...$r, 'valor_por_moneda' => (object) $r['valor_por_moneda']], array_values($resumen))];
    }

    /**
     * Movimientos por motivo, mantenimientos por falla y acción, y bajas por motivo y destino.
     *
     * @return array<string, mixed>
     */
    public function resumenMotivos(?string $desde, ?string $hasta, ?int $sede): array
    {
        $rango = static function (string $col, array &$p) use ($desde, $hasta): string {
            $s = '';
            if (null !== $desde) {
                $s .= " AND {$col} >= :desde";
                $p['desde'] = "{$desde} 00:00:00.000000";
            }
            if (null !== $hasta) {
                $s .= " AND {$col} <= :hasta";
                $p['hasta'] = "{$hasta} 23:59:59.999999";
            }

            return $s;
        };
        [$aAct, $pAct] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $filtroSede = null === $sede ? '' : ' AND a."SED_Sede" = :sede';
        $base = null === $sede ? $pAct : [...$pAct, 'sede' => $sede];

        $p = $base;
        $movimientos = array_map(static fn (array $f): array => ['codigo' => $f[0], 'nombre' => $f[1], 'tipo' => $f[2], 'total' => (int) $f[3]],
            $this->conexion->fetchAllNumeric('SELECT mm."MMO_Codigo", mm."MMO_Nombre", t."TMO_Nombre", COUNT(*) FROM "INV_MOVIMIENTO" m JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" '
                .'LEFT JOIN "INV_TIPO_MOVIMIENTO" t ON t."TMO_Tipo_Movimiento" = m."TMO_Tipo_Movimiento" LEFT JOIN "INV_MOTIVO_MOVIMIENTO" mm ON mm."MMO_Motivo" = m."MMO_Motivo" '
                .'WHERE '.$aAct.$filtroSede.$rango('m."MOV_Fecha_Asignacion"', $p).' GROUP BY mm."MMO_Codigo", mm."MMO_Nombre", t."TMO_Nombre" ORDER BY COUNT(*) DESC', $p));
        $p = $base;
        $fallas = [];
        foreach ($this->conexion->fetchAllNumeric('SELECT tf."TFA_Codigo", tf."TFA_Nombre", m."MAN_Moneda", COUNT(*), NVL(SUM(m."MAN_Costo_Total"), 0) FROM "INV_MANTENIMIENTO" m '
            .'JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" LEFT JOIN "INV_TIPO_FALLA" tf ON tf."TFA_Tipo_Falla" = m."TFA_Tipo_Falla" WHERE '.$aAct.$filtroSede
            .$rango('m."MAN_Fecha_Ingreso"', $p).' GROUP BY tf."TFA_Codigo", tf."TFA_Nombre", m."MAN_Moneda"', $p) as [$c, $n, $moneda, $t, $costo]) {
            $k = ($c ?? '').'|'.($n ?? '');
            $fallas[$k] ??= ['codigo' => $c, 'nombre' => $n, 'total' => 0, 'costo_por_moneda' => []];
            $fallas[$k]['total'] += (int) $t;
            if (0.0 !== (float) $costo) {
                $fallas[$k]['costo_por_moneda'][$moneda ?? 'GTQ'] = Esquema::decimal((string) $costo, 2);
            }
        }
        usort($fallas, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        $p = $base;
        $acciones = array_map(static fn (array $f): array => ['codigo' => $f[0], 'nombre' => $f[1], 'total' => (int) $f[2]],
            $this->conexion->fetchAllNumeric('SELECT am."AMA_Codigo", am."AMA_Nombre", COUNT(*) FROM "INV_DETALLE_MANT" d JOIN "INV_MANTENIMIENTO" m ON m."MAN_Mantenimiento" = d."MAN_Mantenimiento" '
                .'JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" LEFT JOIN "INV_ACCION_MANTENIMIENTO" am ON am."AMA_Accion" = d."AMA_Accion" WHERE '.$aAct.$filtroSede
                .$rango('m."MAN_Fecha_Ingreso"', $p).' GROUP BY am."AMA_Codigo", am."AMA_Nombre" ORDER BY COUNT(*) DESC', $p));
        $bajas = function (string $col) use ($aAct, $filtroSede, $base, $desde, $hasta): array {
            $p = $base;
            $s = '';
            if (null !== $desde) {
                $s .= " AND a.\"ACT_Baja_Fecha\" >= TO_DATE(:desde, 'YYYY-MM-DD')";
                $p['desde'] = $desde;
            }
            if (null !== $hasta) {
                $s .= " AND a.\"ACT_Baja_Fecha\" <= TO_DATE(:hasta, 'YYYY-MM-DD')";
                $p['hasta'] = $hasta;
            }

            return array_map(static fn (array $f): array => ['codigo' => $f[0], 'total' => (int) $f[1]], $this->conexion->fetchAllNumeric(
                "SELECT a.\"{$col}\", COUNT(*) FROM \"INV_ACTIVO\" a WHERE a.\"ACT_Baja_Fecha\" IS NOT NULL AND {$aAct}{$filtroSede}{$s} GROUP BY a.\"{$col}\" ORDER BY COUNT(*) DESC", $p));
        };

        return ['movimientos' => $movimientos, 'fallas' => array_map(static fn (array $f): array => [...$f, 'costo_por_moneda' => (object) $f['costo_por_moneda']], $fallas),
            'acciones' => $acciones, 'bajas_motivo' => $bajas('ACT_Baja_Motivo'), 'bajas_destino' => $bajas('ACT_Baja_Destino')];
    }

    // ------------------------------------------------------------------
    // Bandeja «Requiere su atención»
    // ------------------------------------------------------------------

    /**
     * Solo lo que el rol puede atender o consultar (sin indicios del resto).
     *
     * @return array<string, mixed>
     */
    public function atencion(string $rol): array
    {
        $permitidos = self::ATENCION_POR_ROL[$rol] ?? null;
        if ([] === $permitidos) {
            return ['generado' => Reloj::iso(Reloj::ahora()), 'items' => []];
        }
        $hoy = Reloj::ahora()->setTimezone(new \DateTimeZone($this->ajustes->zonaHoraria))->format('Y-m-d');
        $estados = [];
        foreach ($this->conexion->fetchAllNumeric('SELECT "EOP_Nombre", "EOP_Estado_Operativo" FROM "INV_ESTADO_OPERATIVO"') as [$n, $id]) {
            $estados[(string) $n] = (int) $id;
        }
        [$aAct, $pAct] = $this->alcance->lectura('INV_ACTIVO', 'a');
        [$aMov, $pMov] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');
        $custodias = 'SELECT m."ACT_Activo" FROM "INV_MOVIMIENTO" m WHERE m."MOV_Fecha_Devolucion" IS NULL AND '.$aMov;
        $activos = function (string $cond, array $p = []) use ($aAct, $pAct, $pMov): array {
            return array_map(static fn (array $f): array => ['id' => Ident::aTexto((string) $f[0]), 'codigo' => $f[1]], $this->conexion->fetchAllNumeric(
                'SELECT a."ACT_Activo", a."ACT_Codigo_Interno" FROM "INV_ACTIVO" a WHERE '.$cond.' AND '.$aAct.' ORDER BY a."ACT_Codigo_Interno" FETCH FIRST 100 ROWS ONLY',
                [...$pAct, ...$pMov, ...$p]));
        };
        $filas = fn (string $sql, array $p = []): array => $this->conexion->fetchAllNumeric($sql, [...$pAct, ...$p]);
        $items = [];
        // Cada consulta corre solo si el rol puede ver esa clave (F-12: antes corrían todas y se filtraba al final).
        $quiere = static fn (string $clave): bool => null === $permitidos || \in_array($clave, $permitidos, true);
        $agregar = static function (string $clave, string $severidad, \Closure $consulta, array $extra = []) use (&$items, $quiere): void {
            if (!$quiere($clave)) {
                return;
            }
            $lista = $consulta();
            if ([] !== $lista) {
                $items[] = ['clave' => $clave, 'severidad' => $severidad, 'total' => \count($lista), 'items' => \array_slice($lista, 0, 20), ...$extra];
            }
        };
        $baja = $estados['Baja'] ?? null;
        $agregar('ASIGNADO_SIN_CUSTODIO', 'alta', fn (): array => $activos('a."EOP_Estado_Operativo" = :asignado AND a."ACT_Activo" NOT IN ('.$custodias.')', ['asignado' => $estados['Asignado'] ?? -1]));
        $agregar('ALMACEN_CON_CUSTODIO', 'alta', fn (): array => $activos('a."EOP_Estado_Operativo" = :bod AND a."ACT_Activo" IN ('.$custodias.')',
            ['bod' => $estados['En Bodega'] ?? -1]));
        if (null !== $baja) {
            $agregar('BAJA_CON_CUSTODIA', 'alta', fn (): array => $activos('a."EOP_Estado_Operativo" = :baja AND a."ACT_Activo" IN ('.$custodias.')', ['baja' => $baja]));
        }
        [$aApr, $pApr] = $this->alcance->lectura('INV_APROBACION', 'ap');
        $agregar('APROBACION_PENDIENTE', 'alta', fn (): array => array_map(static fn (array $f): array => ['id' => (int) $f[0], 'tipo' => $f[1], 'resumen' => $f[2]],
            $this->conexion->fetchAllNumeric("SELECT ap.\"APR_Aprobacion\", ap.\"APR_Tipo\", ap.\"APR_Resumen\" FROM \"INV_APROBACION\" ap WHERE ap.\"APR_Estado\" = 'PENDIENTE' AND "
                .$aApr.' ORDER BY ap."APR_Fecha_Solicitud"', $pApr)));
        [$aPer, $pPer] = $this->alcance->lectura('INV_PERSONA', 'p');
        $agregar('PERSONA_INACTIVA_CON_EQUIPOS', 'alta', fn (): array => array_map(static fn (array $f): array => ['id' => Ident::aTexto((string) $f[0]), 'nombre' => $f[1].' '.$f[2], 'cantidad' => (int) $f[3]],
            $this->conexion->fetchAllNumeric('SELECT p."PER_Persona", p."PER_Primer_Nombre", p."PER_Primer_Apellido", COUNT(m."MOV_Movimiento") FROM "INV_PERSONA" p '
                .'JOIN "INV_MOVIMIENTO" m ON m."PER_Persona" = p."PER_Persona" WHERE p."PER_Estado" = 0 AND m."MOV_Fecha_Devolucion" IS NULL AND '.$aPer.' AND '.$aMov
                .' GROUP BY p."PER_Persona", p."PER_Primer_Nombre", p."PER_Primer_Apellido"', [...$pPer, ...$pMov])));
        $agregar('FALTANTE_PENDIENTE', 'alta', fn (): array => array_map(static fn (array $f): array => ['id' => (int) $f[0], 'codigo' => $f[1], 'estado' => $f[2]],
            $filas("SELECT f.\"FAL_Faltante\", a.\"ACT_Codigo_Interno\", f.\"FAL_Estado\" FROM \"INV_FALTANTE\" f JOIN \"INV_ACTIVO\" a ON a.\"ACT_Activo\" = f.\"ACT_Activo\" "
                ."WHERE f.\"FAL_Estado\" IN ('PENDIENTE', 'EN_INVESTIGACION') AND {$aAct} ORDER BY f.\"FAL_Fecha\"")));
        $agregar('RESPONSABLE_INACTIVO', 'media', fn (): array => array_map(static fn (array $f): array => ['id' => Ident::aTexto((string) $f[0]), 'codigo' => $f[1], 'nombre' => $f[2].' '.$f[3]],
            $filas('SELECT a."ACT_Activo", a."ACT_Codigo_Interno", p."PER_Primer_Nombre", p."PER_Primer_Apellido" FROM "INV_ACTIVO" a JOIN "INV_PERSONA" p ON p."PER_Persona" = a."ACT_Responsable" '
                ."WHERE a.\"ACT_Instalado\" = 1 AND p.\"PER_Estado\" = 0 AND {$aAct}")));
        $agregar('INSUMOS_POR_RECLASIFICAR', 'baja', fn (): array => array_map(static fn (array $f): array => ['id' => (int) $f[0], 'nombre' => $f[1], 'cantidad' => (int) $f[2]],
            $filas('SELECT t."TAC_Tipo_Activo", t."TAC_Nombre", COUNT(a."ACT_Activo") FROM "INV_TIPO_ACTIVO" t JOIN "INV_ACTIVO" a ON a."TAC_Tipo_Activo" = t."TAC_Tipo_Activo" '
                ."WHERE t.\"TAC_Clase\" = 'INSUMO'".(null === $baja ? '' : ' AND a."EOP_Estado_Operativo" <> :baja')." AND {$aAct} GROUP BY t.\"TAC_Tipo_Activo\", t.\"TAC_Nombre\"",
                null === $baja ? [] : ['baja' => $baja])));
        $agregar('PRESTAMO_VENCIDO', 'media', fn (): array => array_map(static fn (array $f): array => ['id' => Ident::aTexto((string) $f[0]), 'codigo' => $f[1], 'fecha' => substr((string) $f[2], 0, 10)],
            $this->conexion->fetchAllNumeric('SELECT m."MOV_Movimiento", a."ACT_Codigo_Interno", m."MOV_Fecha_Prevista_Devolucion" FROM "INV_MOVIMIENTO" m '
                .'JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" WHERE m."MOV_Fecha_Devolucion" IS NULL AND m."MOV_Fecha_Prevista_Devolucion" IS NOT NULL '
                ."AND m.\"MOV_Fecha_Prevista_Devolucion\" < TO_DATE(:hoy, 'YYYY-MM-DD') AND {$aMov}", [...$pMov, 'hoy' => $hoy])));
        [$aCon, $pCon] = $this->alcance->lectura('INV_CONSUMIBLE', 'c');
        $agregar('STOCK_BAJO', 'media', fn (): array => array_map(static fn (array $f): array => ['id' => (int) $f[0], 'nombre' => $f[1], 'stock' => (int) $f[2], 'minimo' => (int) $f[3]],
            $this->conexion->fetchAllNumeric('SELECT c."CON_Consumible", c."CON_Nombre", c."CON_Stock_Actual", c."CON_Stock_Minimo" FROM "INV_CONSUMIBLE" c WHERE c."CON_Activo" = 1 '
                ."AND c.\"CON_Stock_Minimo\" > 0 AND c.\"CON_Stock_Actual\" <= c.\"CON_Stock_Minimo\" AND {$aCon}", $pCon)));
        [$aIng, $pIng] = $this->alcance->lectura('PUR_GOODS_RECEIPT', 'g');
        $agregar('INGRESO_POR_CONFIRMAR', 'media', fn (): array => array_map(static fn (array $f): array => ['id' => (int) $f[0], 'codigo' => $f[1], 'documento' => $f[2]],
            $this->conexion->fetchAllNumeric("SELECT g.GRC_GOODS_RECEIPT, g.GRC_CODE, g.GRC_DOCUMENT_NUMBER FROM PUR_GOODS_RECEIPT g WHERE g.GRC_STATUS = 'REGISTERED' AND {$aIng} ORDER BY g.GRC_GOODS_RECEIPT", $pIng)));
        $agregar('ACTIVO_POR_COMPLETAR', 'media', fn (): array => $activos('a."ACT_Pending_Completion" = 1'));
        $agregar('TICKET_ANTIGUO', 'media', fn (): array => array_map(fn (array $f): array => ['id' => Ident::aTexto((string) $f[0]), 'codigo' => $f[1],
            'desde' => Reloj::desdeBd((string) $f[2])?->setTimezone(new \DateTimeZone($this->ajustes->zonaHoraria))->format('Y-m-d')],
            $filas('SELECT m."MAN_Mantenimiento", a."ACT_Codigo_Interno", m."MAN_Fecha_Ingreso" FROM "INV_MANTENIMIENTO" m JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" '
                ."WHERE m.\"MAN_Fecha_Cierre\" IS NULL AND m.\"MAN_Fecha_Ingreso\" < :limite AND {$aAct}", ['limite' => Reloj::aBd(Reloj::ahora()->modify('-15 days'))])));
        $vigentes = null === $baja ? '1 = 1' : 'a."EOP_Estado_Operativo" <> :baja';
        $pBaja = null === $baja ? [] : ['baja' => $baja];
        $agregar('GARANTIA_POR_VENCER', 'baja', fn (): array => $activos($vigentes." AND a.\"ACT_Fin_Garantia\" IS NOT NULL AND a.\"ACT_Fin_Garantia\" >= TO_DATE(:hoy, 'YYYY-MM-DD') "
            ."AND a.\"ACT_Fin_Garantia\" <= TO_DATE(:hoy, 'YYYY-MM-DD') + 90", [...$pBaja, 'hoy' => $hoy]));
        $agregar('LICENCIA_POR_VENCER', 'media', fn (): array => array_map(static fn (array $f): array => ['id' => (int) $f[0], 'nombre' => $f[1], 'fecha' => substr((string) $f[2], 0, 10),
            'vencida' => substr((string) $f[2], 0, 10) < $hoy], $this->conexion->fetchAllNumeric('SELECT l."LIC_Licencia", s."SOF_Nombre", l."LIC_Fecha_Vencimiento" FROM "INV_LICENCIA" l '
                .'JOIN "INV_SOFTWARE" s ON s."SOF_Software" = l."SOF_Software" WHERE l."LIC_Fecha_Vencimiento" IS NOT NULL '
                ."AND l.\"LIC_Fecha_Vencimiento\" <= TO_DATE(:hoy, 'YYYY-MM-DD') + :dias ORDER BY l.\"LIC_Fecha_Vencimiento\"", ['hoy' => $hoy, 'dias' => self::ALERTA_LICENCIAS_DIAS])));
        $cuentas = fn (string $cond, array $p = []): array => array_map(static fn (array $f): array => ['id' => (int) $f[0], 'licencia' => (int) $f[1], 'usuario' => $f[2], 'nombre' => $f[3]],
            $this->conexion->fetchAllNumeric('SELECT c."LCL_Licencia_Clave", c."LIC_Licencia", c."LCL_Usuario", s."SOF_Nombre" FROM "INV_LICENCIA_CLAVE" c '
                .'JOIN "INV_LICENCIA" l ON l."LIC_Licencia" = c."LIC_Licencia" JOIN "INV_SOFTWARE" s ON s."SOF_Software" = l."SOF_Software" '
                ."WHERE c.\"LCL_Estado\" <> 'BAJA' AND {$cond}", $p));
        $agregar('CUENTA_POR_CAMBIAR', 'alta', fn (): array => $cuentas('c."LCL_Requiere_Cambio" = 1'));
        $agregar('CUENTA_SIN_ROTAR', 'baja', fn (): array => $cuentas('c."LCL_Secreto_Enc" IS NOT NULL AND c."LCL_Requiere_Cambio" = 0 AND NVL(c."LCL_Ultimo_Cambio_Secreto", c."CREATED_AT") < :limite',
            ['limite' => Reloj::aBd(Reloj::ahora()->modify('-'.self::ROTACION_CUENTAS_DIAS.' days'))]), ['dias' => self::ROTACION_CUENTAS_DIAS]);
        $agregar('CUPOS_AGOTADOS', 'media', fn (): array => array_map(static fn (array $f): array => ['id' => (int) $f[0], 'nombre' => $f[1], 'total' => (int) $f[2]],
            $this->conexion->fetchAllNumeric('SELECT l."LIC_Licencia", s."SOF_Nombre", l."LIC_Cantidad_Total" FROM "INV_LICENCIA" l JOIN "INV_SOFTWARE" s ON s."SOF_Software" = l."SOF_Software" '
                ."LEFT JOIN \"INV_FORMA_CONTEO\" fc ON fc.\"FCO_Forma\" = l.\"FCO_Forma_Conteo\" WHERE l.\"LIC_Cantidad_Usada\" >= l.\"LIC_Cantidad_Total\" AND NVL(fc.\"FCO_Codigo\", ' ') <> 'SITIO'")));
        if ($quiere('DATOS_INCOMPLETOS')) {
            // Un solo recorrido para los dos conteos.
            [$sinGarantia, $sinCosto] = array_map('intval', (array) $this->conexion->fetchNumeric('SELECT NVL(SUM(CASE WHEN a."ACT_Fin_Garantia" IS NULL THEN 1 ELSE 0 END), 0), '
                .'NVL(SUM(CASE WHEN a."ACT_Costo" IS NULL THEN 1 ELSE 0 END), 0) FROM "INV_ACTIVO" a WHERE '.$vigentes.' AND '.$aAct, [...$pAct, ...$pBaja]));
            if ($sinGarantia > 0 || $sinCosto > 0) {
                $items[] = ['clave' => 'DATOS_INCOMPLETOS', 'severidad' => 'baja', 'total' => $sinGarantia + $sinCosto, 'sin_garantia' => $sinGarantia, 'sin_costo' => $sinCosto, 'items' => []];
            }
        }
        $orden = ['alta' => 0, 'media' => 1, 'baja' => 2];
        usort($items, static fn (array $a, array $b): int => $orden[$a['severidad']] <=> $orden[$b['severidad']]);

        return ['generado' => Reloj::iso(Reloj::ahora()), 'items' => $items];
    }
}
