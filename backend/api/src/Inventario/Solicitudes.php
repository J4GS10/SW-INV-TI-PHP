<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Db\InsercionOracle;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Http\ErrorApi;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Solicitudes que esperan la aprobación del Jefe de TI (separación de
 * funciones): toda salida o pérdida de inventario se valida al pedirla y se
 * ejecuta solo cuando otra persona la aprueba (App\Inventario\Aprobaciones).
 */
final readonly class Solicitudes
{
    private const string COLUMNAS = 'a."APR_Aprobacion", a."APR_Tipo", a."APR_Estado", a."APR_Payload", a."APR_Resumen", a."APR_Motivo", a."ACT_Activo", '
        .'a."CON_Consumible", a."SED_Sede", a."APR_Solicitante", a."APR_Fecha_Solicitud", a."APR_Aprobador", a."APR_Fecha_Resolucion", a."APR_Comentario"';

    public function __construct(
        private Connection $conexion,
        private Alcance $alcance,
        private GobiernoRepositorio $gobierno,
        private Activos $activos,
        private Reclasificacion $reclasificacion,
        private Operador $operador,
        private Transaccion $transaccion,
    ) {
    }

    /**
     * Crea la solicitud PENDIENTE (dentro de la transacción en curso).
     *
     * @param array<string, mixed> $payload
     */
    public function crear(string $tipo, array $payload, ?string $solicitante, string $resumen, ?string $motivo = null, ?string $activo = null,
        ?int $consumible = null, ?int $sede = null, ?string $ip = null): int
    {
        if (null === $solicitante) {
            throw new ErrorApi(400, 'APPROVAL_REQUIRES_REQUESTER');
        }
        $resumen = mb_substr($resumen, 0, 500);
        // RETURNING no admite el CLOB: se inserta con un payload vacío y se escribe después.
        // (La solicitud siempre es visible para quien la crea: el VPD incluye las propias.)
        $id = (int) InsercionOracle::insertar($this->conexion, 'INV_APROBACION', ['APR_Tipo' => $tipo, 'APR_Estado' => 'PENDIENTE', 'APR_Payload' => '{}',
            'APR_Resumen' => $resumen, 'APR_Motivo' => null === $motivo || '' === $motivo ? null : mb_substr($motivo, 0, 500), 'ACT_Activo' => $activo,
            'CON_Consumible' => $consumible, 'SED_Sede' => $sede, 'APR_Solicitante' => $solicitante,
            'APR_Fecha_Solicitud' => Reloj::aBd(Reloj::ahora())], 'APR_Aprobacion');
        $this->conexion->executeStatement('UPDATE "INV_APROBACION" SET "APR_Payload" = :p WHERE "APR_Aprobacion" = :id',
            ['p' => json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), 'id' => $id], ['p' => ParameterType::LARGE_OBJECT]);
        $this->gobierno->registrarAuditoria('APPROVAL_REQUEST', 'INV_APROBACION', ['id' => $id, 'tipo' => $tipo, 'resumen' => $resumen], $solicitante, $ip, sedeId: $sede);

        return $id;
    }

    /** 409 APPROVAL_ALREADY_PENDING si ya hay una solicitud pendiente del mismo tipo y sujeto. */
    public function exigirSinPendiente(string $tipo, ?int $consumible = null): void
    {
        $this->sinPendiente($tipo, $consumible);
    }

    private function sinPendiente(string $tipo, ?int $consumible = null): void
    {
        $sql = "SELECT \"APR_Aprobacion\" FROM \"INV_APROBACION\" WHERE \"APR_Tipo\" = :t AND \"APR_Estado\" = 'PENDIENTE'";
        $p = ['t' => $tipo];
        if (null !== $consumible) {
            $sql .= ' AND "CON_Consumible" = :c';
            $p['c'] = $consumible;
        }
        $pendiente = $this->conexion->fetchOne($sql.' FETCH FIRST 1 ROWS ONLY', $p);
        if (false !== $pendiente) {
            throw new ErrorApi(409, "APPROVAL_ALREADY_PENDING:{$pendiente}");
        }
    }

    /**
     * La baja se valida ahora y se ejecuta cuando el Jefe de TI la aprueba.
     *
     * @param array<string, mixed> $baja
     *
     * @return array<string, mixed>
     */
    public function solicitarBaja(string $activo, array $baja, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($activo, $baja, $usuario, $ip): array {
            // El activo bloqueado: dos solicitudes de baja simultáneas no pasan las dos la revisión de «ya pedida».
            $this->activos->bloquearEnOrden([$activo]);
            [$a] = $this->activos->validarBaja($activo, $baja);
            $pendiente = $this->conexion->fetchOne("SELECT \"APR_Aprobacion\" FROM \"INV_APROBACION\" WHERE \"ACT_Activo\" = :a AND \"APR_Tipo\" = 'BAJA' "
                ."AND \"APR_Estado\" = 'PENDIENTE' FETCH FIRST 1 ROWS ONLY", ['a' => $activo]);
            if (false !== $pendiente) {
                throw new ErrorApi(409, "DECOMMISSION_ALREADY_REQUESTED:{$pendiente}");
            }
            $id = $this->crear('BAJA', Esquemas::bajaJson($baja), $usuario,
                "Baja de {$a['ACT_Codigo_Interno']} ({$a['ACT_Serie_Fabricante']}): {$baja['motivo']} → {$baja['destino']}",
                $baja['observacion'], activo: $activo, sede: null === $a['SED_Sede'] ? null : (int) $a['SED_Sede'], ip: $ip);

            return $this->unaSinAlcance($id);
        });
    }

    /**
     * Un ajuste por conteo (suba o baje la existencia): se ejecuta cuando otra persona lo aprueba.
     *
     * @return array<string, mixed>
     */
    public function solicitarAjuste(int $consumible, int $real, string $motivo, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($consumible, $real, $motivo, $usuario, $ip): array {
            [$alcance, $p] = $this->alcance->lectura('INV_CONSUMIBLE', 'c');
            $c = $this->conexion->fetchAssociative('SELECT c."CON_Nombre", c."CON_Stock_Actual", c."CON_Unidad", c."SED_Sede" FROM "INV_CONSUMIBLE" c '
                .'WHERE c."CON_Consumible" = :id AND '.$alcance.' FOR UPDATE', [...$p, 'id' => $consumible]) ?: throw new ErrorApi(404, 'CONSUMABLE_NOT_FOUND');
            $actual = (int) $c['CON_Stock_Actual'];
            if ($real === $actual) {
                throw new ErrorApi(400, 'STOCK_ALREADY_MATCHES');
            }
            $this->sinPendiente('AJUSTE_CONSUMIBLE', consumible: $consumible);
            $diferencia = ($real > $actual ? '+' : '−').abs($real - $actual);
            $id = $this->crear('AJUSTE_CONSUMIBLE', ['CON_Stock_Real' => $real, 'MOC_Motivo' => $motivo, 'stock_antes' => $actual], $usuario,
                trim("Ajuste de {$c['CON_Nombre']}: {$actual} → {$real} ({$diferencia} ".($c['CON_Unidad'] ?? '').')'), $motivo,
                consumible: $consumible, sede: null === $c['SED_Sede'] ? null : (int) $c['SED_Sede'], ip: $ip);

            return $this->unaSinAlcance($id);
        });
    }

    /**
     * Los activos de un tipo «insumo» pasan a un consumible (por sede) al aprobarse.
     *
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public function solicitarReclasificacion(int $tipo, array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($tipo, $d, $usuario, $ip): array {
            // El tipo bloqueado: dos reclasificaciones simultáneas del mismo tipo no quedan pendientes a la vez.
            $this->conexion->fetchOne('SELECT 1 FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t FOR UPDATE', ['t' => $tipo]);
            $vista = $this->reclasificacion->vistaPrevia($tipo, $d['SED_Sede']);
            if ([] !== $vista['bloqueados']) {
                throw new ErrorApi(409, 'RECLASSIFY_ASSETS_IN_USE');
            }
            if (0 === $vista['activos']) {
                throw new ErrorApi(409, 'RECLASSIFY_NOTHING_TO_CONVERT');
            }
            $this->sinPendiente('RECLASIFICACION');
            $id = $this->crear('RECLASIFICACION', ['tipo_id' => $tipo, ...$d], $usuario,
                "Reclasificar {$vista['activos']} activos «{$vista['tipo']}» como el consumible «{$d['nombre']}»", $d['motivo'], sede: $d['SED_Sede'], ip: $ip);

            return $this->unaSinAlcance($id);
        });
    }

    // ------------------------------------------------------------------
    // Consulta
    // ------------------------------------------------------------------

    /**
     * Las de las sedes del alcance y las propias (igual que el VPD de INV_APROBACION).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function alcance(): array
    {
        $sedes = $this->alcance->sedesVisibles();
        if (null === $sedes) {
            return ['1 = 1', []];
        }
        [$filtro, $p] = $this->alcance->filtroSede('a."SED_Sede"', 'asede');

        return ["({$filtro} OR a.\"APR_Solicitante\" = :propio)", [...$p, 'propio' => $this->alcance->actual()?->usuarioId]];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, string> $nombres
     *
     * @return array<string, mixed>
     */
    public function aApi(array $a, ?array $nombres = null): array
    {
        $nombres ??= $this->operador->nombres([(string) $a['APR_Solicitante'], null === $a['APR_Aprobador'] ? null : (string) $a['APR_Aprobador']]);
        $uuid = static fn (mixed $v): ?string => null === $v ? null : Ident::aTexto((string) $v);

        return ['APR_Aprobacion' => (int) $a['APR_Aprobacion'], 'APR_Tipo' => $a['APR_Tipo'], 'APR_Estado' => $a['APR_Estado'], 'APR_Resumen' => $a['APR_Resumen'],
            'APR_Motivo' => $a['APR_Motivo'], 'APR_Payload' => json_decode((string) $a['APR_Payload'], true), 'ACT_Activo' => $uuid($a['ACT_Activo']),
            'CON_Consumible' => null === $a['CON_Consumible'] ? null : (int) $a['CON_Consumible'],
            'SED_Sede' => null === $a['SED_Sede'] ? null : (int) $a['SED_Sede'],
            'APR_Solicitante' => $uuid($a['APR_Solicitante']), 'solicitante_nombre' => $nombres[(string) $a['APR_Solicitante']] ?? null,
            'APR_Fecha_Solicitud' => Reloj::iso(Reloj::desdeBd((string) $a['APR_Fecha_Solicitud'])),
            'APR_Aprobador' => $uuid($a['APR_Aprobador']), 'aprobador_nombre' => null === $a['APR_Aprobador'] ? null : ($nombres[(string) $a['APR_Aprobador']] ?? null),
            'APR_Fecha_Resolucion' => Reloj::iso(Reloj::desdeBd($a['APR_Fecha_Resolucion'])), 'APR_Comentario' => $a['APR_Comentario']];
    }

    /** @return array<string, mixed>|null */
    public function fila(int $id, bool $bloquear = false): ?array
    {
        $f = $this->conexion->fetchAssociative('SELECT '.self::COLUMNAS.' FROM "INV_APROBACION" a WHERE a."APR_Aprobacion" = :id'.($bloquear ? ' FOR UPDATE' : ''), ['id' => $id]);

        return false === $f ? null : $f;
    }

    /**
     * La recién creada por el propio usuario (sin depender del alcance).
     *
     * @return array<string, mixed>
     */
    private function unaSinAlcance(int $id): array
    {
        return $this->aApi($this->fila($id) ?? throw new ErrorApi(404, 'APPROVAL_NOT_FOUND'));
    }

    /** @return list<array<string, mixed>> */
    public function listar(?string $estado, ?string $activo, int $limite = 200): array
    {
        [$sql, $p] = $this->alcance();
        if (null !== $estado && '' !== $estado) {
            $sql .= ' AND a."APR_Estado" = :estado';
            $p['estado'] = $estado;
        }
        if (null !== $activo) {
            $sql .= ' AND a."ACT_Activo" = :activo';
            $p['activo'] = $activo;
        }
        $filas = $this->conexion->fetchAllAssociative('SELECT '.self::COLUMNAS.' FROM "INV_APROBACION" a WHERE '.$sql
            .' ORDER BY a."APR_Fecha_Solicitud" DESC, a."APR_Aprobacion" DESC FETCH FIRST '.$limite.' ROWS ONLY', $p);
        $nombres = $this->operador->nombres([...array_map(static fn (array $f): string => (string) $f['APR_Solicitante'], $filas),
            ...array_map(static fn (array $f): ?string => null === $f['APR_Aprobador'] ? null : (string) $f['APR_Aprobador'], $filas)]);

        return array_map(fn (array $f): array => $this->aApi($f, $nombres), $filas);
    }

    public function pendientes(): int
    {
        [$sql, $p] = $this->alcance();

        return (int) $this->conexion->fetchOne("SELECT COUNT(*) FROM \"INV_APROBACION\" a WHERE \"APR_Estado\" = 'PENDIENTE' AND ".$sql, $p);
    }
}
