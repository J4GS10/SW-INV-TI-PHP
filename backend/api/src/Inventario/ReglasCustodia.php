<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Inventario\Infrastructure\ActivosStore;
use App\Inventario\Infrastructure\DatosActivos;
use App\Inventario\Infrastructure\Kardex;
use App\Inventario\Infrastructure\MantenimientosStore;
use App\Inventario\Infrastructure\MovimientosStore;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;

/**
 * Lo que toda operación de custodia comprueba antes de mover un activo: que el activo y la persona sean visibles
 * y estén en condiciones, que el destino exista y esté en el alcance, y que el motivo sea válido.
 */
final readonly class ReglasCustodia
{
    public function __construct(
        private MovimientosStore $datos,
        private ActivosStore $activos,
        private DatosActivos $datosDeActivos,
        private MantenimientosStore $mantenimientos,
        private Alcance $alcance,
        private EstadosActivo $estados,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
    ) {
    }

    /** @return array<string, mixed>|null fila del activo (bloqueada) visible para el usuario */
    public function activo(string $id): ?array
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
    public function persona(string $id): ?array
    {
        $f = $this->datos->personaVisible($id);

        return false === $f ? null : $f;
    }

    /** @return array<string, mixed>|null */
    public function vigente(string $activo, bool $bloquear = true): ?array
    {
        $m = $this->datos->vigenteDe($activo, $bloquear);

        return false === $m ? null : $m;
    }

    /** @param array<string, mixed> $activo */
    public function estadoDe(array $activo): ?string
    {
        return EstadosActivo::canonicoFlexible($this->estados->nombre((int) $activo['EOP_Estado_Operativo']));
    }

    public function idEstado(string $estado): int
    {
        return $this->estados->idDe($estado) ?? throw ErrorDominio::interno('SYSTEM_CONFIG_ERROR_MISSING_OPERATIONAL_STATE');
    }

    /** @param array<string, mixed>|null $persona No se entregan equipos a personas inactivas. */
    public static function exigirPersonaActiva(?array $persona): void
    {
        if (null !== $persona && 1 !== (int) $persona['PER_Estado']) {
            throw ErrorDominio::invalido('PERSON_INACTIVE');
        }
    }

    public function ticketAbierto(string $activo): bool
    {
        return $this->mantenimientos->tieneTicketAbierto($activo);
    }

    /**
     * Un equipo en reparación, instalado dentro de otro o con una baja pendiente no se entrega.
     *
     * @param array<string, mixed> $activo
     */
    public function exigirOperable(array $activo): void
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
    public function sedeDeDestino(int $area): int
    {
        $sede = $this->datosDeActivos->sedeDeArea($area);
        if (false === $sede || null === $sede) {
            throw ErrorDominio::invalido('AREA_WITHOUT_SEDE');
        }

        return (int) $this->alcance->exigirSede((int) $sede);
    }

    public function existeArea(int $area): bool
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
    public function ubicarEnSede(array $activo, ?array $persona, ?int $sede, string $usuario, ?string $ip): void
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

    /** @param array<string, mixed>|null $p */
    public static function nombre(?array $p): string
    {
        return null === $p ? '—' : $p['PER_Primer_Nombre'].' '.$p['PER_Primer_Apellido'];
    }
}
