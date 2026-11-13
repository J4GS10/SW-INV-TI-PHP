<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Inventario\Infrastructure\Kardex;
use App\Inventario\Infrastructure\Operador;
use App\Inventario\Infrastructure\ActivosStore;
use App\Inventario\Infrastructure\MovimientosStore;
use App\Correo\Notificador;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;

/**
 * Custodia de un activo (INV_MOVIMIENTO): asignación y préstamo, devolución y transferencia. Cada operación
 * bloquea la custodia vigente, valida la máquina de estados y deja su evento en el kardex, su auditoría y su
 * aviso por correo tras el commit. La salida de una persona y los equipos instalados en un área tienen su
 * propia clase (SalidaDePersona, InstalacionEnArea); las comprobaciones comunes están en ReglasCustodia.
 */
final readonly class Movimientos
{
    public function __construct(
        private ReglasCustodia $custodia,
        private TiposMovimiento $tipos,
        private MovimientosStore $datos,
        private ActivosStore $activos,
        private Kardex $kardex,
        private ReglasClase $reglas,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
        private Notificador $notificador,
        private Operador $operador,
    ) {
    }

    // =====================================================================
    // Consulta
    // =====================================================================

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed> MovimientoResponse
     */
    public static function aApi(array $f): array
    {
        $uuid = static fn (mixed $v): ?string => null === $v ? null : Ident::aTexto((string) $v);

        return [
            'ACT_Activo' => $uuid($f['ACT_Activo']), 'PER_Persona' => $uuid($f['PER_Persona']), 'ARE_Area' => (int) $f['ARE_Area'],
            'TMO_Tipo_Movimiento' => (int) $f['TMO_Tipo_Movimiento'], 'MOV_Observacion' => $f['MOV_Observacion'],
            'MOV_Movimiento' => $uuid($f['MOV_Movimiento']), 'MOV_Fecha_Asignacion' => Reloj::iso(Reloj::desdeBd((string) $f['MOV_Fecha_Asignacion'])),
            'MOV_Fecha_Devolucion' => Reloj::iso(Reloj::desdeBd($f['MOV_Fecha_Devolucion'])), 'MOV_Motivo' => $f['MOV_Motivo'],
            'MMO_Motivo' => null === $f['MMO_Motivo'] ? null : (int) $f['MMO_Motivo'], 'MOV_Motivo_Detalle' => $f['MOV_Motivo_Detalle'],
            'MOV_Fecha_Prevista_Devolucion' => null === $f['MOV_Fecha_Prevista_Devolucion'] ? null : substr((string) $f['MOV_Fecha_Prevista_Devolucion'], 0, 10),
            'MOV_Usuario_Registra' => $uuid($f['MOV_Usuario_Registra']),
            'activo' => null === $f['ACT_Codigo_Interno'] ? null : ['ACT_Codigo_Interno' => $f['ACT_Codigo_Interno'], 'ACT_Hostname' => $f['ACT_Hostname']],
            'persona' => null === $f['PER_Primer_Nombre'] ? null : ['PER_Primer_Nombre' => $f['PER_Primer_Nombre'],
                'PER_Primer_Apellido' => $f['PER_Primer_Apellido'], 'PER_Email_Corporativo' => $f['PER_Email_Corporativo']],
            'tipo_movimiento' => null === $f['TMO_Nombre'] ? null : ['TMO_Nombre' => $f['TMO_Nombre'], 'TMO_Tipo_Movimiento' => (int) $f['TMO_Tipo_Movimiento']],
            'area' => null === $f['ARE_Nombre'] ? null : ['ARE_Nombre' => $f['ARE_Nombre']],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listar(int $saltar, int $limite): array
    {
        return array_map(self::aApi(...), $this->datos->pagina($saltar, $limite));
    }

    /** @return list<array<string, mixed>> */
    public function historial(string $activo): array
    {
        return array_map(self::aApi(...), $this->datos->deActivo($activo));
    }

    /**
     * Activos bajo custodia de una persona.
     *
     * @return list<array<string, mixed>>
     */
    public function asignacionesDe(string $persona): array
    {
        $this->custodia->persona($persona) ?? throw ErrorDominio::noEncontrado('PERSON_NOT_FOUND');

        return array_map(self::aApi(...), $this->datos->vigentesDePersona($persona));
    }

    /** @return array<string, mixed> */
    private function porId(string $mov): array
    {
        $f = $this->datos->porId($mov);

        return self::aApi($f ?: throw ErrorDominio::noEncontrado('MOVEMENT_NOT_FOUND'));
    }

    // =====================================================================
    // Escritura de la custodia
    // =====================================================================

    /** @param array<string, mixed> $d */
    private function insertarMovimiento(array $d, string $usuario): string
    {
        $id = Ident::nuevo();
        $this->datos->insertar(['id' => $id, 'obs' => $d['MOV_Observacion'] ?? null, 'usuario' => $usuario,
            'motivo' => $d['MOV_Motivo'] ?? null, 'mmo' => $d['MMO_Motivo'] ?? null, 'detalle' => $d['MOV_Motivo_Detalle'] ?? null,
            'prevista' => $d['MOV_Fecha_Prevista_Devolucion'] ?? null, 'activo' => $d['ACT_Activo'], 'persona' => $d['PER_Persona'],
            'area' => $d['ARE_Area'], 'tipo' => $d['TMO_Tipo_Movimiento']], Reloj::ahora());

        return $id;
    }

    private function cerrar(string $mov): bool
    {
        return $this->datos->cerrar($mov, Reloj::ahora());
    }

    // =====================================================================
    // Asignación y préstamo
    // =====================================================================

    /**
     * Abre una custodia (asignación o préstamo). El bloqueo de la custodia
     * vigente y un índice único impiden dos custodias abiertas del mismo activo.
     *
     * @param array<string, mixed> $d MovimientoCreate (ids en hex)
     *
     * @return array<string, mixed> MovimientoResponse
     */
    public function asignar(array $d, string $usuario, ?string $ip): array
    {
        // Si choca el índice único es que otra custodia del mismo activo se abrió a la vez.
        return $this->transaccion->ejecutarUnica(function () use ($d, $usuario, $ip): array {
            $activo = $this->custodia->activo($d['ACT_Activo']) ?? throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
            // Una impresora o un switch se instalan en un área, no se asignan a una persona.
            $this->reglas->exigirAsignableAPersona($activo);
            $tipo = $this->tipos->tipoCanonico((int) $d['TMO_Tipo_Movimiento']);
            // Este endpoint solo ABRE custodias; devolución, transferencia, ingreso y baja tienen su operación.
            if (!\in_array($tipo, [TiposMovimiento::ASIGNACION, TiposMovimiento::PRESTAMO], true)) {
                throw ErrorDominio::invalido('MOVEMENT_TYPE_REQUIRES_SPECIFIC_OPERATION');
            }
            $estado = $this->custodia->estadoDe($activo) ?? throw ErrorDominio::interno('UNKNOWN_CURRENT_STATE');
            EstadosActivo::validarTransicion($estado, EstadosActivo::ASIGNADO);
            $persona = $this->custodia->persona($d['PER_Persona']) ?? throw ErrorDominio::noEncontrado('PERSON_NOT_FOUND');
            ReglasCustodia::exigirPersonaActiva($persona);
            $this->custodia->exigirOperable($activo);
            if (!$this->custodia->existeArea((int) $d['ARE_Area'])) {
                throw ErrorDominio::noEncontrado('AREA_NOT_FOUND');
            }
            $sede = $this->custodia->sedeDeDestino((int) $d['ARE_Area']);
            $vigente = $this->custodia->vigente($d['ACT_Activo']);
            $hoy = Reloj::ahora()->format('Y-m-d');
            if (TiposMovimiento::PRESTAMO === $tipo) {
                if (null === ($d['MOV_Fecha_Prevista_Devolucion'] ?? null)) {
                    throw ErrorDominio::invalido('LOAN_RETURN_DATE_REQUIRED');
                }
                if ($d['MOV_Fecha_Prevista_Devolucion'] < $hoy) {
                    throw ErrorDominio::invalido('LOAN_RETURN_DATE_IN_THE_PAST');
                }
            } else {
                $d['MOV_Fecha_Prevista_Devolucion'] = null;
            }
            if (null !== $vigente) {
                throw ErrorDominio::conflicto('ASSET_ALREADY_ASSIGNED_USE_TRANSFER');
            }
            if (!\in_array($estado, EstadosActivo::DE_ALMACEN, true)) {
                throw ErrorDominio::invalido('ASSET_NOT_AVAILABLE_FOR_ASSIGNMENT');
            }
            $d = [...$d, ...$this->custodia->conMotivo($d, TiposMovimiento::PRESTAMO === $tipo ? 'PRESTAMO' : 'ASIGNACION')];
            $this->custodia->ubicarEnSede($activo, $persona, $sede, $usuario, $ip);
            $mov = $this->insertarMovimiento($d, $usuario);
            $this->activos->actualizar($d['ACT_Activo'], ['ARE_Area_Ubicacion' => $d['ARE_Area']]);
            $this->kardex->registrar(TiposMovimiento::PRESTAMO === $tipo ? 'PRESTAMO' : 'ASIGNACION', activo: $activo, usuario: $usuario,
                destino: ['persona' => $d['PER_Persona'], 'area' => (int) $d['ARE_Area'], 'sede' => $sede], documento: ['MOVIMIENTO', Ident::aTexto($mov)],
                motivo: ($d['MOV_Motivo'] ?? null) ?: ($d['MOV_Observacion'] ?? null), detalle: ['devolucion_prevista' => $d['MOV_Fecha_Prevista_Devolucion']]);
            $asignado = $this->custodia->idEstado(EstadosActivo::ASIGNADO);
            if ($asignado !== (int) $activo['EOP_Estado_Operativo']) {
                $this->activos->actualizar($d['ACT_Activo'], ['EOP_Estado_Operativo' => $asignado]);
            }
            // Cambio opcional de hostname al entregar el equipo.
            if (\array_key_exists('ACT_Hostname', $d) && null !== $d['ACT_Hostname'] && (($d['ACT_Hostname'] ?: null) !== ($activo['ACT_Hostname'] ?: null))) {
                $this->activos->actualizar($d['ACT_Activo'], ['ACT_Hostname' => $d['ACT_Hostname'] ?: null]);
            }
            $this->gobierno->registrarAuditoria('ASSIGN', 'INV_MOVIMIENTO', ['activo' => Ident::aTexto($d['ACT_Activo']), 'persona' => Ident::aTexto($d['PER_Persona']),
                'area' => (int) $d['ARE_Area'], 'sede' => $sede, 'tipo' => $tipo], $usuario, $ip, sedeId: $sede);
            $resultado = $this->porId($mov);
            $this->avisarAsignacion($d['ACT_Activo'], $persona, $resultado, $usuario);

            return $resultado;
        }, ErrorDominio::conflicto('ASSET_ALREADY_HAS_OPEN_MOVEMENT'));
    }

    /**
     * @param array<string, mixed> $persona
     * @param array<string, mixed> $mov
     */
    private function avisarAsignacion(string $activo, array $persona, array $mov, string $usuario): void
    {
        $a = $this->datos->fichaDeActivo($activo) ?: [];
        $op = $this->operador->de($usuario);
        $correo = $persona['PER_Email_Corporativo'] ?? null;
        $contexto = ['codigo' => $a['ACT_Codigo_Interno'] ?? '', 'serie' => $a['ACT_Serie_Fabricante'] ?? '', 'hostname' => $a['ACT_Hostname'] ?? '',
            'tipo' => $a['TAC_Nombre'] ?? '', 'marca' => $a['MAR_Nombre'] ?? '', 'modelo' => $a['MOD_Nombre'] ?? '', 'persona_nombre' => ReglasCustodia::nombre($persona),
            'fecha' => $mov['MOV_Fecha_Asignacion'] ?? '', 'area' => $mov['area']['ARE_Nombre'] ?? '', 'observacion' => $mov['MOV_Observacion'] ?? ''];
        $this->transaccion->alConfirmar(fn () => $this->notificador->enviar('asignacion', $contexto, null === $correo || '' === $correo ? [] : [$correo],
            responderA: $op['correo'], operador: $op['nombre'], rolOperador: $op['rol']));
    }

    // =====================================================================
    // Devolución
    // =====================================================================

    /**
     * Cierra la custodia vigente. Si el equipo está en reparación, sigue así
     * hasta que se cierre el ticket.
     *
     * @param array<string, mixed> $d DevolucionCreate (hex)
     *
     * @return array<string, mixed>
     */
    public function devolver(array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $usuario, $ip): array {
            $vigente = $this->custodia->vigente($d['ACT_Activo']) ?? throw ErrorDominio::invalido('ASSET_IS_NOT_ASSIGNED');
            $activo = $this->custodia->activo($d['ACT_Activo']) ?? throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
            $estado = $this->custodia->estadoDe($activo);
            $reparacion = $this->custodia->ticketAbierto($d['ACT_Activo']);
            if (!$reparacion) {
                EstadosActivo::validarTransicion($estado ?? throw ErrorDominio::interno('UNKNOWN_CURRENT_STATE'), EstadosActivo::BODEGA);
            }
            $destino = $d['ARE_Area_Destino'] ?? null;
            if (null !== $destino) {
                if (!$this->custodia->existeArea((int) $destino)) {
                    throw ErrorDominio::noEncontrado('AREA_NOT_FOUND');
                }
                $this->custodia->sedeDeDestino((int) $destino);
            }
            $motivo = $this->custodia->conMotivo($d, 'DEVOLUCION');
            if (!$this->cerrar((string) $vigente['MOV_Movimiento'])) {
                throw ErrorDominio::conflicto('MOVEMENT_ALREADY_CLOSED');
            }
            $this->datos->anotarMotivo($vigente['MOV_Movimiento'], $motivo['MOV_Motivo'], $motivo['MMO_Motivo'], $motivo['MOV_Motivo_Detalle']);
            $bodega = $this->custodia->idEstado(EstadosActivo::BODEGA);
            $this->activos->actualizar($d['ACT_Activo'], ['ARE_Area_Ubicacion' => $destino, ...($reparacion ? [] : ['EOP_Estado_Operativo' => $bodega])]);
            $sede = null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'];
            $this->kardex->registrar('DEVOLUCION', activo: $activo, usuario: $usuario,
                origen: ['persona' => (string) $vigente['PER_Persona'], 'area' => (int) $vigente['ARE_Area'], 'sede' => $sede],
                destino: ['area' => null === $destino ? null : (int) $destino, 'sede' => $sede], documento: ['MOVIMIENTO', Ident::aTexto((string) $vigente['MOV_Movimiento'])],
                motivo: $motivo['MOV_Motivo'], detalle: ['condicion' => $d['condicion'] ?? null, 'en_reparacion' => $reparacion]);
            $this->gobierno->registrarAuditoria('RETURN', 'INV_MOVIMIENTO', ['activo' => Ident::aTexto($d['ACT_Activo']), 'estado_nuevo' => $bodega], $usuario, $ip, sedeId: $sede);
            $persona = $this->datos->contactoDePersona($vigente['PER_Persona']) ?: null;
            $op = $this->operador->de($usuario);
            $contexto = ['codigo' => $activo['ACT_Codigo_Interno'], 'persona_nombre' => ReglasCustodia::nombre($persona), 'fecha' => Reloj::iso(Reloj::ahora())];
            $para = null === $persona || null === $persona['PER_Email_Corporativo'] ? [] : [$persona['PER_Email_Corporativo']];
            $this->transaccion->alConfirmar(fn () => $this->notificador->enviar('devolucion', $contexto, $para, responderA: $op['correo'],
                operador: $op['nombre'], rolOperador: $op['rol']));
            // Diálogo: el sistema propone el siguiente paso lógico.
            $siguiente = ['DESCARGAR_HOJA_DESCARGO'];
            if ('CON_DANO' === ($d['condicion'] ?? null) && !$reparacion) {
                array_unshift($siguiente, 'ABRIR_TICKET_MANTENIMIENTO');
            }

            return ['status' => 'success', 'message' => 'ASSET_RETURNED_SUCCESSFULLY', 'MOV_Movimiento' => Ident::aTexto((string) $vigente['MOV_Movimiento']),
                'estado' => $reparacion ? 'En Reparación' : 'En Bodega', 'siguientes_pasos' => $siguiente];
        });
    }

    // =====================================================================
    // Transferencia
    // =====================================================================

    /**
     * @param array<string, mixed> $d TransferenciaCreate (hex)
     *
     * @return array<string, mixed>
     */
    public function transferir(array $d, string $usuario, ?string $ip): array
    {
        // Si choca el índice único es que otra custodia del mismo activo se abrió a la vez.
        return $this->transaccion->ejecutarUnica(function () use ($d, $usuario, $ip): array {
            $activo = $this->custodia->activo($d['ACT_Activo']) ?? throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
            $this->reglas->exigirAsignableAPersona($activo);
            EstadosActivo::validarTransicion($this->custodia->estadoDe($activo) ?? throw ErrorDominio::interno('UNKNOWN_CURRENT_STATE'), EstadosActivo::ASIGNADO);
            $vigente = $this->custodia->vigente($d['ACT_Activo']) ?? throw ErrorDominio::invalido('ASSET_IS_NOT_ASSIGNED_CANNOT_TRANSFER');
            $persona = $this->custodia->persona($d['PER_Persona_Destino']) ?? throw ErrorDominio::noEncontrado('DESTINATION_PERSON_NOT_FOUND');
            ReglasCustodia::exigirPersonaActiva($persona);
            $this->custodia->exigirOperable($activo);
            if ((string) $vigente['PER_Persona'] === $d['PER_Persona_Destino'] && (int) $vigente['ARE_Area'] === (int) $d['ARE_Area_Destino']) {
                throw ErrorDominio::invalido('TRANSFER_TO_SAME_CUSTODIAN');
            }
            if (!$this->custodia->existeArea((int) $d['ARE_Area_Destino'])) {
                throw ErrorDominio::noEncontrado('DESTINATION_AREA_NOT_FOUND');
            }
            $sede = $this->custodia->sedeDeDestino((int) $d['ARE_Area_Destino']);
            // La nueva custodia se registra como "Transferencia" (o "Asignación" si el catálogo no lo tiene).
            $tipo = $this->tipos->idTipo(TiposMovimiento::TRANSFERENCIA) ?? $this->tipos->idTipo(TiposMovimiento::ASIGNACION)
                ?? $this->datos->primerTipoDeAsignacion()
                ?? throw ErrorDominio::interno('SYSTEM_CONFIG_ERROR_MISSING_ASSIGNMENT_TYPE');
            $motivo = $this->custodia->conMotivo($d, 'TRANSFERENCIA');
            $this->cerrar((string) $vigente['MOV_Movimiento']);
            $sedeOrigen = null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'];
            $this->custodia->ubicarEnSede($activo, $persona, $sede, $usuario, $ip);
            $mov = $this->insertarMovimiento(['ACT_Activo' => $d['ACT_Activo'], 'PER_Persona' => $d['PER_Persona_Destino'], 'ARE_Area' => (int) $d['ARE_Area_Destino'],
                'TMO_Tipo_Movimiento' => $tipo, 'MOV_Observacion' => ($d['MOV_Observacion'] ?? null) ?: 'Transferencia de custodia', ...$motivo], $usuario);
            $this->activos->actualizar($d['ACT_Activo'], ['ARE_Area_Ubicacion' => (int) $d['ARE_Area_Destino'], 'EOP_Estado_Operativo' => $this->custodia->idEstado(EstadosActivo::ASIGNADO)]);
            $this->kardex->registrar('TRANSFERENCIA', activo: $activo, usuario: $usuario,
                origen: ['persona' => (string) $vigente['PER_Persona'], 'area' => (int) $vigente['ARE_Area'], 'sede' => $sedeOrigen],
                destino: ['persona' => $d['PER_Persona_Destino'], 'area' => (int) $d['ARE_Area_Destino'], 'sede' => $sede],
                documento: ['MOVIMIENTO', Ident::aTexto($mov)], motivo: ($motivo['MOV_Motivo'] ?? null) ?: ($d['MOV_Observacion'] ?? null));
            $this->gobierno->registrarAuditoria('TRANSFER', 'INV_MOVIMIENTO', ['activo' => Ident::aTexto($d['ACT_Activo']), 'de' => Ident::aTexto((string) $vigente['PER_Persona']),
                'a' => Ident::aTexto($d['PER_Persona_Destino']), 'sede_origen' => $sedeOrigen, 'sede_destino' => $sede], $usuario, $ip, sedeId: $sede);
            $origen = $this->datos->contactoDePersona($vigente['PER_Persona']) ?: null;
            $op = $this->operador->de($usuario);
            $para = array_values(array_filter([$origen['PER_Email_Corporativo'] ?? null, $persona['PER_Email_Corporativo'] ?? null]));
            $contexto = ['codigo' => $activo['ACT_Codigo_Interno'], 'origen_nombre' => ReglasCustodia::nombre($origen), 'destino_nombre' => ReglasCustodia::nombre($persona),
                'fecha' => Reloj::iso(Reloj::ahora())];
            $this->transaccion->alConfirmar(fn () => $this->notificador->enviar('transferencia', $contexto, $para, responderA: $op['correo'],
                operador: $op['nombre'], rolOperador: $op['rol']));

            return $this->porId($mov);
        }, ErrorDominio::conflicto('ASSET_ALREADY_HAS_OPEN_MOVEMENT'));
    }
}
