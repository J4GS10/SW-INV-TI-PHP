<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Inventario\Infrastructure\Kardex;
use App\Inventario\Infrastructure\Operador;
use App\Inventario\Infrastructure\ActivosStore;
use App\Inventario\Infrastructure\DatosActivos;
use App\Inventario\Infrastructure\Instalaciones;
use App\Inventario\Infrastructure\MantenimientosStore;
use App\Inventario\Infrastructure\MovimientosStore;
use App\Catalogo\ClasesActivo;
use App\Correo\Notificador;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Dominio\ErrorDominio;
use App\Organizacion\Identidades;
use App\Organizacion\Infrastructure\CuentasStore;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;

/**
 * Trazabilidad de la custodia (INV_MOVIMIENTO): asignación y préstamo,
 * devolución, transferencia, salida de una persona (offboarding) y equipos
 * compartidos instalados en un área. Cada operación bloquea la custodia
 * vigente, valida la máquina de estados, deja su evento en el kardex, su
 * auditoría y su aviso por correo tras el commit.
 */
final readonly class Movimientos
{
    public const string ASIGNACION = 'Asignación';
    public const string DEVOLUCION = 'Devolución';
    public const string TRANSFERENCIA = 'Transferencia';
    public const string PRESTAMO = 'Préstamo';
    public const string INGRESO = 'Ingreso';
    public const string BAJA = 'Baja por Retiro';
    private const array TIPOS = [self::INGRESO, self::ASIGNACION, self::DEVOLUCION, self::TRANSFERENCIA, self::PRESTAMO, self::BAJA];

    public function __construct(
        private MovimientosStore $datos,
        private ActivosStore $activos,
        private DatosActivos $datosDeActivos,
        private MantenimientosStore $mantenimientos,
        private CuentasStore $cuentas,
        private Alcance $alcance,
        private Kardex $kardex,
        private EstadosActivo $estados,
        private ReglasClase $reglas,
        private Instalaciones $instalaciones,
        private Credenciales $credenciales,
        private GobiernoRepositorio $gobierno,
        private Identidades $identidades,
        private Transaccion $transaccion,
        private Notificador $notificador,
        private Operador $operador,
        private \App\Db\LogicalDelete $borrado,
    ) {
    }

    // =====================================================================
    // Tipos de movimiento
    // =====================================================================

    /** @return list<array<string, mixed>> */
    public function tipos(): array
    {
        return array_map(static fn (array $t): array => ['TMO_Nombre' => $t['TMO_Nombre'], 'TMO_Tipo_Movimiento' => (int) $t['TMO_Tipo_Movimiento']],
            $this->datos->tipos());
    }

    /** @return array<string, mixed> */
    public function tipo(int $id): array
    {
        $n = $this->datos->nombreDeTipo($id);

        return false === $n ? throw ErrorDominio::noEncontrado('MOVEMENT_TYPE_NOT_FOUND') : ['TMO_Nombre' => $n, 'TMO_Tipo_Movimiento' => $id];
    }

    /** @return array<string, mixed> */
    public function crearTipo(string $nombre, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($nombre, $usuario, $ip): array {
            if (null !== $this->datos->idDeTipo($nombre)) {
                throw ErrorDominio::invalido('MOVEMENT_TYPE_ALREADY_EXISTS');
            }
            $id = $this->datos->crearTipo($nombre);
            $this->gobierno->registrarAuditoria('CREATE', 'INV_TIPO_MOVIMIENTO', ['nombre' => $nombre], $usuario, $ip);

            return $this->tipo($id);
        });
    }

    /**
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    public function cambiarTipo(int $id, array $cambios, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $cambios, $usuario, $ip): array {
            $this->tipo($id);
            if (\array_key_exists('TMO_Nombre', $cambios)) {
                $this->datos->renombrarTipo($id, $cambios['TMO_Nombre']);
            }
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_TIPO_MOVIMIENTO', ['id' => $id, 'cambios' => (object) $cambios], $usuario, $ip);

            return $this->tipo($id);
        });
    }

    public function eliminarTipo(int $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $t = $this->tipo($id);
            if ($this->alcance->elevado(fn (): bool => $this->datos->tipoEnUso($id))) {
                throw ErrorDominio::conflicto('CANNOT_DELETE_TYPE_HAS_MOVEMENTS');
            }
            $this->borrado->archive('INV_TIPO_MOVIMIENTO', 'TMO_Tipo_Movimiento', $id);
            $this->gobierno->registrarAuditoria('DELETE', 'INV_TIPO_MOVIMIENTO', ['id' => $id, 'nombre' => $t['TMO_Nombre']], $usuario, $ip);
        });
    }

    /** Tipo canónico del catálogo (exacto o contenido en el nombre, sin tildes). */
    private function tipoCanonico(int $id): ?string
    {
        $nombre = $this->datos->nombreDeTipo($id);
        if (false === $nombre) {
            return null;
        }
        foreach (self::TIPOS as $t) {
            if ($t === $nombre) {
                return $t;
            }
        }
        $normal = ClasesActivo::normalizar((string) $nombre);
        foreach (self::TIPOS as $t) {
            if (str_contains($normal, ClasesActivo::normalizar($t))) {
                return $t;
            }
        }

        return null;
    }

    private function idTipo(string $nombre): ?int
    {
        return $this->datos->idDeTipo($nombre);
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
        $this->persona($persona) ?? throw ErrorDominio::noEncontrado('PERSON_NOT_FOUND');

        return array_map(self::aApi(...), $this->datos->vigentesDePersona($persona));
    }

    /** @return array<string, mixed> */
    private function porId(string $mov): array
    {
        $f = $this->datos->porId($mov);

        return self::aApi($f ?: throw ErrorDominio::noEncontrado('MOVEMENT_NOT_FOUND'));
    }

    // =====================================================================
    // Reglas comunes
    // =====================================================================

    /** @return array<string, mixed>|null fila del activo (bloqueada) visible para el usuario */
    private function activo(string $id): ?array
    {
        if (false === $this->activos->visible($id)) {
            return null;
        }
        $this->activos->bloquear($id);
        $activo = $this->activos->porId($id) ?: null;
        // Sin serie ni datos completos, el activo no puede ir a nadie.
        if (null !== $activo && 1 === (int) $activo['ACT_Pending_Completion']) {
            throw ErrorDominio::conflicto('ASSET_PENDING_COMPLETION');
        }

        return $activo;
    }

    /** @return array<string, mixed>|null */
    private function persona(string $id): ?array
    {
        $f = $this->datos->personaVisible($id);

        return false === $f ? null : $f;
    }

    /** @return array<string, mixed>|null */
    private function vigente(string $activo, bool $bloquear = true): ?array
    {
        $m = $this->datos->vigenteDe($activo, $bloquear);

        return false === $m ? null : $m;
    }

    /** @param array<string, mixed> $activo */
    private function estadoDe(array $activo): ?string
    {
        return EstadosActivo::canonicoFlexible($this->estados->nombre((int) $activo['EOP_Estado_Operativo']));
    }

    private function idEstado(string $estado): int
    {
        return $this->estados->idDe($estado) ?? throw ErrorDominio::interno('SYSTEM_CONFIG_ERROR_MISSING_OPERATIONAL_STATE');
    }

    /** @param array<string, mixed>|null $persona No se entregan equipos a personas inactivas. */
    private static function exigirPersonaActiva(?array $persona): void
    {
        if (null !== $persona && 1 !== (int) $persona['PER_Estado']) {
            throw ErrorDominio::invalido('PERSON_INACTIVE');
        }
    }

    private function ticketAbierto(string $activo): bool
    {
        return $this->mantenimientos->tieneTicketAbierto($activo);
    }

    /**
     * Un equipo en reparación, instalado dentro de otro o con una baja pendiente no se entrega.
     *
     * @param array<string, mixed> $activo
     */
    private function exigirOperable(array $activo): void
    {
        $id = (string) $activo['ACT_Activo'];
        if ($this->ticketAbierto($id)) {
            throw ErrorDominio::conflicto('ASSET_IN_MAINTENANCE');
        }
        if (null !== $activo['ACT_Activo_Padre']) {
            throw ErrorDominio::conflicto('ASSET_INSTALLED_AS_COMPONENT');
        }
        if ($this->datos->tieneBajaPendiente($id)) {
            throw ErrorDominio::conflicto('ASSET_HAS_PENDING_DECOMMISSION');
        }
    }

    /** Sede del área de destino, validada contra el alcance. */
    private function sedeDeDestino(int $area): int
    {
        $sede = $this->datosDeActivos->sedeDeArea($area);
        if (false === $sede || null === $sede) {
            throw ErrorDominio::invalido('AREA_WITHOUT_SEDE');
        }

        return (int) $this->alcance->exigirSede((int) $sede);
    }

    private function existeArea(int $area): bool
    {
        return $this->datosDeActivos->existeArea($area);
    }

    /**
     * La sede del activo sigue al área de su último movimiento; una persona sin
     * sede recibe la del área. El cambio se audita en la sede de ORIGEN.
     *
     * @param array<string, mixed>      $activo
     * @param array<string, mixed>|null $persona
     */
    private function ubicarEnSede(array $activo, ?array $persona, ?int $sede, string $usuario, ?string $ip): void
    {
        if (null === $sede) {
            return;
        }
        $actual = null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'];
        if (null !== $actual && $actual !== $sede) {
            $this->gobierno->registrarAuditoria('ASSET_SEDE_CHANGE', 'INV_ACTIVO', ['activo' => Ident::aTexto((string) $activo['ACT_Activo']),
                'codigo' => $activo['ACT_Codigo_Interno'], 'sede_origen' => $actual, 'sede_destino' => $sede], $usuario, $ip, sedeId: $actual);
            $this->kardex->registrar('TRASLADO_SEDE', activo: $activo, usuario: $usuario, origen: ['sede' => $actual], destino: ['sede' => $sede],
                motivo: 'Cambio de sede por asignación o transferencia');
        }
        if ($actual !== $sede) {
            $this->activos->actualizar((string) $activo['ACT_Activo'], ['SED_Sede' => $sede]);
        }
        if (null !== $persona && null === $persona['SED_Sede']) {
            $this->datos->fijarSedeDePersonaSiFalta($persona['PER_Persona'], $sede);
        }
    }

    /**
     * Motivo del catálogo (INV_MOTIVO_MOVIMIENTO): debe estar activo y aplicar al
     * tipo; compone el texto legible «Motivo: detalle». Sin motivo del catálogo se
     * exige el texto libre.
     *
     * @param array{MMO_Motivo?: ?int, MOV_Motivo?: ?string, MOV_Motivo_Detalle?: ?string, MOV_Observacion?: ?string} $d
     *
     * @return array{MMO_Motivo: ?int, MOV_Motivo: ?string, MOV_Motivo_Detalle: ?string}
     */
    public function conMotivo(array $d, string $aplicaA): array
    {
        $id = $d['MMO_Motivo'] ?? null;
        $detalle = mb_substr(trim((string) (($d['MOV_Motivo_Detalle'] ?? null) ?: ($d['MOV_Observacion'] ?? null) ?: '')), 0, 255);
        $detalle = '' === $detalle ? null : $detalle;
        if (null === $id) {
            if ('' === trim((string) ($d['MOV_Motivo'] ?? ''))) {
                throw ErrorDominio::noProcesable('MOVEMENT_REASON_REQUIRED');
            }

            return ['MMO_Motivo' => null, 'MOV_Motivo' => $d['MOV_Motivo'], 'MOV_Motivo_Detalle' => null];
        }
        $m = $this->datos->motivo($id);
        if (false === $m || 1 !== (int) $m['MMO_Activo']) {
            throw ErrorDominio::noProcesable('MOVEMENT_REASON_NOT_FOUND');
        }
        $aplica = null === $m['MMO_Aplica_A'] ? [] : (json_decode((string) $m['MMO_Aplica_A'], true) ?: []);
        if ([] !== $aplica && !\in_array($aplicaA, $aplica, true)) {
            throw ErrorDominio::noProcesable('MOVEMENT_REASON_NOT_APPLICABLE');
        }
        if (1 === (int) $m['MMO_Pide_Detalle'] && null === $detalle) {
            throw ErrorDominio::noProcesable('MOVEMENT_REASON_DETAIL_REQUIRED');
        }
        $texto = null !== $detalle ? "{$m['MMO_Nombre']}: {$detalle}" : (string) $m['MMO_Nombre'];

        return ['MMO_Motivo' => (int) $id, 'MOV_Motivo' => mb_substr($texto, 0, 255), 'MOV_Motivo_Detalle' => $detalle];
    }

    /** ¿El motivo del catálogo aplica a este tipo de movimiento? */
    public function aplica(?int $motivo, string $aplicaA): bool
    {
        if (null === $motivo) {
            return false;
        }
        $m = $this->datos->motivo($motivo);
        if (false === $m) {
            return false;
        }
        $a = $m['MMO_Aplica_A'];
        $lista = null === $a ? [] : (json_decode((string) $a, true) ?: []);

        return [] === $lista || \in_array($aplicaA, $lista, true);
    }

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

    /** @param array<string, mixed>|null $p */
    private static function nombre(?array $p): string
    {
        return null === $p ? '—' : $p['PER_Primer_Nombre'].' '.$p['PER_Primer_Apellido'];
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
            $activo = $this->activo($d['ACT_Activo']) ?? throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
            // Una impresora o un switch se instalan en un área, no se asignan a una persona.
            $this->reglas->exigirAsignableAPersona($activo);
            $tipo = $this->tipoCanonico((int) $d['TMO_Tipo_Movimiento']);
            // Este endpoint solo ABRE custodias; devolución, transferencia, ingreso y baja tienen su operación.
            if (!\in_array($tipo, [self::ASIGNACION, self::PRESTAMO], true)) {
                throw ErrorDominio::invalido('MOVEMENT_TYPE_REQUIRES_SPECIFIC_OPERATION');
            }
            $estado = $this->estadoDe($activo) ?? throw ErrorDominio::interno('UNKNOWN_CURRENT_STATE');
            EstadosActivo::validarTransicion($estado, EstadosActivo::ASIGNADO);
            $persona = $this->persona($d['PER_Persona']) ?? throw ErrorDominio::noEncontrado('PERSON_NOT_FOUND');
            self::exigirPersonaActiva($persona);
            $this->exigirOperable($activo);
            if (!$this->existeArea((int) $d['ARE_Area'])) {
                throw ErrorDominio::noEncontrado('AREA_NOT_FOUND');
            }
            $sede = $this->sedeDeDestino((int) $d['ARE_Area']);
            $vigente = $this->vigente($d['ACT_Activo']);
            $hoy = Reloj::ahora()->format('Y-m-d');
            if (self::PRESTAMO === $tipo) {
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
            $d = [...$d, ...$this->conMotivo($d, self::PRESTAMO === $tipo ? 'PRESTAMO' : 'ASIGNACION')];
            $this->ubicarEnSede($activo, $persona, $sede, $usuario, $ip);
            $mov = $this->insertarMovimiento($d, $usuario);
            $this->activos->actualizar($d['ACT_Activo'], ['ARE_Area_Ubicacion' => $d['ARE_Area']]);
            $this->kardex->registrar(self::PRESTAMO === $tipo ? 'PRESTAMO' : 'ASIGNACION', activo: $activo, usuario: $usuario,
                destino: ['persona' => $d['PER_Persona'], 'area' => (int) $d['ARE_Area'], 'sede' => $sede], documento: ['MOVIMIENTO', Ident::aTexto($mov)],
                motivo: ($d['MOV_Motivo'] ?? null) ?: ($d['MOV_Observacion'] ?? null), detalle: ['devolucion_prevista' => $d['MOV_Fecha_Prevista_Devolucion']]);
            $asignado = $this->idEstado(EstadosActivo::ASIGNADO);
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
            'tipo' => $a['TAC_Nombre'] ?? '', 'marca' => $a['MAR_Nombre'] ?? '', 'modelo' => $a['MOD_Nombre'] ?? '', 'persona_nombre' => self::nombre($persona),
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
            $vigente = $this->vigente($d['ACT_Activo']) ?? throw ErrorDominio::invalido('ASSET_IS_NOT_ASSIGNED');
            $activo = $this->activo($d['ACT_Activo']) ?? throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
            $estado = $this->estadoDe($activo);
            $reparacion = $this->ticketAbierto($d['ACT_Activo']);
            if (!$reparacion) {
                EstadosActivo::validarTransicion($estado ?? throw ErrorDominio::interno('UNKNOWN_CURRENT_STATE'), EstadosActivo::BODEGA);
            }
            $destino = $d['ARE_Area_Destino'] ?? null;
            if (null !== $destino) {
                if (!$this->existeArea((int) $destino)) {
                    throw ErrorDominio::noEncontrado('AREA_NOT_FOUND');
                }
                $this->sedeDeDestino((int) $destino);
            }
            $motivo = $this->conMotivo($d, 'DEVOLUCION');
            if (!$this->cerrar((string) $vigente['MOV_Movimiento'])) {
                throw ErrorDominio::conflicto('MOVEMENT_ALREADY_CLOSED');
            }
            $this->datos->anotarMotivo($vigente['MOV_Movimiento'], $motivo['MOV_Motivo'], $motivo['MMO_Motivo'], $motivo['MOV_Motivo_Detalle']);
            $bodega = $this->idEstado(EstadosActivo::BODEGA);
            $this->activos->actualizar($d['ACT_Activo'], ['ARE_Area_Ubicacion' => $destino, ...($reparacion ? [] : ['EOP_Estado_Operativo' => $bodega])]);
            $sede = null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'];
            $this->kardex->registrar('DEVOLUCION', activo: $activo, usuario: $usuario,
                origen: ['persona' => (string) $vigente['PER_Persona'], 'area' => (int) $vigente['ARE_Area'], 'sede' => $sede],
                destino: ['area' => null === $destino ? null : (int) $destino, 'sede' => $sede], documento: ['MOVIMIENTO', Ident::aTexto((string) $vigente['MOV_Movimiento'])],
                motivo: $motivo['MOV_Motivo'], detalle: ['condicion' => $d['condicion'] ?? null, 'en_reparacion' => $reparacion]);
            $this->gobierno->registrarAuditoria('RETURN', 'INV_MOVIMIENTO', ['activo' => Ident::aTexto($d['ACT_Activo']), 'estado_nuevo' => $bodega], $usuario, $ip, sedeId: $sede);
            $persona = $this->datos->contactoDePersona($vigente['PER_Persona']) ?: null;
            $op = $this->operador->de($usuario);
            $contexto = ['codigo' => $activo['ACT_Codigo_Interno'], 'persona_nombre' => self::nombre($persona), 'fecha' => Reloj::iso(Reloj::ahora())];
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
            $activo = $this->activo($d['ACT_Activo']) ?? throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
            $this->reglas->exigirAsignableAPersona($activo);
            EstadosActivo::validarTransicion($this->estadoDe($activo) ?? throw ErrorDominio::interno('UNKNOWN_CURRENT_STATE'), EstadosActivo::ASIGNADO);
            $vigente = $this->vigente($d['ACT_Activo']) ?? throw ErrorDominio::invalido('ASSET_IS_NOT_ASSIGNED_CANNOT_TRANSFER');
            $persona = $this->persona($d['PER_Persona_Destino']) ?? throw ErrorDominio::noEncontrado('DESTINATION_PERSON_NOT_FOUND');
            self::exigirPersonaActiva($persona);
            $this->exigirOperable($activo);
            if ((string) $vigente['PER_Persona'] === $d['PER_Persona_Destino'] && (int) $vigente['ARE_Area'] === (int) $d['ARE_Area_Destino']) {
                throw ErrorDominio::invalido('TRANSFER_TO_SAME_CUSTODIAN');
            }
            if (!$this->existeArea((int) $d['ARE_Area_Destino'])) {
                throw ErrorDominio::noEncontrado('DESTINATION_AREA_NOT_FOUND');
            }
            $sede = $this->sedeDeDestino((int) $d['ARE_Area_Destino']);
            // La nueva custodia se registra como "Transferencia" (o "Asignación" si el catálogo no lo tiene).
            $tipo = $this->idTipo(self::TRANSFERENCIA) ?? $this->idTipo(self::ASIGNACION)
                ?? $this->datos->primerTipoDeAsignacion()
                ?? throw ErrorDominio::interno('SYSTEM_CONFIG_ERROR_MISSING_ASSIGNMENT_TYPE');
            $motivo = $this->conMotivo($d, 'TRANSFERENCIA');
            $this->cerrar((string) $vigente['MOV_Movimiento']);
            $sedeOrigen = null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'];
            $this->ubicarEnSede($activo, $persona, $sede, $usuario, $ip);
            $mov = $this->insertarMovimiento(['ACT_Activo' => $d['ACT_Activo'], 'PER_Persona' => $d['PER_Persona_Destino'], 'ARE_Area' => (int) $d['ARE_Area_Destino'],
                'TMO_Tipo_Movimiento' => $tipo, 'MOV_Observacion' => ($d['MOV_Observacion'] ?? null) ?: 'Transferencia de custodia', ...$motivo], $usuario);
            $this->activos->actualizar($d['ACT_Activo'], ['ARE_Area_Ubicacion' => (int) $d['ARE_Area_Destino'], 'EOP_Estado_Operativo' => $this->idEstado(EstadosActivo::ASIGNADO)]);
            $this->kardex->registrar('TRANSFERENCIA', activo: $activo, usuario: $usuario,
                origen: ['persona' => (string) $vigente['PER_Persona'], 'area' => (int) $vigente['ARE_Area'], 'sede' => $sedeOrigen],
                destino: ['persona' => $d['PER_Persona_Destino'], 'area' => (int) $d['ARE_Area_Destino'], 'sede' => $sede],
                documento: ['MOVIMIENTO', Ident::aTexto($mov)], motivo: ($motivo['MOV_Motivo'] ?? null) ?: ($d['MOV_Observacion'] ?? null));
            $this->gobierno->registrarAuditoria('TRANSFER', 'INV_MOVIMIENTO', ['activo' => Ident::aTexto($d['ACT_Activo']), 'de' => Ident::aTexto((string) $vigente['PER_Persona']),
                'a' => Ident::aTexto($d['PER_Persona_Destino']), 'sede_origen' => $sedeOrigen, 'sede_destino' => $sede], $usuario, $ip, sedeId: $sede);
            $origen = $this->datos->contactoDePersona($vigente['PER_Persona']) ?: null;
            $op = $this->operador->de($usuario);
            $para = array_values(array_filter([$origen['PER_Email_Corporativo'] ?? null, $persona['PER_Email_Corporativo'] ?? null]));
            $contexto = ['codigo' => $activo['ACT_Codigo_Interno'], 'origen_nombre' => self::nombre($origen), 'destino_nombre' => self::nombre($persona),
                'fecha' => Reloj::iso(Reloj::ahora())];
            $this->transaccion->alConfirmar(fn () => $this->notificador->enviar('transferencia', $contexto, $para, responderA: $op['correo'],
                operador: $op['nombre'], rolOperador: $op['rol']));

            return $this->porId($mov);
        }, ErrorDominio::conflicto('ASSET_ALREADY_HAS_OPEN_MOVEMENT'));
    }

    // =====================================================================
    // Offboarding (salida de una persona)
    // =====================================================================

    /**
     * En una transacción: cierra todas sus custodias (los equipos vuelven a
     * bodega, salvo los que están en reparación), libera sus licencias, marca sus
     * cuentas compartidas para cambio de clave, desactiva su cuenta y la inactiva.
     * Idempotente.
     *
     * @return array<string, mixed>
     */
    public function offboarding(string $personaId, bool $desactivarUsuario, string $usuario, string $rol, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($personaId, $desactivarUsuario, $usuario, $rol, $ip): array {
            $persona = $this->persona($personaId) ?? throw ErrorDominio::noEncontrado('PERSON_NOT_FOUND');
            $sedePersona = null === $persona['SED_Sede'] ? null : (int) $persona['SED_Sede'];
            $this->alcance->exigirPropio($sedePersona);
            // Si alguna custodia está en una sede fuera del alcance, se haría a medias.
            $visibles = $this->datos->custodiasVisiblesDe($personaId);
            $totales = $this->alcance->elevado(fn (): int => $this->datos->custodiasDe($personaId));
            if ($totales !== $visibles) {
                throw ErrorDominio::prohibido('OFFBOARDING_REQUIRES_SCOPE_OVER_ALL_ASSETS');
            }
            $estabaActiva = 1 === (int) $persona['PER_Estado'];
            // La cuenta solo se desactiva si se puede (nunca el último SUPER_ADMIN, ni la propia, ni fuera de la jerarquía).
            $cuenta = !$desactivarUsuario ? false : $this->datos->cuentaDePersona($personaId);
            if (false !== $cuenta && 1 === (int) $cuenta['USU_Estado']) {
                if (Roles::SUPER_ADMIN === $cuenta['USU_Rol'] && $this->identidades->superAdministradoresActivos() <= 1) {
                    throw ErrorDominio::invalido('CANNOT_OFFBOARD_LAST_SUPER_ADMIN');
                }
                if ((string) $cuenta['USU_Usuario'] === $usuario) {
                    throw ErrorDominio::prohibido('CANNOT_MODIFY_OWN_ACCOUNT');
                }
                if (!self::puedeDesactivarEnSalida($rol, (string) $cuenta['USU_Rol'])) {
                    throw ErrorDominio::prohibido('OFFBOARDING_ACCOUNT_REQUIRES_IAM');
                }
            }
            // Cerrojo único en todo el sistema: activos (en orden) -> movimientos -> sello del kardex.
            $vigentes = $this->datos->bloquearCustodiasDe($personaId);
            $cerrados = $this->datos->cerrarTodosDe($personaId, Reloj::ahora());
            $activos = array_map(static fn (array $m): string => (string) $m['ACT_Activo'], $vigentes);
            $reparacion = array_values(array_filter($activos, fn (string $a): bool => $this->ticketAbierto($a)));
            $aBodega = array_values(array_diff($activos, $reparacion));
            if ([] !== $aBodega) {
                $bodega = $this->idEstado(EstadosActivo::BODEGA);
                foreach ($aBodega as $a) {
                    $this->activos->actualizar($a, ['EOP_Estado_Operativo' => $bodega]);
                }
            }
            foreach ($vigentes as $m) {
                $act = $this->activos->porId((string) $m['ACT_Activo']) ?: null;
                $sede = null === $act || null === $act['SED_Sede'] ? null : (int) $act['SED_Sede'];
                $this->kardex->registrar('DEVOLUCION', activo: $act, activoId: (string) $m['ACT_Activo'], usuario: $usuario,
                    origen: ['persona' => $personaId, 'area' => (int) $m['ARE_Area'], 'sede' => $sede], destino: ['sede' => $sede],
                    documento: ['OFFBOARDING', Ident::aTexto($personaId)], motivo: 'Salida de la persona (offboarding)',
                    detalle: ['en_reparacion' => \in_array((string) $m['ACT_Activo'], $reparacion, true)]);
                $this->activos->actualizar((string) $m['ACT_Activo'], ['ARE_Area_Ubicacion' => null]);
            }
            $liberadas = $this->instalaciones->liberarPorDestino($activos, $personaId, 'Salida de la persona (offboarding)');
            foreach ($liberadas as $l) {
                $this->kardex->registrar('LICENCIA_RETIRADA', licencia: $l['licencia'], clave: $l['clave'], referencia: "Licencia #{$l['licencia']}", cantidad: 1,
                    usuario: $usuario, origen: ['persona' => $l['persona'], 'activo' => $l['activo'], 'sede' => $sedePersona], motivo: 'Salida de la persona (offboarding)');
            }
            // Las cuentas que usaba quedan con el pendiente «cambiar la clave».
            $cuentasPorCambiar = $this->credenciales->marcarCambioTrasSalida(array_values(array_filter(array_column($liberadas, 'clave'))));
            $desactivado = false;
            if (false !== $cuenta) {
                // La columna de identidad está protegida: se cambia sin contexto de usuario.
                $this->alcance->elevado(fn () => $this->cuentas->actualizar((string) $cuenta['USU_Usuario'], ['USU_Estado' => 0]));
                $this->identidades->revocarAcceso((string) $cuenta['USU_Usuario']);
                $desactivado = true;
            }
            $this->datos->desactivarPersona($personaId);
            $cambio = $cerrados > 0 || [] !== $liberadas || $desactivado || $estabaActiva;
            if ($cambio) {
                $ids = array_map(static fn (string $a): string => Ident::aTexto($a), $activos);
                $this->gobierno->registrarAuditoria('OFFBOARDING', 'INV_PERSONA', ['persona_id' => Ident::aTexto($personaId), 'usuario_desactivado' => $desactivado,
                    'movimientos_cerrados' => $cerrados, 'activos_devueltos' => \count($activos), 'activos_ids' => $ids, 'licencias_liberadas' => \count($liberadas)],
                    $usuario, $ip, sedeId: $sedePersona);
                $codigos = array_values($this->datosDeActivos->codigosDe($activos));
                $op = $this->operador->de($usuario);
                $correo = $persona['PER_Email_Corporativo'];
                $contexto = ['persona_nombre' => self::nombre($persona), 'persona_email' => $correo ?: '—', 'num_activos' => \count($codigos), 'activos_lista' => $codigos,
                    'usuario_desactivado' => $desactivado ? 'Sí' : 'No', 'fecha' => Reloj::iso(Reloj::ahora())];
                $this->transaccion->alConfirmar(fn () => $this->notificador->enviar('offboarding', $contexto, [], responderA: $op['correo'], operador: $op['nombre'],
                    rolOperador: $op['rol'], afectados: null === $correo || '' === $correo ? [] : [$correo]));
            }

            return ['status' => 'success', 'persona_id' => Ident::aTexto($personaId), 'movimientos_cerrados' => $cerrados,
                'activos_devueltos_a_bodega' => \count($activos), 'licencias_liberadas' => \count($liberadas), 'cuentas_por_cambiar' => $cuentasPorCambiar,
                'usuario_desactivado' => $desactivado, 'persona_inactivada' => true, 'idempotent_noop' => !$cambio];
        });
    }

    /** Cuentas que quien gestiona una salida puede desactivar sin pasar por IAM. */
    private static function puedeDesactivarEnSalida(string $solicitante, string $objetivo): bool
    {
        return Roles::puedeAdministrar($solicitante, $objetivo) || \in_array($objetivo, match ($solicitante) {
            Roles::ADMIN_TI => [Roles::ADMIN_TI, Roles::TECNICO, Roles::CONSULTA, Roles::COMPRAS],
            default => [],
        }, true);
    }

    // =====================================================================
    // Equipos compartidos instalados en un área
    // =====================================================================

    /** @return array<string, mixed> */
    public function estadoInstalacion(string $activo): array
    {
        $f = $this->datos->instalacionEnArea($activo) ?: throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');

        return ['ACT_Activo' => Ident::aTexto((string) $f['ACT_Activo']), 'ACT_Codigo_Interno' => $f['ACT_Codigo_Interno'], 'ACT_Instalado' => 1 === (int) $f['ACT_Instalado'],
            'ARE_Area' => null === $f['ARE_Area_Ubicacion'] ? null : (int) $f['ARE_Area_Ubicacion'], 'area' => $f['ARE_Nombre'],
            'PER_Responsable' => null === $f['ACT_Responsable'] ? null : Ident::aTexto((string) $f['ACT_Responsable']),
            'responsable' => null === $f['PER_Primer_Nombre'] ? null : $f['PER_Primer_Nombre'].' '.$f['PER_Primer_Apellido']];
    }

    /**
     * Instala o reubica un equipo compartido en un área (responsable opcional).
     *
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public function instalarEnArea(array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $usuario, $ip): array {
            $activo = $this->activo($d['ACT_Activo']) ?? throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
            $estado = $this->estadoDe($activo);
            $this->reglas->exigirInstalableEnArea($activo);
            if (!\in_array($estado, [EstadosActivo::BODEGA, EstadosActivo::INSTALADO], true)) {
                throw ErrorDominio::conflicto('ASSET_NOT_AVAILABLE_FOR_INSTALLATION');
            }
            EstadosActivo::validarTransicion((string) $estado, EstadosActivo::INSTALADO);
            $this->exigirOperable($activo);
            if (null !== $this->vigente($d['ACT_Activo'], false)) {
                throw ErrorDominio::conflicto('ASSET_HAS_CUSTODIAN_RETURN_FIRST');
            }
            $area = (int) $d['ARE_Area'];
            $nombreArea = $this->datos->nombreDeArea($area);
            if (false === $nombreArea) {
                throw ErrorDominio::noEncontrado('AREA_NOT_FOUND');
            }
            $responsableId = $d['PER_Responsable'] ?? null;
            if (EstadosActivo::INSTALADO === $estado && (int) $activo['ARE_Area_Ubicacion'] === $area && ($activo['ACT_Responsable'] ?? null) === $responsableId) {
                throw ErrorDominio::conflicto('ASSET_ALREADY_INSTALLED_THERE');
            }
            $responsable = null;
            if (null !== $responsableId) {
                $responsable = $this->cuentas->personaCompleta($responsableId) ?: throw ErrorDominio::noEncontrado('PERSON_NOT_FOUND');
                self::exigirPersonaActiva($responsable);
            }
            $motivo = $this->conMotivo($d, 'INSTALACION');
            $sede = $this->sedeDeDestino($area);
            $this->ubicarEnSede($activo, null, $sede, $usuario, $ip);
            $origen = ['area' => null === $activo['ARE_Area_Ubicacion'] ? null : (int) $activo['ARE_Area_Ubicacion'],
                'sede' => null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'], 'persona' => $activo['ACT_Responsable']];
            $reubicacion = EstadosActivo::INSTALADO === $estado;
            $this->activos->actualizar($d['ACT_Activo'], ['EOP_Estado_Operativo' => $this->idEstado(EstadosActivo::INSTALADO), 'ARE_Area_Ubicacion' => $area,
                'ACT_Instalado' => 1, 'ACT_Responsable' => $responsableId]);
            // Los componentes instalados en el equipo lo siguen.
            $this->datos->ubicarComponentesDe($d['ACT_Activo'], $area, $sede);
            $nombreResp = null === $responsable ? null : self::nombre($responsable);
            $this->kardex->registrar('INSTALACION_AREA', activo: $activo, usuario: $usuario,
                origen: $reubicacion ? $origen : ['area' => $origen['area'], 'sede' => $origen['sede']],
                destino: ['area' => $area, 'sede' => $sede, 'persona' => $responsableId], motivo: $motivo['MOV_Motivo'],
                detalle: ['responsable' => $nombreResp, 'reubicacion' => $reubicacion]);
            $this->gobierno->registrarAuditoria('ASSET_INSTALL_AREA', 'INV_ACTIVO', ['activo' => Ident::aTexto($d['ACT_Activo']), 'codigo' => $activo['ACT_Codigo_Interno'],
                'area' => $nombreArea, 'responsable' => $nombreResp, 'motivo' => $motivo['MOV_Motivo'], 'reubicacion' => $reubicacion], $usuario, $ip, sedeId: $sede);

            return $this->estadoInstalacion($d['ACT_Activo']);
        });
    }

    /**
     * Retira un equipo compartido de su área (queda en bodega).
     *
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public function retirarDeArea(array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $usuario, $ip): array {
            $activo = $this->activo($d['ACT_Activo']) ?? throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
            if (EstadosActivo::INSTALADO !== $this->estadoDe($activo)) {
                throw ErrorDominio::conflicto('ASSET_NOT_INSTALLED');
            }
            $this->exigirOperable($activo);
            $destino = $d['ARE_Area_Destino'] ?? null;
            $sede = null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'];
            if (null !== $destino) {
                if (!$this->existeArea((int) $destino)) {
                    throw ErrorDominio::noEncontrado('AREA_NOT_FOUND');
                }
                $sede = $this->sedeDeDestino((int) $destino);
                $this->ubicarEnSede($activo, null, $sede, $usuario, $ip);
            }
            $motivo = $this->conMotivo($d, 'RETIRO');
            $origen = ['area' => null === $activo['ARE_Area_Ubicacion'] ? null : (int) $activo['ARE_Area_Ubicacion'],
                'sede' => null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'], 'persona' => $activo['ACT_Responsable']];
            $this->activos->actualizar($d['ACT_Activo'], ['EOP_Estado_Operativo' => $this->idEstado(EstadosActivo::BODEGA),
                'ARE_Area_Ubicacion' => $destino ?? $activo['ARE_Area_Ubicacion'], 'ACT_Instalado' => 0, 'ACT_Responsable' => null]);
            $condicion = $d['condicion'] ?? null;
            $this->kardex->registrar('RETIRO_AREA', activo: $activo, usuario: $usuario, origen: $origen, destino: ['area' => null === $destino ? null : (int) $destino, 'sede' => $sede],
                motivo: $motivo['MOV_Motivo'], detalle: null === $condicion ? null : ['condicion' => $condicion]);
            $this->gobierno->registrarAuditoria('ASSET_REMOVE_AREA', 'INV_ACTIVO', ['activo' => Ident::aTexto($d['ACT_Activo']), 'codigo' => $activo['ACT_Codigo_Interno'],
                'motivo' => $motivo['MOV_Motivo'], 'condicion' => $condicion], $usuario, $ip, sedeId: null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede']);

            return $this->estadoInstalacion($d['ACT_Activo']);
        });
    }
}
