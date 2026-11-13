<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Inventario\Infrastructure\Kardex;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use App\Inventario\Infrastructure\DatosActivos;
use App\Inventario\Infrastructure\TomasStore;

/**
 * Toma de inventario físico (conciliación) por sede o área: cada lectura dice al
 * instante qué es el equipo y si está donde debería; al cerrar, lo esperado que
 * no se leyó queda como FALTANTE (con su seguimiento) y los hallazgos pasan al kardex.
 */
final readonly class Tomas
{
    public function __construct(
        private TomasStore $datos,
        private DatosActivos $activosDatos,
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
        $t = $this->datos->porId($id, $bloquear);

        return false === $t ? throw ErrorDominio::noEncontrado('INVENTORY_COUNT_NOT_FOUND') : $t;
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return array_map(self::aApi(...), $this->datos->visibles());
    }

    /** @return array<string, mixed> */
    public function crear(int $sede, ?int $area, ?string $observacion, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($sede, $area, $observacion, $usuario, $ip): array {
            $sede = (int) $this->alcance->exigirSede($sede);
            if (null !== $area) {
                $sedeArea = $this->activosDatos->sedeDeArea($area);
                if (false === $sedeArea && !$this->activosDatos->existeArea($area)) {
                    throw ErrorDominio::noEncontrado('AREA_NOT_FOUND');
                }
                if ((int) $sedeArea !== $sede) {
                    throw ErrorDominio::invalido('AREA_NOT_IN_SEDE');
                }
            }
            $abierta = $this->datos->abiertaEn($sede, $area);
            if (false !== $abierta) {
                throw ErrorDominio::conflicto("INVENTORY_COUNT_ALREADY_OPEN:{$abierta}");
            }
            $id = (int) $this->datos->insertar('INV_TOMA_INVENTARIO', ['SED_Sede' => $sede, 'ARE_Area' => $area, 'TOM_Estado' => 'ABIERTA',
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
        // Un activo por completar aún no tiene serie ni custodia: no se espera en la toma.
        return $this->datos->esperados((int) $t['SED_Sede'], null === $t['ARE_Area'] ? null : (int) $t['ARE_Area'], $this->estados->idDe(EstadosActivo::BAJA));
    }

    /** @return list<array<string, mixed>> */
    private function lecturas(int $toma): array
    {
        return $this->datos->lecturasDe($toma);
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
            $activo = $this->datos->activoPorCodigo($codigo);
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
            $lectura = (int) $this->datos->insertar('INV_TOMA_LECTURA', ['TOM_Toma' => $tomaId, 'TLE_Codigo_Leido' => mb_substr($codigo, 0, 100),
                'ACT_Activo' => $id, 'TLE_Resultado' => $resultado, 'ARE_Area' => $areaLectura, 'TLE_Fecha' => Reloj::aBd(Reloj::ahora()),
                'TLE_Observacion' => $observacion, 'USU_Usuario' => $usuario], 'TLE_Lectura');
            if (null !== $id && \in_array($resultado, ['ENCONTRADO', 'OTRA_UBICACION'], true)) {
                $this->faltantes->resolverPorLectura($id, $tomaId, $usuario);
            }
            $esperados = $this->esperados($t);
            $leidos = array_flip(array_filter([...array_map(static fn (array $l): string => (string) $l['ACT_Activo'], $lecturas), $id]));
            // De otra sede solo se informa el código (no sus datos: fuera del alcance).
            $visible = null !== $activo && 'OTRA_SEDE' !== $resultado;
            $fila = $this->datos->lectura($lectura);

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
                $this->datos->marcarCerrando($id, ['actualizar_ubicacion' => $actualizarUbicacion, 'usuario' => $usuario, 'ip' => $ip]);

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
        foreach ($this->datos->enCierre() as $id) {
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
                        $this->datos->ubicarActivo(Ident::aHex($f['activo']), $f['area']);
                    }
                }
            }
            $resumen['ubicaciones_actualizadas'] = $actualizarUbicacion;
            // Lo que no apareció queda para investigar (Tomas → Faltantes).
            $resumen['faltantes_nuevos'] = $this->faltantes->registrarDeToma($id, $sede, array_map(static fn (array $f): string => Ident::aHex($f['activo']), $resumen['faltantes']));
            $this->datos->cerrar($id, $resumen, Reloj::ahora());
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
            $this->datos->anular($id, Reloj::ahora());
            $this->gobierno->registrarAuditoria('INVENTORY_COUNT_CANCEL', 'INV_TOMA_INVENTARIO', ['toma' => $id], $usuario, $ip, sedeId: (int) $t['SED_Sede']);

            return self::aApi($this->fila($id));
        });
    }
}
