<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Inventario\Infrastructure\Kardex;
use App\Inventario\Infrastructure\SoftwareStore;
use App\Repositorio\GobiernoRepositorio;

/**
 * Instalaciones: la asignación de una licencia a un equipo o a una persona. Los cupos y las claves se
 * reservan con una actualización condicional atómica: dos asignaciones simultáneas nunca exceden lo comprado.
 */
final readonly class InstalacionesDeSoftware
{
    public function __construct(
        private SoftwareStore $datos,
        private Licencias $licencias,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
        private ReglasClase $reglas,
        private Transaccion $transaccion,
    ) {
    }

    /** @return list<array<string, mixed>> InstalacionDetalleResponse */
    public function instalacionesDe(string $columna, string $hex, bool $soloActivas): array
    {
        $filas = $this->datos->instalacionesDe($columna, $hex, $soloActivas);

        return array_map(static fn (array $f): array => [...self::instalacionAApi($f), 'licencia' => [
            'LIC_Licencia' => (int) $f['LIC_Licencia'],
            'LIC_Fecha_Vencimiento' => null === $f['LIC_Fecha_Vencimiento'] ? null : substr((string) $f['LIC_Fecha_Vencimiento'], 0, 10),
            'LIC_Cantidad_Total' => (int) $f['LIC_Cantidad_Total'], 'LIC_Cantidad_Usada' => (int) $f['LIC_Cantidad_Usada'],
            'software' => null === $f['SOF_Software'] ? null : ['SOF_Software' => (int) $f['SOF_Software'], 'SOF_Nombre' => $f['SOF_Nombre'],
                'SOF_Version' => $f['SOF_Version'], 'SOF_Fabricante' => $f['SOF_Fabricante']],
            'tipo_licencia' => null === $f['TLI_Tipo_Licencia'] ? null : ['TLI_Tipo_Licencia' => (int) $f['TLI_Tipo_Licencia'], 'TLI_Nombre' => $f['TLI_Nombre']],
        ]], $filas);
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private static function instalacionAApi(array $f): array
    {
        $uuid = static fn (mixed $v): ?string => null === $v ? null : Ident::aTexto((string) $v);

        return ['ACT_Activo' => $uuid($f['ACT_Activo']), 'PER_Persona' => $uuid($f['PER_Persona']), 'LIC_Licencia' => (int) $f['LIC_Licencia'],
            'LCL_Licencia_Clave' => null === $f['LCL_Licencia_Clave'] ? null : (int) $f['LCL_Licencia_Clave'],
            'INS_Fecha_Instalacion' => substr((string) $f['INS_Fecha_Instalacion'], 0, 10), 'INS_Instalacion' => (int) $f['INS_Instalacion'],
            'INS_Estado' => 1 === (int) $f['INS_Estado'], 'INS_Fecha_Retiro' => Reloj::iso(Reloj::desdeBd($f['INS_Fecha_Retiro'])),
            'INS_Motivo_Retiro' => $f['INS_Motivo_Retiro']];
    }

    /** @return array<string, mixed>|null */
    private function instalacionActiva(int $licencia, ?string $activo, ?string $persona): ?array
    {
        $f = $this->datos->instalacionActiva($licencia, $activo, $persona);

        return false === $f ? null : $f;
    }

    /**
     * Asigna una licencia a un equipo o a una persona: reserva el cupo (y una
     * clave, salvo concurrente o por sitio) con UPDATE condicional atómico.
     *
     * @return array<string, mixed> InstalacionResponse
     */
    public function instalar(int $licencia, ?string $activo, ?string $persona, ?int $clave, string $fecha, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($licencia, $activo, $persona, $clave, $fecha, $usuario, $ip): array {
            if (null !== $activo) {
                if (!$this->datos->existeActivo($activo)) {
                    throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
                }
                // Software y licencias por equipo: solo en equipos que lo llevan (no en un monitor).
                $this->reglas->exigirSoftware($activo);
            }
            if (null !== $persona) {
                $estado = $this->datos->estadoDePersona($persona);
                if (false === $estado) {
                    throw ErrorDominio::noEncontrado('PERSON_NOT_FOUND');
                }
                if (1 !== (int) $estado) {
                    throw ErrorDominio::invalido('PERSON_INACTIVE');
                }
            }
            if (null !== $this->instalacionActiva($licencia, $activo, $persona)) {
                throw ErrorDominio::invalido('LICENSE_ALREADY_ASSIGNED_TO_TARGET');
            }
            $l = $this->licencias->licenciaO404($licencia);
            $forma = $l['FCO_Codigo'];
            if ('EQUIPO' === $forma && null === $activo) {
                throw ErrorDominio::invalido('LICENSE_COUNTS_BY_DEVICE');
            }
            if ('PERSONA' === $forma && null === $persona) {
                throw ErrorDominio::invalido('LICENSE_COUNTS_BY_PERSON');
            }
            // Concurrente comparte la credencial; por sitio registra el uso sin descontar cupos.
            $reservaCredencial = !\in_array($forma, ['CONCURRENTE', 'SITIO'], true);
            $consumeCupo = 'SITIO' !== $forma;
            $reservada = $reservaCredencial ? $clave : null;
            if (null !== $reservada) {
                $propia = $this->datos->licenciaDeClave($reservada);
                if (false === $propia || (int) $propia !== $licencia) {
                    throw ErrorDominio::noEncontrado('LICENSE_KEY_NOT_FOUND');
                }
                if (!$this->datos->reservarClave($licencia, $reservada)) {
                    throw ErrorDominio::conflicto('LICENSE_KEY_NOT_AVAILABLE');
                }
            } elseif ($reservaCredencial && $this->datos->tieneClaves($licencia)) {
                foreach ($this->datos->clavesAsignables($licencia) as $candidata) {
                    if ($this->datos->reservarClave($licencia, $candidata)) {
                        $reservada = $candidata;
                        break;
                    }
                }
                if (null === $reservada) {
                    throw ErrorDominio::conflicto('NO_LICENSE_KEYS_AVAILABLE');
                }
            }
            if ($consumeCupo && !$this->datos->tomarCupo($licencia)) {
                throw ErrorDominio::conflicto('NO_LICENSE_SEATS_AVAILABLE');
            }
            $id = $this->datos->crearInstalacion(['INS_Fecha_Instalacion' => $fecha, 'INS_Estado' => 1, 'INS_Consume_Cupo' => $consumeCupo ? 1 : 0,
                'ACT_Activo' => $activo, 'PER_Persona' => $persona, 'LIC_Licencia' => $licencia, 'LCL_Licencia_Clave' => $reservada]);
            $usada = (int) $this->datos->cuposUsados($licencia);
            $this->kardex->registrar('LICENCIA_ASIGNADA', licencia: $licencia, clave: $reservada, referencia: $this->licencias->referenciaLicencia($licencia),
                destino: ['persona' => $persona, 'activo' => $activo, 'sede' => $this->sedeDestino($activo, $persona)], cantidad: 1, saldo: $usada, usuario: $usuario);
            $this->gobierno->registrarAuditoria('INSTALL', 'INV_INSTALACION', ['activo' => null === $activo ? null : Ident::aTexto($activo),
                'persona' => null === $persona ? null : Ident::aTexto($persona), 'licencia' => $licencia, 'licencia_clave' => $reservada], $usuario, $ip);

            return self::instalacionAApi((array) $this->datos->instalacion($id));
        });
    }

    /**
     * Libera el cupo (UPDATE condicional: una doble desinstalación no descuenta dos veces).
     *
     * @return array<string, string>
     */
    public function desinstalar(int $licencia, ?string $activo, ?string $persona, ?string $motivo, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($licencia, $activo, $persona, $motivo, $usuario, $ip): array {
            $actual = $this->instalacionActiva($licencia, $activo, $persona) ?? throw ErrorDominio::noEncontrado('INSTALLATION_NOT_FOUND_OR_ALREADY_REMOVED');
            if (!$this->datos->retirarInstalacion((int) $actual['INS_Instalacion'], Reloj::ahora(), null === $motivo || '' === $motivo ? null : mb_substr($motivo, 0, 255))) {
                throw ErrorDominio::noEncontrado('INSTALLATION_NOT_FOUND_OR_ALREADY_REMOVED');
            }
            if (1 === (int) $actual['INS_Consume_Cupo']) {
                $this->datos->devolverCupo($licencia);
            }
            $clave = null === $actual['LCL_Licencia_Clave'] ? null : (int) $actual['LCL_Licencia_Clave'];
            if (null !== $clave) {
                $this->datos->liberarClave($clave);
            }
            $usada = $this->datos->cuposUsados($licencia);
            $this->kardex->registrar('LICENCIA_RETIRADA', licencia: $licencia, clave: $clave, referencia: $this->licencias->referenciaLicencia($licencia),
                origen: ['persona' => $persona, 'activo' => $activo, 'sede' => $this->sedeDestino($activo, $persona)], cantidad: 1,
                saldo: false === $usada ? null : (int) $usada, motivo: $motivo, usuario: $usuario);
            $this->gobierno->registrarAuditoria('UNINSTALL', 'INV_INSTALACION', ['activo' => null === $activo ? null : Ident::aTexto($activo),
                'persona' => null === $persona ? null : Ident::aTexto($persona), 'licencia' => $licencia, 'licencia_clave' => $clave], $usuario, $ip);

            return ['status' => 'success', 'message' => 'SOFTWARE_UNINSTALLED_SUCCESSFULLY'];
        });
    }

    /**
     * Asignaciones de la licencia, vigentes y retiradas.
     *
     * @return list<array<string, mixed>>
     */
    public function historial(int $licencia): array
    {
        $this->licencias->licenciaO404($licencia);

        return array_map(static fn (array $f): array => ['INS_Instalacion' => (int) $f['INS_Instalacion'],
            'destino' => null !== $f['ACT_Activo'] ? 'ACTIVO' : 'PERSONA',
            'ACT_Activo' => null === $f['ACT_Activo'] ? null : Ident::aTexto((string) $f['ACT_Activo']), 'activo_codigo' => $f['ACT_Codigo_Interno'],
            'PER_Persona' => null === $f['PER_Persona'] ? null : Ident::aTexto((string) $f['PER_Persona']),
            'persona_nombre' => null === $f['PER_Primer_Nombre'] ? null : $f['PER_Primer_Nombre'].' '.$f['PER_Primer_Apellido'],
            'LCL_Licencia_Clave' => null === $f['LCL_Licencia_Clave'] ? null : (int) $f['LCL_Licencia_Clave'],
            'INS_Fecha_Instalacion' => substr((string) $f['INS_Fecha_Instalacion'], 0, 10), 'INS_Estado' => 1 === (int) $f['INS_Estado'],
            'INS_Fecha_Retiro' => Reloj::iso(Reloj::desdeBd($f['INS_Fecha_Retiro'])), 'INS_Motivo_Retiro' => $f['INS_Motivo_Retiro']],
            $this->datos->historialDe($licencia));
    }

    private function sedeDestino(?string $activo, ?string $persona): ?int
    {
        return $this->datos->sedeDeDestino($activo, $persona);
    }
}
