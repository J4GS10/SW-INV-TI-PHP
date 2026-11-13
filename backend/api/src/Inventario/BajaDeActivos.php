<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Catalogo\CatalogoGenerico;
use App\Correo\Notificador;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Inventario\Infrastructure\ActivosStore;
use App\Inventario\Infrastructure\Instalaciones;
use App\Inventario\Infrastructure\Kardex;
use App\Inventario\Infrastructure\Operador;
use App\Repositorio\GobiernoRepositorio;

/**
 * Baja de un activo: la solicitud se valida al pedirla y se ejecuta al aprobarse. Al ejecutarla se
 * retiran sus componentes, se liberan sus licencias y, si se perdió o lo robaron, se cierra su custodia.
 */
final readonly class BajaDeActivos
{
    private const array MOTIVOS_SIN_DEVOLUCION = ['PERDIDA', 'ROBO'];

    public function __construct(
        private Activos $activos,
        private ComponentesDeActivo $componentes,
        private ActivosStore $datos,
        private CatalogoGenerico $catalogos,
        private EstadosActivo $estados,
        private Instalaciones $instalaciones,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
        private Notificador $notificador,
        private Operador $operador,
    ) {
    }

    /**
     * Reglas de la baja, al solicitarla y otra vez al aprobarla.
     *
     * @param array{motivo: string, destino: string, fecha?: ?string, documento?: ?string, observacion?: ?string, retirar_componentes?: bool} $baja
     *
     * @return array{0: array<string, mixed>, 1: int, 2: list<array<string, mixed>>} [activo, estado Baja, componentes]
     */
    public function validarBaja(string $id, array $baja, bool $alAprobar = false): array
    {
        // Al aprobar se acepta un motivo o destino desactivado después de la solicitud.
        $motivo = $this->catalogos->porCodigo('motivos-baja', $baja['motivo'], !$alAprobar) ?? throw ErrorDominio::noProcesable('DECOMMISSION_REASON_NOT_FOUND');
        $destino = $this->catalogos->porCodigo('destinos-baja', $baja['destino'], !$alAprobar) ?? throw ErrorDominio::noProcesable('DECOMMISSION_DESTINATION_NOT_FOUND');
        if ((1 === (int) ($motivo['MBA_Pide_Detalle'] ?? 0) || 1 === (int) ($destino['DBA_Pide_Detalle'] ?? 0))
            && '' === trim((string) ($baja['observacion'] ?? ''))) {
            throw ErrorDominio::noProcesable('OBSERVATION_REQUIRED_FOR_OTHER');
        }
        $activo = $this->activos->filaO404($id);
        // Lo perdido o robado no puede devolverse: su custodia se cierra al aprobar, sin una devolución falsa.
        $sinDevolucion = \in_array($baja['motivo'], self::MOTIVOS_SIN_DEVOLUCION, true);
        if (null !== $this->activos->movimientoVigente($id) && !$sinDevolucion) {
            throw ErrorDominio::invalido('CANNOT_DELETE_ASSIGNED_ASSET_RETURN_IT_FIRST');
        }
        if ($this->activos->mantenimientoAbierto($id)) {
            throw ErrorDominio::conflicto('ASSET_HAS_OPEN_MAINTENANCE');
        }
        if (null !== $activo['ACT_Activo_Padre']) {
            throw ErrorDominio::conflicto('ASSET_INSTALLED_AS_COMPONENT');
        }
        $estadoBaja = $this->estados->idObligatorio(EstadosActivo::BAJA);
        $actual = $this->estados->canonicoDeId((int) $activo['EOP_Estado_Operativo']);
        if (EstadosActivo::BAJA === $actual) {
            throw ErrorDominio::conflicto('ASSET_ALREADY_DECOMMISSIONED');
        }
        if (!$sinDevolucion || EstadosActivo::ASIGNADO !== $actual) {
            EstadosActivo::exigirTransicion($actual, EstadosActivo::BAJA);
        }
        $componentes = $this->componentes->componentesDe($id);
        if ([] !== $componentes && !($baja['retirar_componentes'] ?? false)) {
            throw ErrorDominio::conflicto('ASSET_HAS_COMPONENTS:'.\count($componentes));
        }

        return [$activo, $estadoBaja, $componentes];
    }

    /**
     * Ejecuta una baja APROBADA: retira componentes, libera licencias y deja la
     * SALIDA en el kardex con quién la solicitó y quién la aprobó.
     *
     * @param array<string, mixed> $baja
     * @param array<string, mixed> $aprobacion traza {aprobacion, solicitado_por, aprobado_por}
     */
    public function darDeBaja(string $id, array $baja, string $usuario, array $aprobacion): void
    {
        // Cerrojo único: primero el activo, después sus movimientos.
        $this->datos->bloquear($id);
        [$activo, $estadoBaja, $componentes] = $this->validarBaja($id, $baja, alAprobar: true);
        $custodia = $this->datos->custodioBloqueado($id);
        if (false !== $custodia) {
            $this->datos->cerrarCustodia($id, Reloj::ahora());
        }
        foreach ($componentes as $c) {
            $this->componentes->retirar($activo, $c, 'Retiro por baja del equipo', EstadosActivo::BODEGA, $usuario);
        }
        $fecha = $baja['fecha'] ?? null ?: Reloj::ahora()->format('Y-m-d');
        $this->activos->escribirCambios($id, ['EOP_Estado_Operativo' => $estadoBaja, 'ACT_Baja_Motivo' => $baja['motivo'], 'ACT_Baja_Destino' => $baja['destino'],
            'ACT_Baja_Fecha' => $fecha, 'ACT_Baja_Documento' => $baja['documento'] ?? null, 'ACT_Baja_Observacion' => $baja['observacion'] ?? null,
            'ARE_Area_Ubicacion' => null]);
        $sede = null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'];
        foreach ($this->instalaciones->liberarPorDestino([$id], motivo: 'Baja del equipo') as $l) {
            $this->kardex->registrar('LICENCIA_RETIRADA', licencia: $l['licencia'], clave: $l['clave'], referencia: "Licencia #{$l['licencia']}",
                origen: ['activo' => $l['activo'], 'sede' => $sede], cantidad: 1, motivo: 'Baja del equipo', usuario: $usuario);
        }
        $documento = $baja['documento'] ?? null;
        $this->kardex->registrar('BAJA', activo: $activo, usuario: $usuario, origen: ['sede' => $sede],
            costo: $activo['ACT_Costo'], moneda: $activo['ACT_Moneda'], documento: null === $documento || '' === $documento ? null : ['BAJA', $documento],
            motivo: $baja['observacion'] ?? null,
            detalle: ['motivo' => $baja['motivo'], 'destino' => $baja['destino'], 'fecha' => $fecha, ...$aprobacion,
                ...(false === $custodia ? [] : ['custodia_sin_devolucion' => Ident::aTexto((string) $custodia)])]);
        $this->gobierno->registrarAuditoria('DELETE_LOGIC', 'INV_ACTIVO', ['activo_id' => Ident::aTexto($id), 'codigo' => $activo['ACT_Codigo_Interno'],
            'motivo' => $baja['motivo'], 'destino' => $baja['destino'], 'documento' => $documento,
            ...array_map(static fn (mixed $v): string => (string) $v, $aprobacion)], $usuario, sedeId: $sede);
        $op = $this->operador->de($usuario);
        $codigo = (string) $activo['ACT_Codigo_Interno'];
        $serie = (string) $activo['ACT_Serie_Fabricante'];
        $this->transaccion->alConfirmar(fn () => $this->notificador->enviar('baja', ['codigo' => $codigo, 'serie' => $serie,
            'fecha' => Reloj::iso(Reloj::ahora()), 'motivo' => 'Baja lógica solicitada por administrador'],
            responderA: $op['correo'], operador: $op['nombre'], rolOperador: $op['rol']));
    }
}
