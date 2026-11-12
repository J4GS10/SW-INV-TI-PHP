<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure;

use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Kardex unificado (INV_KARDEX): cada entrada, salida, reasignación y cambio de
 * un activo, consumible o licencia. Solo inserción; el disparador
 * TRG_KARDEX_SELLAR asigna la secuencia y encadena el hash (sellado en Oracle,
 * igual para los dos backends).
 *
 * Uso dentro de la transacción del servicio:
 *
 *   $kardex->registrar('ASIGNACION', activo: $activo, usuario: $u,
 *       destino: ['persona' => $hex, 'area' => 3, 'sede' => 1], documento: ['MOVIMIENTO', '15']);
 */
final readonly class Kardex
{
    public const array EVENTOS_CONSUMIBLE = ['CONSUMIBLE_ENTRADA', 'CONSUMIBLE_SALIDA', 'CONSUMIBLE_AJUSTE', 'CONSUMIBLE_TRASLADO'];
    public const array EVENTOS_LICENCIA = ['LICENCIA_ALTA', 'LICENCIA_ASIGNADA', 'LICENCIA_RETIRADA', 'LICENCIA_AJUSTE', 'LICENCIA_BAJA',
        'LICENCIA_CREDENCIAL_ALTA', 'LICENCIA_SECRETO_CAMBIADO', 'LICENCIA_SECRETO_VISTO'];
    public const array EVENTOS_ENTRADA = ['ALTA', 'RECEPCION', 'CONSUMIBLE_ENTRADA', 'LICENCIA_ALTA'];
    public const array EVENTOS_SALIDA = ['BAJA', 'RECLASIFICACION', 'CONSUMIBLE_SALIDA', 'LICENCIA_BAJA'];
    public const array EVENTOS_REASIGNACION = ['ASIGNACION', 'PRESTAMO', 'DEVOLUCION', 'TRANSFERENCIA', 'INSTALACION_AREA', 'RETIRO_AREA',
        'COMPONENTE_INSTALADO', 'COMPONENTE_RETIRADO', 'LICENCIA_ASIGNADA', 'LICENCIA_RETIRADA', 'CONSUMIBLE_TRASLADO'];

    private const string COLUMNAS = 'k."KDX_Evento", k."KDX_Fecha", k."KDX_Tipo", k."KDX_Objeto", k."ACT_Activo", k."CON_Consumible", '
        .'k."LIC_Licencia", k."LCL_Licencia_Clave", k."KDX_Referencia", k."KDX_Origen_Persona", k."KDX_Origen_Area", k."KDX_Origen_Sede", '
        .'k."KDX_Origen_Activo", k."KDX_Destino_Persona", k."KDX_Destino_Area", k."KDX_Destino_Sede", k."KDX_Destino_Activo", '
        .'k."KDX_Cantidad", k."KDX_Saldo", k."KDX_Costo", k."KDX_Moneda", k."KDX_Saldo_Valor", k."KDX_Documento_Tipo", '
        .'k."KDX_Documento_Ref", k."KDX_Motivo", k."KDX_Detalle", k."USU_Usuario", k."SED_Sede", k."KDX_Migrado", '
        .'k."KDX_Secuencia", k."KDX_Hash"';

    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /**
     * Documento de un evento para leerlo: sin identificadores internos. Un movimiento solo se identifica por su
     * id, así que no aporta nada; los demás conservan el nombre del documento.
     */
    public static function documentoLegible(?string $tipo, ?string $referencia): string
    {
        $interno = null !== $referencia && 1 === preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', trim($referencia));
        if ($interno && 'MOVIMIENTO' === $tipo) {
            return '';
        }

        return trim(($tipo ?? '').' '.($interno ? '' : ($referencia ?? '')));
    }

    /**
     * "LPT00001 · SN123" (código y serie del activo).
     *
     * @param array<string, mixed>|null $activo
     */
    public static function referenciaActivo(?array $activo): ?string
    {
        if (null === $activo) {
            return null;
        }
        $ref = implode(' · ', array_filter([$activo['ACT_Codigo_Interno'] ?? null, $activo['ACT_Serie_Fabricante'] ?? null],
            static fn (mixed $p): bool => null !== $p && '' !== $p));

        return '' === $ref ? null : mb_substr($ref, 0, 200);
    }

    public static function objeto(string $tipo): string
    {
        return match (true) {
            \in_array($tipo, self::EVENTOS_CONSUMIBLE, true) => 'CONSUMIBLE',
            \in_array($tipo, self::EVENTOS_LICENCIA, true) => 'LICENCIA',
            default => 'ACTIVO',
        };
    }

    /**
     * Registra un evento. `$activo` es la fila de INV_ACTIVO (con ACT_Activo en hex);
     * personas y activos de origen/destino, en hex.
     *
     * @param array<string, mixed>|null                                  $activo
     * @param array{persona?: ?string, area?: ?int, sede?: ?int, activo?: ?string} $origen
     * @param array{persona?: ?string, area?: ?int, sede?: ?int, activo?: ?string} $destino
     * @param array{0: string, 1: string|int|null}|null                   $documento
     * @param array<string, mixed>|null                                  $detalle
     */
    public function registrar(
        string $tipo,
        ?array $activo = null,
        ?string $activoId = null,
        ?int $consumible = null,
        ?int $licencia = null,
        ?int $clave = null,
        ?string $referencia = null,
        array $origen = [],
        array $destino = [],
        ?int $cantidad = null,
        ?int $saldo = null,
        string|int|float|null $costo = null,
        ?string $moneda = null,
        string|int|float|null $saldoValor = null,
        ?array $documento = null,
        ?string $motivo = null,
        ?array $detalle = null,
        ?string $usuario = null,
        ?\DateTimeImmutable $fecha = null,
        ?int $sede = null,
    ): void {
        $activoId ??= null === $activo ? null : (string) $activo['ACT_Activo'];
        $sedeEvento = $sede ?: ($destino['sede'] ?? null) ?: ($origen['sede'] ?? null)
            ?: (null === $activo || null === ($activo['SED_Sede'] ?? null) ? null : (int) $activo['SED_Sede']);
        $detalle = null === $detalle ? null : self::json($detalle);
        $motivo = null === $motivo || '' === $motivo ? null : mb_substr($motivo, 0, 500);
        $referencia = (null === $referencia || '' === $referencia ? null : $referencia) ?? self::referenciaActivo($activo);

        $this->conexion->executeStatement(
            'INSERT INTO "INV_KARDEX" ("KDX_Fecha", "KDX_Tipo", "KDX_Objeto", "ACT_Activo", "CON_Consumible", "LIC_Licencia", '
            .'"LCL_Licencia_Clave", "KDX_Referencia", "KDX_Origen_Persona", "KDX_Origen_Area", "KDX_Origen_Sede", "KDX_Origen_Activo", '
            .'"KDX_Destino_Persona", "KDX_Destino_Area", "KDX_Destino_Sede", "KDX_Destino_Activo", "KDX_Cantidad", "KDX_Saldo", '
            .'"KDX_Costo", "KDX_Moneda", "KDX_Saldo_Valor", "KDX_Documento_Tipo", "KDX_Documento_Ref", "KDX_Motivo", "KDX_Detalle", '
            .'"USU_Usuario", "SED_Sede", "KDX_Migrado") VALUES (:fecha, :tipo, :objeto, :activo, :consumible, :licencia, :clave, :ref, '
            .':op, :oa, :os, :oact, :dp, :da, :ds, :dact, :cantidad, :saldo, :costo, :moneda, :saldo_valor, :doc_tipo, :doc_ref, '
            .':motivo, :detalle, :usuario, :sede, 0)',
            [
                'fecha' => Reloj::aBd($fecha ?? Reloj::ahora()),
                'tipo' => $tipo,
                'objeto' => self::objeto($tipo),
                'activo' => $activoId,
                'consumible' => $consumible,
                'licencia' => $licencia,
                'clave' => $clave,
                'ref' => null === $referencia ? null : mb_substr($referencia, 0, 200),
                'op' => $origen['persona'] ?? null,
                'oa' => $origen['area'] ?? null,
                'os' => $origen['sede'] ?? null,
                'oact' => $origen['activo'] ?? null,
                'dp' => $destino['persona'] ?? null,
                'da' => $destino['area'] ?? null,
                'ds' => $destino['sede'] ?? null,
                'dact' => $destino['activo'] ?? null,
                'cantidad' => $cantidad,
                'saldo' => $saldo,
                'costo' => null === $costo ? null : (string) $costo,
                'moneda' => $moneda,
                'saldo_valor' => null === $saldoValor ? null : (string) $saldoValor,
                'doc_tipo' => $documento[0] ?? null,
                'doc_ref' => null === $documento || null === $documento[1] || '' === (string) $documento[1] ? null : mb_substr((string) $documento[1], 0, 120),
                'motivo' => $motivo,
                'detalle' => null === $detalle || [] === $detalle ? null
                    : json_encode($detalle, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
                'usuario' => $usuario,
                'sede' => $sedeEvento,
            ],
            ['detalle' => ParameterType::LARGE_OBJECT],
        );
    }

    /**
     * Detalle serializable como en el backend anterior: sin nulos en los objetos,
     * fechas a texto.
     */
    public static function json(mixed $valor): mixed
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }
        if (!\is_array($valor)) {
            return $valor;
        }
        if (array_is_list($valor)) {
            return array_map(self::json(...), $valor);
        }
        $salida = [];
        foreach ($valor as $k => $v) {
            if (null !== $v) {
                $salida[$k] = self::json($v);
            }
        }

        return $salida;
    }

    // ------------------------------------------------------------------
    // Consulta
    // ------------------------------------------------------------------

    /**
     * Filtros de consulta: [sql, parámetros] (alias `k`). Incluye el alcance del lector.
     *
     * @param array{objeto?: ?string, tipos?: ?list<string>, categoria?: ?string, activo?: ?string, consumible?: ?int,
     *     licencia?: ?int, persona?: ?string, sede?: ?int, desde?: ?string, hasta?: ?string, texto?: ?string} $f
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    public function filtros(array $f): array
    {
        [$sql, $p] = $this->alcance->lectura('INV_KARDEX', 'k');
        $tipos = [];
        $agregar = static function (string $condicion) use (&$sql): void {
            $sql .= ' AND '.$condicion;
        };
        if (null !== ($f['objeto'] ?? null)) {
            $agregar('k."KDX_Objeto" = :objeto');
            $p['objeto'] = $f['objeto'];
        }
        $listas = [];
        if ([] !== ($f['tipos'] ?? [])) {
            $listas[] = $f['tipos'];
        }
        $listas[] = match ($f['categoria'] ?? null) {
            'ENTRADAS' => self::EVENTOS_ENTRADA,
            'SALIDAS' => self::EVENTOS_SALIDA,
            'ENTRADAS_SALIDAS' => [...self::EVENTOS_ENTRADA, ...self::EVENTOS_SALIDA],
            'REASIGNACIONES' => self::EVENTOS_REASIGNACION,
            default => null,
        };
        foreach (array_filter($listas) as $n => $lista) {
            $nombres = [];
            foreach ($lista as $i => $t) {
                $nombres[] = ":t{$n}_{$i}";
                $tipos["t{$n}_{$i}"] = $t;
            }
            $agregar('k."KDX_Tipo" IN ('.implode(', ', $nombres).')');
        }
        if (null !== ($f['activo'] ?? null)) {
            // Un equipo también muestra los eventos de sus componentes.
            $agregar('(k."ACT_Activo" = :activo OR k."KDX_Destino_Activo" = :activo OR k."KDX_Origen_Activo" = :activo)');
            $p['activo'] = $f['activo'];
        }
        if (null !== ($f['consumible'] ?? null)) {
            $agregar('k."CON_Consumible" = :consumible');
            $p['consumible'] = $f['consumible'];
        }
        if (null !== ($f['licencia'] ?? null)) {
            $agregar('k."LIC_Licencia" = :licencia');
            $p['licencia'] = $f['licencia'];
        }
        if (null !== ($f['persona'] ?? null)) {
            $agregar('(k."KDX_Origen_Persona" = :persona OR k."KDX_Destino_Persona" = :persona)');
            $p['persona'] = $f['persona'];
        }
        if (null !== ($f['sede'] ?? null)) {
            $agregar('(k."SED_Sede" = :fsede OR k."KDX_Origen_Sede" = :fsede OR k."KDX_Destino_Sede" = :fsede)');
            $p['fsede'] = $f['sede'];
        }
        if (null !== ($f['desde'] ?? null)) {
            $agregar('k."KDX_Fecha" >= :desde');
            $p['desde'] = $f['desde'].' 00:00:00.000000';
        }
        if (null !== ($f['hasta'] ?? null)) {
            $agregar('k."KDX_Fecha" <= :hasta');
            $p['hasta'] = $f['hasta'].' 23:59:59.999999';
        }
        $texto = trim((string) ($f['texto'] ?? ''));
        if ('' !== $texto) {
            $agregar('(LOWER(k."KDX_Referencia") LIKE :texto OR LOWER(k."KDX_Motivo") LIKE :texto OR LOWER(k."KDX_Documento_Ref") LIKE :texto)');
            $p['texto'] = '%'.mb_strtolower($texto).'%';
        }

        return [$sql, [...$p, ...$tipos], []];
    }

    /**
     * Página de eventos (más recientes primero) y total.
     *
     * @param array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} $filtro
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function listar(array $filtro, int $limite = 50, int $desplazamiento = 0): array
    {
        [$sql, $p] = $filtro;
        $total = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_KARDEX" k WHERE '.$sql, $p);
        $filas = $this->conexion->fetchAllAssociative('SELECT '.self::COLUMNAS.' FROM "INV_KARDEX" k WHERE '.$sql
            .' ORDER BY k."KDX_Fecha" DESC, k."KDX_Evento" DESC OFFSET :saltar ROWS FETCH NEXT :limite ROWS ONLY',
            [...$p, 'saltar' => $desplazamiento, 'limite' => $limite]);

        return [array_map(self::aApi(...), $filas), $total];
    }

    /**
     * Todos los eventos del filtro en orden cronológico (exportes y hoja de vida), con tope.
     *
     * @param array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} $filtro
     *
     * @return list<array<string, mixed>>
     */
    public function todos(array $filtro, int $maximo = 20000): array
    {
        [$sql, $p] = $filtro;

        return array_map(self::aApi(...), $this->conexion->fetchAllAssociative('SELECT '.self::COLUMNAS.' FROM "INV_KARDEX" k WHERE '.$sql
            .' ORDER BY k."KDX_Fecha", k."KDX_Evento" FETCH FIRST :maximo ROWS ONLY', [...$p, 'maximo' => $maximo]));
    }

    /**
     * Eventos de la condición SQL dada (más recientes primero), sin alcance adicional.
     *
     * @param array<string, mixed> $parametros
     *
     * @return list<array<string, mixed>>
     */
    public function donde(string $condicion, array $parametros): array
    {
        return array_map(self::aApi(...), $this->conexion->fetchAllAssociative(
            'SELECT '.self::COLUMNAS.' FROM "INV_KARDEX" k WHERE '.$condicion.' ORDER BY k."KDX_Fecha" DESC, k."KDX_Evento" DESC', $parametros));
    }

    /**
     * Fila → evento de la API (esquema KardexEvento del backend anterior).
     *
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    public static function aApi(array $f): array
    {
        $uuid = static fn (mixed $v): ?string => null === $v ? null : Ident::aTexto((string) $v);
        $entero = static fn (mixed $v): ?int => null === $v ? null : (int) $v;

        return [
            'KDX_Evento' => (int) $f['KDX_Evento'],
            'KDX_Fecha' => Reloj::iso(Reloj::desdeBd((string) $f['KDX_Fecha'])),
            'KDX_Tipo' => $f['KDX_Tipo'],
            'KDX_Objeto' => $f['KDX_Objeto'],
            'ACT_Activo' => $uuid($f['ACT_Activo']),
            'CON_Consumible' => $entero($f['CON_Consumible']),
            'LIC_Licencia' => $entero($f['LIC_Licencia']),
            'LCL_Licencia_Clave' => $entero($f['LCL_Licencia_Clave']),
            'KDX_Referencia' => $f['KDX_Referencia'],
            'KDX_Origen_Persona' => $uuid($f['KDX_Origen_Persona']),
            'KDX_Origen_Area' => $entero($f['KDX_Origen_Area']),
            'KDX_Origen_Sede' => $entero($f['KDX_Origen_Sede']),
            'KDX_Origen_Activo' => $uuid($f['KDX_Origen_Activo']),
            'KDX_Destino_Persona' => $uuid($f['KDX_Destino_Persona']),
            'KDX_Destino_Area' => $entero($f['KDX_Destino_Area']),
            'KDX_Destino_Sede' => $entero($f['KDX_Destino_Sede']),
            'KDX_Destino_Activo' => $uuid($f['KDX_Destino_Activo']),
            'KDX_Cantidad' => $entero($f['KDX_Cantidad']),
            'KDX_Saldo' => $entero($f['KDX_Saldo']),
            'KDX_Costo' => null === $f['KDX_Costo'] ? null : \App\Crud\Esquema::decimal((string) $f['KDX_Costo'], 4),
            'KDX_Moneda' => $f['KDX_Moneda'],
            'KDX_Saldo_Valor' => null === $f['KDX_Saldo_Valor'] ? null : \App\Crud\Esquema::decimal((string) $f['KDX_Saldo_Valor'], 2),
            'KDX_Documento_Tipo' => $f['KDX_Documento_Tipo'],
            'KDX_Documento_Ref' => $f['KDX_Documento_Ref'],
            'KDX_Motivo' => $f['KDX_Motivo'],
            'KDX_Detalle' => null === $f['KDX_Detalle'] ? null : json_decode((string) $f['KDX_Detalle'], true),
            'USU_Usuario' => $uuid($f['USU_Usuario']),
            'SED_Sede' => $entero($f['SED_Sede']),
            'KDX_Migrado' => 1 === (int) $f['KDX_Migrado'],
            'KDX_Secuencia' => $entero($f['KDX_Secuencia']),
            'KDX_Hash' => $f['KDX_Hash'],
        ];
    }
}
