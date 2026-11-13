<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Inventario\Infrastructure\ActivosStore;
use App\Inventario\Infrastructure\Kardex;
use App\Repositorio\GobiernoRepositorio;

/**
 * Componentes (disco, memoria, monitor…) instalados en un equipo: instalar y retirar, sin ciclos,
 * solo los que admite el tipo de equipo, y con cada cambio en el kardex y en la bitácora.
 */
final readonly class ComponentesDeActivo
{
    public function __construct(
        private Activos $activos,
        private ActivosStore $datos,
        private Kardex $kardex,
        private EstadosActivo $estados,
        private ReglasClase $reglas,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function componentesDe(string $equipo): array
    {
        return $this->datos->componentesDe($equipo);
    }

    /** @return array<string, mixed> instalados hoy e historial de instalaciones y retiros */
    public function componentes(string $equipo): array
    {
        $e = $this->activos->filaO404($equipo);

        return [
            'equipo' => Ident::aTexto($equipo),
            'instalado_en' => null === $e['ACT_Activo_Padre'] ? null : Ident::aTexto((string) $e['ACT_Activo_Padre']),
            'actuales' => array_map(static fn (array $c): array => ['ACT_Activo' => Ident::aTexto((string) $c['ACT_Activo']),
                'ACT_Codigo_Interno' => $c['ACT_Codigo_Interno'], 'ACT_Serie_Fabricante' => $c['ACT_Serie_Fabricante'],
                'TAC_Tipo_Activo' => (int) $c['TAC_Tipo_Activo'], 'MOD_Modelo' => (int) $c['MOD_Modelo']], $this->componentesDe($equipo)),
            'historial' => $this->kardex->donde("k.\"KDX_Tipo\" IN ('COMPONENTE_INSTALADO', 'COMPONENTE_RETIRADO') "
                .'AND (k."KDX_Destino_Activo" = :e OR k."KDX_Origen_Activo" = :e)', ['e' => $equipo]),
        ];
    }

    /** @return array<string, mixed> */
    public function instalarComponente(string $equipoId, string $componenteId, ?string $motivo, string $usuario): array
    {
        return $this->transaccion->ejecutar(function () use ($equipoId, $componenteId, $motivo, $usuario): array {
            // Los dos bloqueados en orden: un componente no queda instalado en dos equipos a la vez.
            $this->activos->bloquearEnOrden([$equipoId, $componenteId]);
            $equipo = $this->activos->fila($equipoId);
            $comp = $this->activos->fila($componenteId);
            if (null === $equipo || null === $comp) {
                throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
            }
            if ($componenteId === $equipoId) {
                throw ErrorDominio::invalido('COMPONENT_CANNOT_BE_ITSELF');
            }
            // Una impresora no lleva RAM: solo los componentes que admite el tipo de equipo.
            $this->reglas->exigirComponenteValido($equipo, $comp);
            if (null !== $comp['ACT_Activo_Padre']) {
                throw ErrorDominio::conflicto('COMPONENT_ALREADY_INSTALLED');
            }
            // Sin ciclos: el equipo no puede estar instalado (directa o indirectamente) en el componente.
            $padre = $equipo['ACT_Activo_Padre'];
            while (null !== $padre && false !== $padre) {
                if ((string) $padre === $componenteId) {
                    throw ErrorDominio::invalido('COMPONENT_CYCLE');
                }
                $padre = $this->datos->padreDe($padre);
            }
            foreach ([$comp, $equipo] as $a) {
                if (EstadosActivo::BAJA === $this->estados->canonicoDeId((int) $a['EOP_Estado_Operativo'])) {
                    throw ErrorDominio::invalido('ASSET_DECOMMISSIONED');
                }
            }
            if (null !== $this->activos->movimientoVigente($componenteId)) {
                throw ErrorDominio::invalido('COMPONENT_IS_ASSIGNED_RETURN_IT_FIRST');
            }
            if ($this->activos->mantenimientoAbierto($componenteId)) {
                throw ErrorDominio::conflicto('ASSET_IN_MAINTENANCE');
            }
            if (null !== $comp['SED_Sede'] && null !== $equipo['SED_Sede'] && (int) $comp['SED_Sede'] !== (int) $equipo['SED_Sede']) {
                throw ErrorDominio::invalido('COMPONENT_IN_OTHER_SEDE');
            }
            $this->activos->escribirCambios($componenteId, ['ACT_Activo_Padre' => $equipoId, 'SED_Sede' => $equipo['SED_Sede'],
                'ARE_Area_Ubicacion' => $equipo['ARE_Area_Ubicacion']]);
            $sede = null === $equipo['SED_Sede'] ? null : (int) $equipo['SED_Sede'];
            $this->kardex->registrar('COMPONENTE_INSTALADO', activo: $comp, usuario: $usuario, motivo: $motivo,
                destino: ['activo' => $equipoId, 'sede' => $sede, 'area' => null === $equipo['ARE_Area_Ubicacion'] ? null : (int) $equipo['ARE_Area_Ubicacion']],
                detalle: ['equipo' => $equipo['ACT_Codigo_Interno']]);
            $this->gobierno->registrarAuditoria('COMPONENT_INSTALL', 'INV_ACTIVO',
                ['equipo' => $equipo['ACT_Codigo_Interno'], 'componente' => $comp['ACT_Codigo_Interno'], 'motivo' => $motivo], $usuario, sedeId: $sede);

            return $this->componentes($equipoId);
        });
    }

    /** @return array<string, mixed> */
    public function retirarComponente(string $equipoId, string $componenteId, ?string $motivo, string $estadoDestino, string $usuario): array
    {
        return $this->transaccion->ejecutar(function () use ($equipoId, $componenteId, $motivo, $estadoDestino, $usuario): array {
            $this->activos->bloquearEnOrden([$equipoId, $componenteId]);
            $equipo = $this->activos->fila($equipoId);
            $comp = $this->activos->fila($componenteId);
            if (null === $equipo || null === $comp) {
                throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
            }
            if ((string) $comp['ACT_Activo_Padre'] !== $equipoId) {
                throw ErrorDominio::invalido('COMPONENT_NOT_INSTALLED_IN_ASSET');
            }
            $this->retirar($equipo, $comp, $motivo, $estadoDestino, $usuario);
            $this->gobierno->registrarAuditoria('COMPONENT_REMOVE', 'INV_ACTIVO',
                ['equipo' => $equipo['ACT_Codigo_Interno'], 'componente' => $comp['ACT_Codigo_Interno'], 'motivo' => $motivo], $usuario,
                sedeId: null === $equipo['SED_Sede'] ? null : (int) $equipo['SED_Sede']);

            return $this->componentes($equipoId);
        });
    }

    /**
     * @param array<string, mixed> $equipo
     * @param array<string, mixed> $comp
     */
    public function retirar(array $equipo, array $comp, ?string $motivo, string $estadoDestino, string $usuario): void
    {
        $cambios = ['ACT_Activo_Padre' => null];
        $estado = $this->estados->idDe($estadoDestino);
        if (null !== $estado) {
            $cambios['EOP_Estado_Operativo'] = $estado;
        }
        $this->activos->escribirCambios((string) $comp['ACT_Activo'], $cambios);
        $this->kardex->registrar('COMPONENTE_RETIRADO', activo: $comp, usuario: $usuario, motivo: $motivo,
            origen: ['activo' => (string) $equipo['ACT_Activo'], 'sede' => null === $equipo['SED_Sede'] ? null : (int) $equipo['SED_Sede']],
            destino: ['sede' => null === $comp['SED_Sede'] ? null : (int) $comp['SED_Sede']],
            detalle: ['equipo' => $equipo['ACT_Codigo_Interno'], 'estado_destino' => $estadoDestino]);
    }
}
