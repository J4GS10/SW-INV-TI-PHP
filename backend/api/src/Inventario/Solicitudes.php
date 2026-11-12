<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Inventario\Puertos\SolicitudesDatos;
use App\Repositorio\GobiernoRepositorio;

/**
 * Solicitudes que esperan la aprobación del Jefe de TI (separación de
 * funciones): toda salida o pérdida de inventario se valida al pedirla y se
 * ejecuta solo cuando otra persona la aprueba (App\Inventario\Aprobaciones).
 *
 * Solo reglas: las solicitudes se guardan y se leen por el puerto SolicitudesDatos.
 */
final readonly class Solicitudes
{
    private const int LARGO_TEXTO = 500;

    public function __construct(
        private SolicitudesDatos $datos,
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
            throw ErrorDominio::invalido('APPROVAL_REQUIRES_REQUESTER');
        }
        $resumen = mb_substr($resumen, 0, self::LARGO_TEXTO);
        $id = $this->datos->crear(['APR_Tipo' => $tipo, 'APR_Estado' => 'PENDIENTE',
            'APR_Resumen' => $resumen, 'APR_Motivo' => null === $motivo || '' === $motivo ? null : mb_substr($motivo, 0, self::LARGO_TEXTO), 'ACT_Activo' => $activo,
            'CON_Consumible' => $consumible, 'SED_Sede' => $sede, 'APR_Solicitante' => $solicitante,
            'APR_Fecha_Solicitud' => Reloj::aBd(Reloj::ahora())], $payload);
        $this->gobierno->registrarAuditoria('APPROVAL_REQUEST', 'INV_APROBACION', ['id' => $id, 'tipo' => $tipo, 'resumen' => $resumen], $solicitante, $ip, sedeId: $sede);

        return $id;
    }

    /** APPROVAL_ALREADY_PENDING si ya hay una solicitud pendiente del mismo tipo y sujeto. */
    public function exigirSinPendiente(string $tipo, ?int $consumible = null): void
    {
        $pendiente = $this->datos->pendienteDe($tipo, $consumible);
        if (null !== $pendiente) {
            throw ErrorDominio::conflicto("APPROVAL_ALREADY_PENDING:{$pendiente}");
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
            $pendiente = $this->datos->bajaPendienteDe($activo);
            if (null !== $pendiente) {
                throw ErrorDominio::conflicto("DECOMMISSION_ALREADY_REQUESTED:{$pendiente}");
            }
            $id = $this->crear('BAJA', Esquemas::bajaJson($baja), $usuario,
                "Baja de {$a['ACT_Codigo_Interno']} ({$a['ACT_Serie_Fabricante']}): {$baja['motivo']} → {$baja['destino']}",
                $baja['observacion'], activo: $activo, sede: null === $a['SED_Sede'] ? null : (int) $a['SED_Sede'], ip: $ip);

            return $this->recienCreada($id);
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
            $c = $this->datos->consumibleParaAjustar($consumible) ?? throw ErrorDominio::noEncontrado('CONSUMABLE_NOT_FOUND');
            $actual = $c['CON_Stock_Actual'];
            if ($real === $actual) {
                throw ErrorDominio::invalido('STOCK_ALREADY_MATCHES');
            }
            $this->exigirSinPendiente('AJUSTE_CONSUMIBLE', $consumible);
            $id = $this->crear('AJUSTE_CONSUMIBLE', ['CON_Stock_Real' => $real, 'MOC_Motivo' => $motivo, 'stock_antes' => $actual], $usuario,
                self::resumenDeAjuste($c['CON_Nombre'], $actual, $real, $c['CON_Unidad']), $motivo,
                consumible: $consumible, sede: $c['SED_Sede'], ip: $ip);

            return $this->recienCreada($id);
        });
    }

    /** Regla pura: «Ajuste de Tóner: 10 → 7 (−3 unidad)». */
    public static function resumenDeAjuste(string $nombre, int $actual, int $real, ?string $unidad): string
    {
        $diferencia = ($real > $actual ? '+' : '−').abs($real - $actual);

        return trim("Ajuste de {$nombre}: {$actual} → {$real} ({$diferencia} ".($unidad ?? '').')');
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
            $this->datos->bloquearTipo($tipo);
            $vista = $this->reclasificacion->vistaPrevia($tipo, $d['SED_Sede']);
            if ([] !== $vista['bloqueados']) {
                throw ErrorDominio::conflicto('RECLASSIFY_ASSETS_IN_USE');
            }
            if (0 === $vista['activos']) {
                throw ErrorDominio::conflicto('RECLASSIFY_NOTHING_TO_CONVERT');
            }
            $this->exigirSinPendiente('RECLASIFICACION');
            $id = $this->crear('RECLASIFICACION', ['tipo_id' => $tipo, ...$d], $usuario,
                "Reclasificar {$vista['activos']} activos «{$vista['tipo']}» como el consumible «{$d['nombre']}»", $d['motivo'], sede: $d['SED_Sede'], ip: $ip);

            return $this->recienCreada($id);
        });
    }

    // ------------------------------------------------------------------
    // Consulta
    // ------------------------------------------------------------------

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
        return $this->datos->porId($id, $bloquear);
    }

    /**
     * La recién creada por el propio usuario (sin depender del alcance).
     *
     * @return array<string, mixed>
     */
    private function recienCreada(int $id): array
    {
        return $this->aApi($this->fila($id) ?? throw ErrorDominio::noEncontrado('APPROVAL_NOT_FOUND'));
    }

    /** @return list<array<string, mixed>> las de las sedes del alcance y las propias */
    public function listar(?string $estado, ?string $activo, int $limite = 200): array
    {
        $filas = $this->datos->visibles($estado, $activo, $limite);
        $nombres = $this->operador->nombres([...array_map(static fn (array $f): string => (string) $f['APR_Solicitante'], $filas),
            ...array_map(static fn (array $f): ?string => null === $f['APR_Aprobador'] ? null : (string) $f['APR_Aprobador'], $filas)]);

        return array_map(fn (array $f): array => $this->aApi($f, $nombres), $filas);
    }

    public function pendientes(): int
    {
        return $this->datos->pendientesVisibles();
    }

    /** Deja la solicitud resuelta: APROBADA, RECHAZADA o ANULADA. */
    public function resolver(int $id, string $estado, ?string $aprobador, ?string $comentario): void
    {
        $this->datos->resolver($id, $estado, $aprobador, Reloj::ahora(), null === $comentario || '' === $comentario ? null : $comentario);
    }
}
