<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Db\InsercionOracle;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Toma de inventario físico (conciliación) por sede o área: cada lectura dice al
 * instante qué es el equipo y si está donde debería; al cerrar, lo esperado que
 * no se leyó queda como FALTANTE (con su seguimiento) y los hallazgos pasan al kardex.
 */
final readonly class Tomas
{
    private const string COLUMNAS = 't."TOM_Toma", t."SED_Sede", t."ARE_Area", t."TOM_Estado", t."TOM_Fecha_Inicio", t."TOM_Fecha_Cierre", t."TOM_Observacion", '
        .'t."USU_Usuario", t."TOM_Resumen"';

    public function __construct(
        private Connection $conexion,
        private Alcance $alcance,
        private Kardex $kardex,
        private EstadosActivo $estados,
        private Faltantes $faltantes,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
        private \Psr\Log\LoggerInterface $logger,
    ) {
    }

    /** Sobre esta cantidad de activos esperados, la toma se cierra en segundo plano. */
    private const int UMBRAL_SEGUNDO_PLANO = 500;

    /**
     * @param array<string, mixed> $t
     *
     * @return array<string, mixed> TomaResponse
     */
    private static function aApi(array $t): array
    {
        return ['TOM_Toma' => (int) $t['TOM_Toma'], 'SED_Sede' => (int) $t['SED_Sede'], 'ARE_Area' => null === $t['ARE_Area'] ? null : (int) $t['ARE_Area'],
            'TOM_Estado' => $t['TOM_Estado'], 'TOM_Fecha_Inicio' => Reloj::iso(Reloj::desdeBd((string) $t['TOM_Fecha_Inicio'])),
            'TOM_Fecha_Cierre' => Reloj::iso(Reloj::desdeBd($t['TOM_Fecha_Cierre'])), 'TOM_Observacion' => $t['TOM_Observacion'],
            'USU_Usuario' => null === $t['USU_Usuario'] ? null : Ident::aTexto((string) $t['USU_Usuario']),
            'TOM_Resumen' => null === $t['TOM_Resumen'] || 'CERRANDO' === $t['TOM_Estado'] ? null : json_decode((string) $t['TOM_Resumen'], true)];
    }

    /** @return array<string, mixed> */
    private function fila(int $id, bool $bloquear = false): array
    {
        [$alcance, $p] = $this->alcance->lectura('INV_TOMA_INVENTARIO', 't');
        $t = $this->conexion->fetchAssociative('SELECT '.self::COLUMNAS.' FROM "INV_TOMA_INVENTARIO" t WHERE t."TOM_Toma" = :id AND '.$alcance.($bloquear ? ' FOR UPDATE' : ''),
            [...$p, 'id' => $id]);

        return false === $t ? throw ErrorDominio::noEncontrado('INVENTORY_COUNT_NOT_FOUND') : $t;
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        [$alcance, $p] = $this->alcance->lectura('INV_TOMA_INVENTARIO', 't');

        return array_map(self::aApi(...), $this->conexion->fetchAllAssociative('SELECT '.self::COLUMNAS.' FROM "INV_TOMA_INVENTARIO" t WHERE '.$alcance
            .' ORDER BY t."TOM_Fecha_Inicio" DESC, t."TOM_Toma" DESC FETCH FIRST 200 ROWS ONLY', $p));
    }

    /** @return array<string, mixed> */
    public function crear(int $sede, ?int $area, ?string $observacion, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($sede, $area, $observacion, $usuario, $ip): array {
            $sede = (int) $this->alcance->exigirSede($sede);
            if (null !== $area) {
                $sedeArea = $this->conexion->fetchOne('SELECT e."SED_Sede" FROM "INV_AREA" a JOIN "INV_NIVEL" n ON n."NIV_Nivel" = a."NIV_Nivel" '
                    .'JOIN "INV_EDIFICIO" e ON e."EDI_Edificio" = n."EDI_Edificio" WHERE a."ARE_Area" = :a', ['a' => $area]);
                if (false === $sedeArea && false === $this->conexion->fetchOne('SELECT 1 FROM "INV_AREA" WHERE "ARE_Area" = :a', ['a' => $area])) {
                    throw ErrorDominio::noEncontrado('AREA_NOT_FOUND');
                }
                if ((int) $sedeArea !== $sede) {
                    throw ErrorDominio::invalido('AREA_NOT_IN_SEDE');
                }
            }
            $abierta = $this->conexion->fetchOne("SELECT \"TOM_Toma\" FROM \"INV_TOMA_INVENTARIO\" WHERE \"SED_Sede\" = :s AND \"TOM_Estado\" = 'ABIERTA' AND "
                .(null === $area ? '"ARE_Area" IS NULL' : '"ARE_Area" = :a').' FETCH FIRST 1 ROWS ONLY', null === $area ? ['s' => $sede] : ['s' => $sede, 'a' => $area]);
            if (false !== $abierta) {
                throw ErrorDominio::conflicto("INVENTORY_COUNT_ALREADY_OPEN:{$abierta}");
            }
            $id = (int) InsercionOracle::insertar($this->conexion, 'INV_TOMA_INVENTARIO', ['SED_Sede' => $sede, 'ARE_Area' => $area, 'TOM_Estado' => 'ABIERTA',
                'TOM_Fecha_Inicio' => Reloj::aBd(Reloj::ahora()), 'TOM_Observacion' => $observacion, 'USU_Usuario' => $usuario], 'TOM_Toma');
            $this->gobierno->registrarAuditoria('INVENTORY_COUNT_OPEN', 'INV_TOMA_INVENTARIO', ['toma' => $id, 'sede' => $sede, 'area' => $area], $usuario, $ip, sedeId: $sede);

            return self::aApi($this->fila($id));
        });
    }

    /**
     * Activos que el sistema dice que están en la sede (o el área) de la toma.
     *
     * @param array<string, mixed> $t
     *
     * @return list<array<string, mixed>>
     */
    private function esperados(array $t): array
    {
        $p = ['s' => (int) $t['SED_Sede']];
        // Un activo por completar aún no tiene serie ni custodia: no se espera en la toma.
        $sql = 'SELECT "ACT_Activo", "ACT_Codigo_Interno", "ARE_Area_Ubicacion" FROM "INV_ACTIVO" WHERE "ACT_Pending_Completion" = 0 AND "SED_Sede" = :s';
        $baja = $this->estados->idDe(EstadosActivo::BAJA);
        if (null !== $baja) {
            $sql .= ' AND "EOP_Estado_Operativo" <> :b';
            $p['b'] = $baja;
        }
        if (null !== $t['ARE_Area']) {
            $sql .= ' AND "ARE_Area_Ubicacion" = :a';
            $p['a'] = (int) $t['ARE_Area'];
        }

        return $this->alcance->elevado(fn (): array => $this->conexion->fetchAllAssociative($sql.' ORDER BY "ACT_Codigo_Interno"', $p));
    }

    /** @return list<array<string, mixed>> */
    private function lecturas(int $toma): array
    {
        return $this->conexion->fetchAllAssociative('SELECT "TLE_Lectura", "TLE_Codigo_Leido", "ACT_Activo", "TLE_Resultado", "ARE_Area", "TLE_Fecha", "TLE_Observacion" '
            .'FROM "INV_TOMA_LECTURA" WHERE "TOM_Toma" = :t ORDER BY "TLE_Lectura"', ['t' => $toma]);
    }

    /**
     * @param array<string, mixed> $l
     *
     * @return array<string, mixed> LecturaResponse
     */
    private static function lecturaAApi(array $l): array
    {
        return ['TLE_Lectura' => (int) $l['TLE_Lectura'], 'TLE_Codigo_Leido' => $l['TLE_Codigo_Leido'],
            'ACT_Activo' => null === $l['ACT_Activo'] ? null : Ident::aTexto((string) $l['ACT_Activo']), 'TLE_Resultado' => $l['TLE_Resultado'],
            'ARE_Area' => null === $l['ARE_Area'] ? null : (int) $l['ARE_Area'], 'TLE_Fecha' => Reloj::iso(Reloj::desdeBd((string) $l['TLE_Fecha'])),
            'TLE_Observacion' => $l['TLE_Observacion']];
    }

    /**
     * Lectura de un código (interno o de serie): resultado inmediato y avance.
     *
     * @return array<string, mixed> LecturaResultado
     */
    public function leer(int $tomaId, string $codigo, ?int $area, ?string $observacion, string $usuario): array
    {
        return $this->transaccion->ejecutar(function () use ($tomaId, $codigo, $area, $observacion, $usuario): array {
            $t = $this->fila($tomaId, true);
            if ('ABIERTA' !== $t['TOM_Estado']) {
                throw ErrorDominio::conflicto('INVENTORY_COUNT_NOT_OPEN');
            }
            $codigo = trim($codigo);
            // Se busca en toda la empresa: un equipo de otra sede también es un hallazgo.
            $activo = $this->alcance->elevado(fn (): mixed => $this->conexion->fetchAssociative('SELECT "ACT_Activo", "ACT_Codigo_Interno", "ACT_Hostname", '
                .'"EOP_Estado_Operativo", "SED_Sede", "ARE_Area_Ubicacion" FROM "INV_ACTIVO" WHERE LOWER("ACT_Codigo_Interno") = :c OR LOWER("ACT_Serie_Fabricante") = :c '
                .'ORDER BY "ACT_Codigo_Interno" FETCH FIRST 1 ROWS ONLY', ['c' => mb_strtolower($codigo)]));
            $activo = false === $activo ? null : $activo;
            $baja = $this->estados->idDe(EstadosActivo::BAJA);
            $lecturas = $this->lecturas($tomaId);
            $areaLectura = $area ?? (null === $t['ARE_Area'] ? null : (int) $t['ARE_Area']);
            $id = null === $activo ? null : (string) $activo['ACT_Activo'];
            $ubicacion = null === $activo || null === $activo['ARE_Area_Ubicacion'] ? null : (int) $activo['ARE_Area_Ubicacion'];
            $resultado = match (true) {
                null === $activo => 'NO_REGISTRADO',
                [] !== array_filter($lecturas, static fn (array $l): bool => (string) $l['ACT_Activo'] === $id && 'REPETIDO' !== $l['TLE_Resultado']) => 'REPETIDO',
                null !== $baja && (int) $activo['EOP_Estado_Operativo'] === $baja => 'DE_BAJA',
                (int) $activo['SED_Sede'] !== (int) $t['SED_Sede'] => 'OTRA_SEDE',
                null !== $areaLectura && null !== $ubicacion && $ubicacion !== $areaLectura => 'OTRA_UBICACION',
                null !== $t['ARE_Area'] && $ubicacion !== (int) $t['ARE_Area'] => 'OTRA_UBICACION',
                default => 'ENCONTRADO',
            };
            $lectura = (int) InsercionOracle::insertar($this->conexion, 'INV_TOMA_LECTURA', ['TOM_Toma' => $tomaId, 'TLE_Codigo_Leido' => mb_substr($codigo, 0, 100),
                'ACT_Activo' => $id, 'TLE_Resultado' => $resultado, 'ARE_Area' => $areaLectura, 'TLE_Fecha' => Reloj::aBd(Reloj::ahora()),
                'TLE_Observacion' => $observacion, 'USU_Usuario' => $usuario], 'TLE_Lectura');
            if (null !== $id && \in_array($resultado, ['ENCONTRADO', 'OTRA_UBICACION'], true)) {
                $this->faltantes->resolverPorLectura($id, $tomaId, $usuario);
            }
            $esperados = $this->esperados($t);
            $leidos = array_flip(array_filter([...array_map(static fn (array $l): string => (string) $l['ACT_Activo'], $lecturas), $id]));
            // De otra sede solo se informa el código (no sus datos: fuera del alcance).
            $visible = null !== $activo && 'OTRA_SEDE' !== $resultado;
            $fila = $this->conexion->fetchAssociative('SELECT "TLE_Lectura", "TLE_Codigo_Leido", "ACT_Activo", "TLE_Resultado", "ARE_Area", "TLE_Fecha", "TLE_Observacion" '
                .'FROM "INV_TOMA_LECTURA" WHERE "TLE_Lectura" = :l', ['l' => $lectura]);

            return [...self::lecturaAApi($fila), 'ACT_Activo' => $visible ? Ident::aTexto((string) $id) : null,
                'activo_codigo' => $activo['ACT_Codigo_Interno'] ?? null, 'activo_descripcion' => $visible ? $activo['ACT_Hostname'] : null,
                'mensaje' => $resultado, 'esperados' => \count($esperados),
                'leidos' => \count(array_filter($esperados, static fn (array $a): bool => isset($leidos[(string) $a['ACT_Activo']])))];
        });
    }

    /**
     * @param array<string, mixed> $t
     *
     * @return array<string, mixed>
     */
    private function resumen(array $t): array
    {
        if ('CERRADA' === $t['TOM_Estado'] && null !== $t['TOM_Resumen']) {
            return json_decode((string) $t['TOM_Resumen'], true) ?: [];
        }
        $esperados = $this->esperados($t);
        $lecturas = $this->lecturas((int) $t['TOM_Toma']);
        $leidos = [];
        foreach ($lecturas as $l) {
            if (null !== $l['ACT_Activo'] && 'REPETIDO' !== $l['TLE_Resultado']) {
                $leidos[(string) $l['ACT_Activo']] = true;
            }
        }
        $lista = static fn (string $r): array => array_values(array_map(static fn (array $l): array => ['codigo' => $l['TLE_Codigo_Leido'],
            'activo' => null === $l['ACT_Activo'] ? null : Ident::aTexto((string) $l['ACT_Activo']), 'area' => null === $l['ARE_Area'] ? null : (int) $l['ARE_Area']],
            array_filter($lecturas, static fn (array $l): bool => $l['TLE_Resultado'] === $r)));
        $faltantes = array_values(array_map(static fn (array $a): array => ['codigo' => $a['ACT_Codigo_Interno'], 'activo' => Ident::aTexto((string) $a['ACT_Activo']),
            'area' => null === $a['ARE_Area_Ubicacion'] ? null : (int) $a['ARE_Area_Ubicacion']],
            array_filter($esperados, static fn (array $a): bool => !isset($leidos[(string) $a['ACT_Activo']]))));
        $n = \count($esperados);

        return ['esperados' => $n, 'leidos' => \count($lecturas), 'encontrados' => \count($lista('ENCONTRADO')),
            'avance_pct' => $n > 0 ? round(100 * ($n - \count($faltantes)) / $n, 1) : 100.0, 'faltantes' => $faltantes,
            'otra_ubicacion' => $lista('OTRA_UBICACION'), 'otra_sede' => $lista('OTRA_SEDE'), 'de_baja' => $lista('DE_BAJA'),
            'no_registrados' => $lista('NO_REGISTRADO'), 'repetidos' => \count($lista('REPETIDO'))];
    }

    /** @return array<string, mixed> TomaDetalle */
    public function detalle(int $id): array
    {
        $t = $this->fila($id);

        return [...self::aApi($t), 'resumen' => $this->resumen($t), 'lecturas' => array_map(self::lecturaAApi(...), $this->lecturas($id))];
    }

    /** @return array<string, mixed> */
    public function cerrar(int $id, bool $actualizarUbicacion, string $usuario, ?string $ip): array
    {
        $this->transaccion->ejecutar(function () use ($id, $actualizarUbicacion, $usuario, $ip): void {
            $t = $this->fila($id, true);
            if ('ABIERTA' !== $t['TOM_Estado']) {
                throw ErrorDominio::conflicto('INVENTORY_COUNT_NOT_OPEN');
            }
            // Una toma grande no retiene la petición: el worker la cierra y la pantalla muestra el avance.
            if (\count($this->esperados($t)) > self::UMBRAL_SEGUNDO_PLANO) {
                $this->conexion->executeStatement("UPDATE \"INV_TOMA_INVENTARIO\" SET \"TOM_Estado\" = 'CERRANDO', \"TOM_Resumen\" = :r WHERE \"TOM_Toma\" = :id",
                    ['r' => json_encode(['actualizar_ubicacion' => $actualizarUbicacion, 'usuario' => $usuario, 'ip' => $ip], \JSON_THROW_ON_ERROR), 'id' => $id], ['r' => ParameterType::LARGE_OBJECT]);

                return;
            }
            $this->aplicarCierre($t, $id, $actualizarUbicacion, $usuario, $ip);
        });

        return $this->detalle($id);
    }

    /** Worker: cierra las tomas en CERRANDO. Si una falla, queda en CERRANDO y se reintenta en la siguiente pasada. */
    public function cerrarPendientes(): int
    {
        $cerradas = 0;
        foreach ($this->conexion->fetchFirstColumn("SELECT \"TOM_Toma\" FROM \"INV_TOMA_INVENTARIO\" WHERE \"TOM_Estado\" = 'CERRANDO' ORDER BY \"TOM_Toma\"") as $id) {
            $id = (int) $id;
            try {
                $this->transaccion->ejecutar(function () use ($id, &$cerradas): void {
                    $t = $this->fila($id, true);
                    if ('CERRANDO' !== $t['TOM_Estado']) {
                        return;
                    }
                    $pedido = json_decode((string) $t['TOM_Resumen'], true) ?: [];
                    $this->aplicarCierre($t, $id, true === ($pedido['actualizar_ubicacion'] ?? false), (string) $pedido['usuario'], $pedido['ip'] ?? null);
                    ++$cerradas;
                });
            } catch (\Throwable $e) {
                $this->logger->error('toma.cierre_fallido', ['toma' => $id, 'error' => $e::class]);
            }
        }

        return $cerradas;
    }

    /** @param array<string, mixed> $t */
    private function aplicarCierre(array $t, int $id, bool $actualizarUbicacion, string $usuario, ?string $ip): void
    {
        {
            $resumen = $this->resumen($t);
            $sede = (int) $t['SED_Sede'];
            $documento = ['TOMA_INVENTARIO', (string) $id];
            foreach ($resumen['faltantes'] as $f) {
                $this->kardex->registrar('INVENTARIO_FISICO', activoId: Ident::aHex($f['activo']), referencia: $f['codigo'], origen: ['sede' => $sede, 'area' => $f['area']],
                    documento: $documento, usuario: $usuario, motivo: 'No se encontró en la toma de inventario', detalle: ['resultado' => 'FALTANTE']);
            }
            foreach (['otra_ubicacion', 'de_baja'] as $clave) {
                foreach ($resumen[$clave] as $f) {
                    if (null === $f['activo']) {
                        continue;
                    }
                    $this->kardex->registrar('INVENTARIO_FISICO', activoId: Ident::aHex($f['activo']), referencia: $f['codigo'], destino: ['sede' => $sede, 'area' => $f['area']],
                        documento: $documento, usuario: $usuario, detalle: ['resultado' => strtoupper($clave)],
                        motivo: 'otra_ubicacion' === $clave ? 'Encontrado en otra área' : 'Figura de baja y apareció');
                    if ('otra_ubicacion' === $clave && $actualizarUbicacion && null !== $f['area']) {
                        $this->conexion->executeStatement('UPDATE "INV_ACTIVO" SET "ARE_Area_Ubicacion" = :a WHERE "ACT_Activo" = :id', ['a' => $f['area'], 'id' => Ident::aHex($f['activo'])]);
                    }
                }
            }
            $resumen['ubicaciones_actualizadas'] = $actualizarUbicacion;
            // Lo que no apareció queda para investigar (Tomas → Faltantes).
            $resumen['faltantes_nuevos'] = $this->faltantes->registrarDeToma($id, $sede, array_map(static fn (array $f): string => Ident::aHex($f['activo']), $resumen['faltantes']));
            $this->conexion->executeStatement("UPDATE \"INV_TOMA_INVENTARIO\" SET \"TOM_Estado\" = 'CERRADA', \"TOM_Fecha_Cierre\" = :f, \"TOM_Resumen\" = :r WHERE \"TOM_Toma\" = :id",
                ['f' => Reloj::aBd(Reloj::ahora()), 'r' => json_encode($resumen, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), 'id' => $id],
                ['r' => ParameterType::LARGE_OBJECT]);
            $this->gobierno->registrarAuditoria('INVENTORY_COUNT_CLOSE', 'INV_TOMA_INVENTARIO', ['toma' => $id, 'esperados' => $resumen['esperados'],
                'faltantes' => \count($resumen['faltantes']), 'otra_ubicacion' => \count($resumen['otra_ubicacion']), 'no_registrados' => \count($resumen['no_registrados'])],
                $usuario, $ip, sedeId: $sede);
        }
    }

    /** @return array<string, mixed> */
    public function anular(int $id, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): array {
            $t = $this->fila($id, true);
            if ('ABIERTA' !== $t['TOM_Estado']) {
                throw ErrorDominio::conflicto('INVENTORY_COUNT_NOT_OPEN');
            }
            $this->conexion->executeStatement("UPDATE \"INV_TOMA_INVENTARIO\" SET \"TOM_Estado\" = 'ANULADA', \"TOM_Fecha_Cierre\" = :f WHERE \"TOM_Toma\" = :id",
                ['f' => Reloj::aBd(Reloj::ahora()), 'id' => $id]);
            $this->gobierno->registrarAuditoria('INVENTORY_COUNT_CANCEL', 'INV_TOMA_INVENTARIO', ['toma' => $id], $usuario, $ip, sedeId: (int) $t['SED_Sede']);

            return self::aApi($this->fila($id));
        });
    }
}
