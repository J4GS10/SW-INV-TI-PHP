<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Inventario\Infrastructure\ActivosStore;
use App\Inventario\Infrastructure\Kardex;
use App\Inventario\Infrastructure\MovimientosStore;
use App\Organizacion\Infrastructure\CuentasStore;
use App\Repositorio\GobiernoRepositorio;

/** Equipos compartidos: se instalan en un área (con un responsable opcional) en vez de asignarse a una persona, y se retiran de ella. */
final readonly class InstalacionEnArea
{
    public function __construct(
        private ReglasCustodia $custodia,
        private MovimientosStore $datos,
        private ActivosStore $activos,
        private CuentasStore $cuentas,
        private Kardex $kardex,
        private ReglasClase $reglas,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
    ) {
    }

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
            $activo = $this->custodia->activo($d['ACT_Activo']) ?? throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
            $estado = $this->custodia->estadoDe($activo);
            $this->reglas->exigirInstalableEnArea($activo);
            if (!\in_array($estado, [EstadosActivo::BODEGA, EstadosActivo::INSTALADO], true)) {
                throw ErrorDominio::conflicto('ASSET_NOT_AVAILABLE_FOR_INSTALLATION');
            }
            EstadosActivo::validarTransicion((string) $estado, EstadosActivo::INSTALADO);
            $this->custodia->exigirOperable($activo);
            if (null !== $this->custodia->vigente($d['ACT_Activo'], false)) {
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
                ReglasCustodia::exigirPersonaActiva($responsable);
            }
            $motivo = $this->custodia->conMotivo($d, 'INSTALACION');
            $sede = $this->custodia->sedeDeDestino($area);
            $this->custodia->ubicarEnSede($activo, null, $sede, $usuario, $ip);
            $origen = ['area' => null === $activo['ARE_Area_Ubicacion'] ? null : (int) $activo['ARE_Area_Ubicacion'],
                'sede' => null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'], 'persona' => $activo['ACT_Responsable']];
            $reubicacion = EstadosActivo::INSTALADO === $estado;
            $this->activos->actualizar($d['ACT_Activo'], ['EOP_Estado_Operativo' => $this->custodia->idEstado(EstadosActivo::INSTALADO), 'ARE_Area_Ubicacion' => $area,
                'ACT_Instalado' => 1, 'ACT_Responsable' => $responsableId]);
            // Los componentes instalados en el equipo lo siguen.
            $this->datos->ubicarComponentesDe($d['ACT_Activo'], $area, $sede);
            $nombreResp = null === $responsable ? null : ReglasCustodia::nombre($responsable);
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
            $activo = $this->custodia->activo($d['ACT_Activo']) ?? throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
            if (EstadosActivo::INSTALADO !== $this->custodia->estadoDe($activo)) {
                throw ErrorDominio::conflicto('ASSET_NOT_INSTALLED');
            }
            $this->custodia->exigirOperable($activo);
            $destino = $d['ARE_Area_Destino'] ?? null;
            $sede = null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'];
            if (null !== $destino) {
                if (!$this->custodia->existeArea((int) $destino)) {
                    throw ErrorDominio::noEncontrado('AREA_NOT_FOUND');
                }
                $sede = $this->custodia->sedeDeDestino((int) $destino);
                $this->custodia->ubicarEnSede($activo, null, $sede, $usuario, $ip);
            }
            $motivo = $this->custodia->conMotivo($d, 'RETIRO');
            $origen = ['area' => null === $activo['ARE_Area_Ubicacion'] ? null : (int) $activo['ARE_Area_Ubicacion'],
                'sede' => null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'], 'persona' => $activo['ACT_Responsable']];
            $this->activos->actualizar($d['ACT_Activo'], ['EOP_Estado_Operativo' => $this->custodia->idEstado(EstadosActivo::BODEGA),
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
