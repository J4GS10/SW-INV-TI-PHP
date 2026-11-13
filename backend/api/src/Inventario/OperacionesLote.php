<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use App\Inventario\Infrastructure\DatosActivos;
use App\Inventario\Infrastructure\DatosMovimientos;

/**
 * Varias operaciones de custodia en UNA transacción: si un equipo no se puede
 * entregar o devolver, no se aplica ninguno y el error dice cuál lo impidió
 * (BATCH_ITEM_FAILED:<código>:<motivo>). Cada equipo deja su movimiento, su
 * kardex y su auditoría igual que si se hiciera de a uno.
 */
final readonly class OperacionesLote
{
    /** Minutos durante los que se puede deshacer una asignación o una entrega. */
    public const int VENTANA_DESHACER_MIN = 30;

    public function __construct(
        private DatosActivos $datosActivos,
        private DatosMovimientos $datos,
        private Activos $activos,
        private Movimientos $movimientos,
        private InstalacionesDeSoftware $software,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
    ) {
    }

    /**
     * @param list<string> $ids hex
     *
     * @return array<string, string> hex => código
     */
    private function codigos(array $ids): array
    {
        $codigos = $this->datosActivos->codigosDe($ids);
        if (\count($codigos) !== \count($ids)) {
            throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
        }

        return $codigos;
    }

    private static function fallo(ErrorDominio $e, string $codigo): ErrorDominio
    {
        return new ErrorDominio($e->motivo, "BATCH_ITEM_FAILED:{$codigo}:".($e->cuerpo['error'] ?? $e->detalle));
    }

    private function tipoAsignacion(): int
    {
        $t = $this->datos->tipoDeMovimiento(Movimientos::ASIGNACION);

        return false === $t ? throw ErrorDominio::interno('SYSTEM_CONFIG_ERROR_MISSING_MOVEMENT_TYPE') : (int) $t;
    }

    /**
     * Entrega de puesto: asigna (o transfiere, si ya tienen custodio) varios
     * equipos a una persona, y sus licencias por persona.
     *
     * @param array<string, mixed> $d EntregaLoteCreate (hex)
     *
     * @return array<string, mixed>
     */
    public function entrega(array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $usuario, $ip): array {
            $this->activos->bloquearEnOrden($d['activos']);
            $codigos = $this->codigos($d['activos']);
            $custodios = [];
            foreach ($d['activos'] as $a) {
                $c = $this->datos->custodioActual($a);
                $custodios[$a] = false === $c ? null : (string) $c;
            }
            $tipo = $this->tipoAsignacion();
            // El motivo se valida como asignación; las transferencias lo llevan solo si aplica.
            $d = [...$d, ...$this->movimientos->conMotivo($d, 'ASIGNACION')];
            $motivoTransferencia = $this->movimientos->aplica($d['MMO_Motivo'], 'TRANSFERENCIA') ? $d['MMO_Motivo'] : null;
            $observacion = implode(' · ', array_filter([null === $d['ticket'] ? null : "Ticket {$d['ticket']}", $d['MOV_Observacion']])) ?: null;
            $items = [];
            foreach ($d['activos'] as $a) {
                $custodio = $custodios[$a];
                try {
                    if ($custodio === $d['PER_Persona']) {
                        throw ErrorDominio::invalido('ASSET_ALREADY_WITH_PERSON');
                    }
                    $mov = null !== $custodio
                        ? $this->movimientos->transferir(['ACT_Activo' => $a, 'PER_Persona_Destino' => $d['PER_Persona'], 'ARE_Area_Destino' => $d['ARE_Area'],
                            'MOV_Observacion' => $observacion, 'MOV_Motivo' => $d['MOV_Motivo'], 'MMO_Motivo' => $motivoTransferencia,
                            'MOV_Motivo_Detalle' => null !== $motivoTransferencia ? $d['MOV_Motivo_Detalle'] : null], $usuario, $ip)
                        : $this->movimientos->asignar(['ACT_Activo' => $a, 'PER_Persona' => $d['PER_Persona'], 'ARE_Area' => $d['ARE_Area'],
                            'TMO_Tipo_Movimiento' => $tipo, 'MOV_Observacion' => $observacion, 'MOV_Motivo' => $d['MOV_Motivo'], 'MMO_Motivo' => $d['MMO_Motivo'],
                            'MOV_Motivo_Detalle' => $d['MOV_Motivo_Detalle']], $usuario, $ip);
                } catch (ErrorDominio $e) {
                    // Toda la entrega se revierte: se informa qué equipo la detuvo.
                    throw self::fallo($e, $codigos[$a]);
                }
                $items[] = ['ACT_Activo' => Ident::aTexto($a), 'codigo' => $codigos[$a], 'MOV_Movimiento' => $mov['MOV_Movimiento'],
                    'operacion' => null !== $custodio ? 'TRANSFERENCIA' : 'ASIGNACION'];
            }
            $licencias = [];
            foreach ($d['licencias'] as $lic) {
                try {
                    $ins = $this->software->instalar($lic, null, $d['PER_Persona'], null, Reloj::ahora()->format('Y-m-d'), $usuario, $ip);
                } catch (ErrorDominio $e) {
                    throw self::fallo($e, "LIC-{$lic}");
                }
                $licencias[] = ['LIC_Licencia' => $lic, 'INS_Instalacion' => $ins['INS_Instalacion'], 'LCL_Licencia_Clave' => $ins['LCL_Licencia_Clave']];
            }
            $this->gobierno->registrarAuditoria('ASSIGN_BATCH', 'INV_MOVIMIENTO', ['persona' => Ident::aTexto($d['PER_Persona']), 'area' => $d['ARE_Area'],
                'ticket' => $d['ticket'], 'activos' => array_column($items, 'codigo'), 'licencias' => $d['licencias']], $usuario, $ip);

            return ['total' => \count($items), 'movimientos' => array_column($items, 'MOV_Movimiento'), 'items' => $items, 'licencias' => $licencias];
        });
    }

    /**
     * Devuelve varios equipos a bodega (todo o nada).
     *
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public function devolucion(array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $usuario, $ip): array {
            $this->activos->bloquearEnOrden($d['activos']);
            $codigos = $this->codigos($d['activos']);
            $d = [...$d, ...$this->movimientos->conMotivo($d, 'DEVOLUCION')];
            $items = [];
            foreach ($d['activos'] as $a) {
                try {
                    $r = $this->movimientos->devolver(['ACT_Activo' => $a, 'MOV_Motivo' => $d['MOV_Motivo'], 'MMO_Motivo' => $d['MMO_Motivo'],
                        'MOV_Motivo_Detalle' => $d['MOV_Motivo_Detalle'], 'ARE_Area_Destino' => $d['ARE_Area_Destino'], 'condicion' => $d['condicion']], $usuario, $ip);
                } catch (ErrorDominio $e) {
                    throw self::fallo($e, $codigos[$a]);
                }
                $items[] = ['ACT_Activo' => Ident::aTexto($a), 'codigo' => $codigos[$a], 'MOV_Movimiento' => $r['MOV_Movimiento'], 'estado' => $r['estado']];
            }
            $this->gobierno->registrarAuditoria('RETURN_BATCH', 'INV_MOVIMIENTO', ['activos' => array_column($items, 'codigo'), 'motivo' => $d['MOV_Motivo']], $usuario, $ip);

            return ['total' => \count($items), 'movimientos' => array_column($items, 'MOV_Movimiento'), 'items' => $items];
        });
    }

    /**
     * Deshacer una asignación, transferencia o entrega reciente: nada se borra,
     * se registra el movimiento inverso y se liberan las licencias de la entrega.
     * Solo dentro de la ventana, sobre la custodia vigente y por quien la
     * registró (o la administración de TI).
     *
     * @param list<string> $movimientos hex
     * @param list<int>    $instalaciones
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function revertir(array $movimientos, array $instalaciones, string $usuario, string $rol, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($movimientos, $instalaciones, $usuario, $rol, $ip): array {
            $admin = \in_array($rol, Roles::ADMIN, true);
            $revertido = $this->datos->motivoPorCodigo('REVERTIDO');
            $revertido = false === $revertido ? null : (int) $revertido;
            $ahora = Reloj::ahora();
            $resultado = [];
            foreach ($movimientos as $id) {
                $mov = $this->datos->movimiento($id) ?: throw ErrorDominio::noEncontrado('MOVEMENT_NOT_FOUND');
                $codigo = (string) $this->datosActivos->codigoDe($mov['ACT_Activo']);
                $asignado = Reloj::desdeBd((string) $mov['MOV_Fecha_Asignacion']);
                try {
                    if (null !== $mov['MOV_Fecha_Devolucion']) {
                        throw ErrorDominio::conflicto('MOVEMENT_UNDO_NOT_CURRENT');
                    }
                    if ($ahora->getTimestamp() - $asignado->getTimestamp() > self::VENTANA_DESHACER_MIN * 60) {
                        throw ErrorDominio::conflicto('MOVEMENT_UNDO_WINDOW_EXPIRED');
                    }
                    if (!$admin && (string) $mov['MOV_Usuario_Registra'] !== $usuario) {
                        throw ErrorDominio::prohibido('MOVEMENT_UNDO_NOT_OWNER');
                    }
                    // ¿Venía de otra persona? La custodia anterior se cerró al crear esta.
                    $anterior = $this->datos->custodiaAnterior($mov['ACT_Activo'], $id, $asignado->modify('-5 seconds'));
                    if (false !== $anterior) {
                        $this->movimientos->transferir(['ACT_Activo' => (string) $mov['ACT_Activo'], 'PER_Persona_Destino' => (string) $anterior['PER_Persona'],
                            'ARE_Area_Destino' => (int) $anterior['ARE_Area'], 'MOV_Motivo' => 'Revertido: se deshizo la transferencia'], $usuario, $ip);
                        $operacion = 'TRANSFERENCIA_REVERTIDA';
                    } else {
                        $this->movimientos->devolver(['ACT_Activo' => (string) $mov['ACT_Activo'], 'MMO_Motivo' => $revertido,
                            'MOV_Motivo' => null === $revertido ? 'Revertido' : null], $usuario, $ip);
                        $operacion = 'ASIGNACION_REVERTIDA';
                    }
                } catch (ErrorDominio $e) {
                    throw self::fallo($e, $codigo);
                }
                $resultado[] = ['codigo' => $codigo, 'operacion' => $operacion];
            }
            foreach ($instalaciones as $ins) {
                $i = $this->datos->instalacion($ins);
                if (false === $i || 1 !== (int) $i['INS_Estado']) {
                    continue;
                }
                $this->software->desinstalar((int) $i['LIC_Licencia'], null === $i['ACT_Activo'] ? null : (string) $i['ACT_Activo'],
                    null === $i['PER_Persona'] ? null : (string) $i['PER_Persona'], 'Revertido: se deshizo la entrega', $usuario, $ip);
                $resultado[] = ['licencia' => (int) $i['LIC_Licencia'], 'operacion' => 'LICENCIA_LIBERADA'];
            }
            $this->gobierno->registrarAuditoria('UNDO_MOVEMENTS', 'INV_MOVIMIENTO', ['movimientos' => array_map(static fn (string $m): string => Ident::aTexto($m), $movimientos),
                'instalaciones' => $instalaciones, 'resultado' => $resultado], $usuario, $ip);

            return ['total' => \count($resultado), 'items' => $resultado];
        });
    }
}
