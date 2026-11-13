<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Catalogo\CatalogoGenerico;
use App\Crud\Esquema;
use App\Db\LogicalDelete;
use App\Db\RecordInUse;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Inventario\Infrastructure\CredencialesStore;
use App\Inventario\Infrastructure\DatosActivos;
use App\Inventario\Infrastructure\Kardex;
use App\Inventario\Infrastructure\SoftwareStore;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\CifradoCampos;

/**
 * Licencias de software: alta, cambio, eliminación, sus claves y los cupos disponibles. Las claves se
 * guardan cifradas (Fernet) con huella HMAC para la unicidad y nunca salen completas en un listado.
 */
final readonly class Licencias
{
    public function __construct(
        private SoftwareStore $datos,
        private CredencialesStore $claves,
        private DatosActivos $activos,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
        private CatalogoGenerico $catalogos,
        private CifradoCampos $cifrado,
        private Credenciales $credenciales,
        private Transaccion $transaccion,
        private LogicalDelete $borrado,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function filaLicencia(int $id, bool $bloquear = false): ?array
    {
        $f = $this->datos->licencia($id, $bloquear);

        return false === $f ? null : $f;
    }

    /** @return array<string, mixed> */
    public function licenciaO404(int $id, bool $bloquear = false): array
    {
        return $this->filaLicencia($id, $bloquear) ?? throw ErrorDominio::noEncontrado('LICENSE_NOT_FOUND');
    }

    /**
     * LicenciaResponse: nunca la key (solo se revela una por una, auditado).
     *
     * @param array<string, mixed> $l
     *
     * @return array<string, mixed>
     */
    public function licenciaAApi(array $l): array
    {
        $estados = $this->datos->clavesPorEstado((int) $l['LIC_Licencia']);
        $total = array_sum(array_map('intval', $estados));
        $usada = (int) $l['LIC_Cantidad_Usada'];
        if (0 === $total && null !== $l['LIC_Clave_Activacion'] && '' !== $l['LIC_Clave_Activacion']) {
            $conteos = ['total' => 1, 'disponibles' => 0 !== $usada ? 0 : 1, 'asignadas' => 0 !== $usada ? 1 : 0];
        } else {
            $conteos = ['total' => $total, 'disponibles' => (int) ($estados['DISPONIBLE'] ?? 0), 'asignadas' => (int) ($estados['ASIGNADA'] ?? 0)];
        }
        $fecha = static fn (mixed $v): ?string => null === $v ? null : substr((string) $v, 0, 10);
        $entero = static fn (mixed $v): ?int => null === $v ? null : (int) $v;

        return [
            'LIC_Licencia' => (int) $l['LIC_Licencia'], 'LIC_Clave_Activacion' => null, 'LIC_Fecha_Vencimiento' => $fecha($l['LIC_Fecha_Vencimiento']),
            'LIC_Cantidad_Total' => (int) $l['LIC_Cantidad_Total'], 'LIC_Cantidad_Usada' => $usada, 'SOF_Software' => (int) $l['SOF_Software'],
            'TLI_Tipo_Licencia' => $entero($l['TLI_Tipo_Licencia']),
            'LIC_Costo' => null === $l['LIC_Costo'] ? null : Esquema::decimal((string) $l['LIC_Costo'], 2), 'LIC_Moneda' => $l['LIC_Moneda'],
            'MCO_Modelo_Comercial' => $entero($l['MCO_Modelo_Comercial']), 'MAC_Metodo_Activacion' => $entero($l['MAC_Metodo_Activacion']),
            'FCO_Forma_Conteo' => $entero($l['FCO_Forma_Conteo']), 'LIC_Proveedor' => $entero($l['LIC_Proveedor']),
            'LIC_Fecha_Inicio' => $fecha($l['LIC_Fecha_Inicio']), 'LIC_Renovacion_Automatica' => 1 === (int) $l['LIC_Renovacion_Automatica'],
            'LIC_Notas' => $l['LIC_Notas'],
            'LIC_Claves_Total' => $conteos['total'], 'LIC_Claves_Disponibles' => $conteos['disponibles'], 'LIC_Claves_Asignadas' => $conteos['asignadas'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function licencias(int $software): array
    {
        return array_map($this->licenciaAApi(...), $this->datos->licenciasDe($software));
    }

    /** @return array<string, mixed> */
    public function licencia(int $id): array
    {
        return $this->licenciaAApi($this->licenciaO404($id));
    }

    /**
     * Modelo comercial, método y forma de conteo vigentes; proveedor existente.
     *
     * @param array<string, mixed> $d
     */
    private function validarCatalogos(array $d): void
    {
        foreach (['MCO_Modelo_Comercial' => 'modelos-comerciales', 'MAC_Metodo_Activacion' => 'metodos-activacion', 'FCO_Forma_Conteo' => 'formas-conteo'] as $campo => $clave) {
            if (null !== ($d[$campo] ?? null)) {
                $this->catalogos->nombreVigente($clave, (int) $d[$campo]);
            }
        }
        if (null !== ($d['LIC_Proveedor'] ?? null) && !$this->activos->existeProveedor($d['LIC_Proveedor'])) {
            throw ErrorDominio::noEncontrado('SUPPLIER_NOT_FOUND');
        }
    }

    /**
     * Alta dentro de la transacción en curso (también la usa la recepción de compras).
     *
     * @param array<string, mixed>                                              $d            columnas de INV_LICENCIA (clave en claro)
     * @param list<array{LCL_Clave_Activacion: string, LCL_Referencia: ?string}> $claves
     * @param list<array<string, mixed>>                                        $credenciales
     * @param array{0: string, 1: ?string}|null                                 $documento
     *
     * @return int id de la licencia
     */
    public function crearLicenciaEnTransaccion(array $d, array $claves, array $credenciales, ?string $usuario, ?string $ip,
        ?string $origen = null, ?array $documento = null, ?int $sede = null): int
    {
        $sw = $this->datos->software($d['SOF_Software']) ?: throw ErrorDominio::noEncontrado('SOFTWARE_NOT_FOUND');
        if (null !== ($d['TLI_Tipo_Licencia'] ?? null) && false === $this->datos->tipoDeLicencia((int) $d['TLI_Tipo_Licencia'])) {
            throw ErrorDominio::noEncontrado('LICENSE_TYPE_NOT_FOUND');
        }
        $this->validarCatalogos($d);
        if (null !== ($d['LIC_Fecha_Vencimiento'] ?? null) && $d['LIC_Fecha_Vencimiento'] < Reloj::ahora()->format('Y-m-d')) {
            throw ErrorDominio::invalido('LICENSE_EXPIRATION_IN_THE_PAST');
        }
        if ([] === $claves && null !== ($d['LIC_Clave_Activacion'] ?? null) && '' !== $d['LIC_Clave_Activacion'] && 1 === (int) $d['LIC_Cantidad_Total']) {
            $claves = [['LCL_Clave_Activacion' => (string) $d['LIC_Clave_Activacion'], 'LCL_Referencia' => null]];
        }
        $huellas = array_map(fn (array $k): string => $this->cifrado->huella($k['LCL_Clave_Activacion']), $claves);
        if (\count($huellas) !== \count(array_unique($huellas))) {
            throw ErrorDominio::invalido('DUPLICATE_LICENSE_KEY_IN_REQUEST');
        }
        if ([] !== $huellas && $this->hayHuellas($huellas)) {
            throw ErrorDominio::conflicto('LICENSE_KEY_ALREADY_EXISTS');
        }
        $id = $this->datos->crearLicencia([
            'LIC_Clave_Activacion' => $this->cifrado->cifrar($d['LIC_Clave_Activacion'] ?? null), 'LIC_Cantidad_Total' => $d['LIC_Cantidad_Total'],
            'LIC_Cantidad_Usada' => 0, 'LIC_Costo' => $d['LIC_Costo'] ?? null, 'LIC_Moneda' => $d['LIC_Moneda'] ?? null,
            'MCO_Modelo_Comercial' => $d['MCO_Modelo_Comercial'] ?? null, 'MAC_Metodo_Activacion' => $d['MAC_Metodo_Activacion'] ?? null,
            'FCO_Forma_Conteo' => $d['FCO_Forma_Conteo'] ?? null, 'LIC_Proveedor' => $d['LIC_Proveedor'] ?? null,
            'LIC_Renovacion_Automatica' => ($d['LIC_Renovacion_Automatica'] ?? false) ? 1 : 0, 'LIC_Notas' => $d['LIC_Notas'] ?? null,
            'SOF_Software' => $d['SOF_Software'], 'TLI_Tipo_Licencia' => $d['TLI_Tipo_Licencia'] ?? null,
        ], $d['LIC_Fecha_Vencimiento'] ?? null, $d['LIC_Fecha_Inicio'] ?? null);
        foreach ($claves as $i => $k) {
            $this->datos->agregarClave($id, $this->cifrado->cifrar($k['LCL_Clave_Activacion']), $huellas[$i], $k['LCL_Referencia'] ?? null);
        }
        if ([] !== $credenciales) {
            // Credenciales según el método (cuentas, seriales…): quedan en el mismo evento de alta.
            $this->credenciales->crear($id, $credenciales, $usuario, $ip, registrarKardex: false);
        }
        $this->gobierno->registrarAuditoria('CREATE', 'INV_LICENCIA', ['software_id' => (int) $d['SOF_Software'],
            'cantidad_total' => (int) $d['LIC_Cantidad_Total'], 'claves_individuales' => \count($claves), 'credenciales' => \count($credenciales),
            'origen' => $origen], $usuario, $ip);
        $this->kardex->registrar('LICENCIA_ALTA', licencia: $id, referencia: Software::referenciaSoftware($sw), cantidad: (int) $d['LIC_Cantidad_Total'],
            saldo: 0, costo: $d['LIC_Costo'] ?? null, moneda: $d['LIC_Moneda'] ?? null, documento: $documento, motivo: $origen, usuario: $usuario, sede: $sede,
            detalle: ['claves_individuales' => \count($claves) + \count($credenciales), 'vencimiento' => $d['LIC_Fecha_Vencimiento'] ?? null]);

        return $id;
    }

    /**
     * Adds individual keys to a license created without them (goods receipt).
     *
     * @param list<string> $keys
     */
    public function addKeys(int $license, array $keys, string $user, ?string $ip): void
    {
        if ([] === $keys) {
            return;
        }
        $hashes = array_map(fn (string $key): string => $this->cifrado->huella($key), $keys);
        if (\count($hashes) !== \count(array_unique($hashes))) {
            throw ErrorDominio::invalido('DUPLICATE_LICENSE_KEY_IN_REQUEST');
        }
        if ($this->hayHuellas($hashes)) {
            throw ErrorDominio::conflicto('LICENSE_KEY_ALREADY_EXISTS');
        }
        foreach ($keys as $i => $key) {
            $this->datos->agregarClave($license, $this->cifrado->cifrar($key), $hashes[$i]);
        }
        $this->gobierno->registrarAuditoria('CREATE', 'INV_LICENCIA_CLAVE', ['licencia_id' => $license, 'claves' => \count($keys)], $user, $ip);
    }

    /** @param list<string> $huellas */
    public function hayHuellas(array $huellas, ?int $excepto = null): bool
    {
        return $this->claves->existeHuella($huellas, $excepto);
    }

    /**
     * @param array<string, mixed>       $d
     * @param list<array<string, mixed>> $claves
     * @param list<array<string, mixed>> $credenciales
     *
     * @return array<string, mixed>
     */
    public function crearLicencia(array $d, array $claves, array $credenciales, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(fn (): array => $this->licencia(
            $this->crearLicenciaEnTransaccion($d, $claves, $credenciales, $usuario, $ip)));
    }

    /**
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    public function cambiarLicencia(int $id, array $cambios, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $cambios, $usuario, $ip): array {
            $l = $this->licenciaO404($id, true);
            $this->validarCatalogos($cambios);
            // El método decide qué guarda cada credencial: no cambia si ya hay credenciales.
            if (null !== ($cambios['MAC_Metodo_Activacion'] ?? null) && (int) $cambios['MAC_Metodo_Activacion'] !== (int) $l['MAC_Metodo_Activacion']
                && $this->datos->tieneClaves($id)) {
                throw ErrorDominio::conflicto('LICENSE_METHOD_LOCKED');
            }
            if (null !== ($cambios['LIC_Cantidad_Total'] ?? null) && $cambios['LIC_Cantidad_Total'] < (int) $l['LIC_Cantidad_Usada']) {
                throw ErrorDominio::invalido('CANNOT_REDUCE_BELOW_USED:'.(int) $l['LIC_Cantidad_Usada']);
            }
            $snapshot = $cambios;
            unset($snapshot['LIC_Clave_Activacion']);
            if (null !== ($cambios['LIC_Clave_Activacion'] ?? null)) {
                $cambios['LIC_Clave_Activacion'] = $this->cifrado->cifrar($cambios['LIC_Clave_Activacion']);
            }
            $this->datos->actualizar('INV_LICENCIA', 'LIC_Licencia', $id, $cambios, ['LIC_Fecha_Vencimiento', 'LIC_Fecha_Inicio']);
            $antes = (int) $l['LIC_Cantidad_Total'];
            if (null !== ($cambios['LIC_Cantidad_Total'] ?? null) && $cambios['LIC_Cantidad_Total'] !== $antes) {
                $this->kardex->registrar('LICENCIA_AJUSTE', licencia: $id, referencia: $this->referenciaLicencia($id),
                    cantidad: $cambios['LIC_Cantidad_Total'] - $antes, saldo: (int) $l['LIC_Cantidad_Usada'], usuario: $usuario,
                    detalle: ['total_antes' => $antes, 'total_despues' => $cambios['LIC_Cantidad_Total']]);
            }
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_LICENCIA', ['id' => $id, 'cambios' => (object) $snapshot], $usuario, $ip);

            return $this->licencia($id);
        });
    }

    public function eliminarLicencia(int $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $l = $this->licenciaO404($id, true);
            $activas = $this->datos->instalacionesActivasDe($id);
            if ($activas > 0) {
                throw ErrorDominio::conflicto("CANNOT_DELETE_LICENSE_WITH_ACTIVE_INSTALLATIONS:{$activas}");
            }
            $sw = $this->datos->software($l['SOF_Software']);
            // Salida de la licencia del inventario (el kardex conserva su historia).
            $this->kardex->registrar('LICENCIA_BAJA', licencia: $id, referencia: Software::referenciaSoftware($sw ?: null), cantidad: (int) $l['LIC_Cantidad_Total'],
                saldo: 0, costo: $l['LIC_Costo'], moneda: $l['LIC_Moneda'], motivo: 'Licencia eliminada del inventario', usuario: $usuario);
            try {
                // Se archiva con sus claves y adjuntos; el historial de instalaciones lo impide.
                $this->borrado->archive('INV_LICENCIA', 'LIC_Licencia', $id);
            } catch (RecordInUse) {
                throw ErrorDominio::conflicto('CANNOT_DELETE_LICENSE_IN_USE');
            }
            $this->gobierno->registrarAuditoria('DELETE', 'INV_LICENCIA', ['id' => $id, 'software_id' => (int) $l['SOF_Software'],
                'total' => (int) $l['LIC_Cantidad_Total']], $usuario, $ip);
        });
    }

    /**
     * Claves de la licencia, enmascaradas, con su destino actual (consultarlas queda en la bitácora).
     *
     * @return list<array<string, mixed>>
     */
    public function claves(int $id, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): array {
            $l = $this->licenciaO404($id);
            $filas = $this->datos->clavesConDestino($id);
            $salida = [];
            foreach ($filas as $f) {
                [$tipo, $destino, $nombre, $detalle] = [null, null, null, null];
                if (null !== $f['ACT_Activo'] && null !== $f['ACT_Codigo_Interno']) {
                    [$tipo, $destino, $nombre] = ['ACTIVO', Ident::aTexto((string) $f['ACT_Activo']), $f['ACT_Codigo_Interno']];
                    $detalle = implode(' / ', array_filter([$f['ACT_Hostname'], $f['ACT_Serie_Fabricante']])) ?: null;
                } elseif (null !== $f['PER_Persona'] && null !== $f['PER_Primer_Nombre']) {
                    [$tipo, $destino] = ['PERSONA', Ident::aTexto((string) $f['PER_Persona'])];
                    $nombre = implode(' ', array_filter([$f['PER_Primer_Nombre'], $f['PER_Segundo_Nombre'], $f['PER_Primer_Apellido'], $f['PER_Segundo_Apellido']]));
                    $detalle = $f['PER_Email_Corporativo'];
                }
                $salida[] = ['LCL_Licencia_Clave' => (int) $f['LCL_Licencia_Clave'], 'LIC_Licencia' => (int) $f['LIC_Licencia'],
                    // Nunca la key completa en un listado: se revela una por una (auditado).
                    'LCL_Clave_Activacion' => $this->credenciales->mascara($f['LCL_Clave_Activacion']) ?? '', 'LCL_Referencia' => $f['LCL_Referencia'],
                    'LCL_Estado' => $f['LCL_Estado'], 'LCL_Creado_En' => Reloj::iso(Reloj::desdeBd($f['CREATED_AT'])),
                    'LCL_Asignada_En' => null === $f['INS_Fecha_Instalacion'] ? null : substr((string) $f['INS_Fecha_Instalacion'], 0, 10),
                    'LCL_Destino_Tipo' => $tipo, 'LCL_Destino_Id' => $destino, 'LCL_Destino_Nombre' => $nombre, 'LCL_Destino_Detalle' => $detalle];
            }
            if ([] === $salida && null !== $l['LIC_Clave_Activacion'] && '' !== $l['LIC_Clave_Activacion']) {
                $legada = $this->credenciales->mascara($l['LIC_Clave_Activacion']) ?? '';
                if ('' !== $legada) {
                    $salida[] = ['LCL_Licencia_Clave' => -$id, 'LIC_Licencia' => $id, 'LCL_Clave_Activacion' => $legada, 'LCL_Referencia' => 'Clave legada',
                        'LCL_Estado' => 0 !== (int) $l['LIC_Cantidad_Usada'] ? 'ASIGNADA' : 'DISPONIBLE', 'LCL_Creado_En' => null, 'LCL_Asignada_En' => null,
                        'LCL_Destino_Tipo' => null, 'LCL_Destino_Id' => null, 'LCL_Destino_Nombre' => null, 'LCL_Destino_Detalle' => null];
                }
            }
            $this->gobierno->registrarAuditoria('VIEW_KEYS', 'INV_LICENCIA_CLAVE', ['licencia_id' => $id, 'claves_consultadas' => \count($salida)], $usuario, $ip);

            return $salida;
        });
    }

    /**
     * Licencias asignables (con cupo libre o por sitio), filtradas por forma de conteo.
     *
     * @return list<array<string, mixed>>
     */
    public function disponibles(?string $forma, bool $todas): array
    {
        $hoy = Reloj::ahora()->format('Y-m-d');
        $salida = [];
        foreach ($this->datos->licenciasConSoftware($forma) as $f) {
            $libres = 'SITIO' === $f['FCO_Codigo'] ? null : (int) $f['LIC_Cantidad_Total'] - (int) $f['LIC_Cantidad_Usada'];
            $vence = null === $f['LIC_Fecha_Vencimiento'] ? null : substr((string) $f['LIC_Fecha_Vencimiento'], 0, 10);
            // `todas`: para configurar (kits) se listan también las sin cupo o vencidas.
            if (!$todas && ((null !== $libres && $libres <= 0) || (null !== $vence && $vence < $hoy))) {
                continue;
            }
            $salida[] = ['LIC_Licencia' => (int) $f['LIC_Licencia'], 'SOF_Software' => (int) $f['SOF_Software'], 'software' => Software::referenciaSoftware($f),
                'forma' => $f['FCO_Codigo'], 'metodo' => $f['MAC_Codigo'], 'total' => (int) $f['LIC_Cantidad_Total'],
                'usadas' => (int) $f['LIC_Cantidad_Usada'], 'libres' => $libres, 'vence' => $vence];
        }

        return $salida;
    }

    public function referenciaLicencia(int $licencia): string
    {
        $sw = $this->claves->softwareDeLicencia($licencia);

        return false === $sw ? "Licencia #{$licencia}" : Software::referenciaSoftware($sw)." · licencia #{$licencia}";
    }
}
