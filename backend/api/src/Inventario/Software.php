<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Inventario\Infrastructure\Kardex;
use App\Inventario\Infrastructure\CredencialesStore;
use App\Inventario\Infrastructure\DatosActivos;
use App\Inventario\Infrastructure\Instalaciones;
use App\Inventario\Infrastructure\SoftwareStore;
use App\Catalogo\CatalogoGenerico;
use App\Crud\Esquema;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\CifradoCampos;

/**
 * Software, tipos y licencias, e instalaciones (asignación de una licencia a un
 * equipo o a una persona). Los cupos y las claves se reservan con UPDATE
 * condicional atómico: dos asignaciones simultáneas nunca exceden lo comprado.
 * Las claves se guardan cifradas (Fernet) con huella HMAC para la unicidad.
 */
final readonly class Software
{
    public function __construct(
        private SoftwareStore $datos,
        private CredencialesStore $claves,
        private DatosActivos $activos,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
        private CatalogoGenerico $catalogos,
        private CifradoCampos $cifrado,
        private ReglasClase $reglas,
        private Credenciales $credenciales,
        private Transaccion $transaccion,
        private \App\Db\LogicalDelete $borrado,
    ) {
    }

    // =====================================================================
    // Tipos de licencia y software
    // =====================================================================

    /** @return list<array<string, mixed>> */
    public function tipos(): array
    {
        return array_map(self::tipoAApi(...), $this->datos->tiposDeLicencia());
    }

    /** @return array<string, mixed> */
    public function tipo(int $id): array
    {
        $f = $this->datos->tipoDeLicencia($id);

        return false === $f ? throw ErrorDominio::noEncontrado('LICENSE_TYPE_NOT_FOUND') : self::tipoAApi($f);
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private static function tipoAApi(array $f): array
    {
        return ['TLI_Nombre' => $f['TLI_Nombre'], 'TLI_Descripcion' => $f['TLI_Descripcion'], 'TLI_Tipo_Licencia' => (int) $f['TLI_Tipo_Licencia']];
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public function crearTipo(array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $usuario, $ip): array {
            $id = $this->datos->crearTipoDeLicencia($d);
            $this->gobierno->registrarAuditoria('CREATE', 'INV_TIPO_LICENCIA', ['nombre' => $d['TLI_Nombre']], $usuario, $ip);

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
            $this->datos->actualizar('INV_TIPO_LICENCIA', 'TLI_Tipo_Licencia', $id, $cambios);
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_TIPO_LICENCIA', ['id' => $id, 'cambios' => (object) $cambios], $usuario, $ip);

            return $this->tipo($id);
        });
    }

    public function eliminarTipo(int $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $tipo = $this->tipo($id);
            if ($this->datos->tipoDeLicenciaEnUso($id)) {
                throw ErrorDominio::conflicto('CANNOT_DELETE_TYPE_HAS_LICENSES');
            }
            $this->borrado->archive('INV_TIPO_LICENCIA', 'TLI_Tipo_Licencia', $id);
            $this->gobierno->registrarAuditoria('DELETE', 'INV_TIPO_LICENCIA', ['id' => $id, 'nombre' => $tipo['TLI_Nombre']], $usuario, $ip);
        });
    }

    /** @return list<array<string, mixed>> */
    public function listaSoftware(): array
    {
        return array_map(self::softwareAApi(...), $this->datos->todoElSoftware());
    }

    /** @return array<string, mixed> */
    public function software(int $id): array
    {
        $f = $this->datos->software($id);

        return false === $f ? throw ErrorDominio::noEncontrado('SOFTWARE_NOT_FOUND') : self::softwareAApi($f);
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private static function softwareAApi(array $f): array
    {
        return ['SOF_Nombre' => $f['SOF_Nombre'], 'SOF_Version' => $f['SOF_Version'], 'SOF_Fabricante' => $f['SOF_Fabricante'],
            'MAR_Marca' => null === $f['MAR_Marca'] ? null : (int) $f['MAR_Marca'], 'SOF_Software' => (int) $f['SOF_Software']];
    }

    /**
     * La marca del catálogo fija el texto del fabricante; sin marca, el texto se
     * enlaza con la marca del mismo nombre si existe.
     *
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    private function fabricante(array $d, bool $creacion): array
    {
        if (null !== ($d['MAR_Marca'] ?? null)) {
            $d['SOF_Fabricante'] = $this->catalogos->nombreVigente('marcas', (int) $d['MAR_Marca']);
        } elseif (null !== ($d['SOF_Fabricante'] ?? null) && '' !== $d['SOF_Fabricante']) {
            $d['MAR_Marca'] = $this->datos->marcaActivaPorNombre((string) $d['SOF_Fabricante']);
        }
        if (($creacion || \array_key_exists('SOF_Fabricante', $d)) && mb_strlen(trim((string) ($d['SOF_Fabricante'] ?? ''))) < 2) {
            throw ErrorDominio::noProcesable('SOFTWARE_MANUFACTURER_REQUIRED');
        }

        return $d;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public function crearSoftware(array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($d, $usuario, $ip): array {
            $d = $this->fabricante($d, true);
            $id = $this->datos->crearSoftware($d);
            $this->gobierno->registrarAuditoria('CREATE', 'INV_SOFTWARE', ['nombre' => $d['SOF_Nombre'], 'version' => $d['SOF_Version']], $usuario, $ip);

            return $this->software($id);
        });
    }

    /**
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    public function cambiarSoftware(int $id, array $cambios, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $cambios, $usuario, $ip): array {
            $this->software($id);
            $snapshot = $cambios;
            $cambios = $this->fabricante($cambios, false);
            $this->datos->actualizar('INV_SOFTWARE', 'SOF_Software', $id, $cambios);
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_SOFTWARE', ['id' => $id, 'cambios' => (object) $snapshot], $usuario, $ip);

            return $this->software($id);
        });
    }

    public function eliminarSoftware(int $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $sw = $this->software($id);
            if ($this->datos->softwareTieneLicencias($id)) {
                throw ErrorDominio::conflicto('CANNOT_DELETE_SOFTWARE_HAS_LICENSES');
            }
            try {
                $this->borrado->archive('INV_SOFTWARE', 'SOF_Software', $id);
            } catch (\App\Db\RecordInUse) {
                throw ErrorDominio::conflicto('CANNOT_DELETE_SOFTWARE_HAS_LICENSES');
            }
            $this->gobierno->registrarAuditoria('DELETE', 'INV_SOFTWARE', ['id' => $id, 'nombre' => $sw['SOF_Nombre']], $usuario, $ip);
        });
    }

    // =====================================================================
    // Licencias
    // =====================================================================

    /** @return array<string, mixed>|null */
    public function filaLicencia(int $id, bool $bloquear = false): ?array
    {
        $f = $this->datos->licencia($id, $bloquear);

        return false === $f ? null : $f;
    }

    /** @return array<string, mixed> */
    private function licenciaO404(int $id, bool $bloquear = false): array
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
        $this->kardex->registrar('LICENCIA_ALTA', licencia: $id, referencia: self::referenciaSoftware($sw), cantidad: (int) $d['LIC_Cantidad_Total'],
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
            $this->kardex->registrar('LICENCIA_BAJA', licencia: $id, referencia: self::referenciaSoftware($sw ?: null), cantidad: (int) $l['LIC_Cantidad_Total'],
                saldo: 0, costo: $l['LIC_Costo'], moneda: $l['LIC_Moneda'], motivo: 'Licencia eliminada del inventario', usuario: $usuario);
            try {
                // Se archiva con sus claves y adjuntos; el historial de instalaciones lo impide.
                $this->borrado->archive('INV_LICENCIA', 'LIC_Licencia', $id);
            } catch (\App\Db\RecordInUse) {
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
            $salida[] = ['LIC_Licencia' => (int) $f['LIC_Licencia'], 'SOF_Software' => (int) $f['SOF_Software'], 'software' => self::referenciaSoftware($f),
                'forma' => $f['FCO_Codigo'], 'metodo' => $f['MAC_Codigo'], 'total' => (int) $f['LIC_Cantidad_Total'],
                'usadas' => (int) $f['LIC_Cantidad_Usada'], 'libres' => $libres, 'vence' => $vence];
        }

        return $salida;
    }

    // =====================================================================
    // Instalaciones
    // =====================================================================

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
            $l = $this->licenciaO404($licencia);
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
            $this->kardex->registrar('LICENCIA_ASIGNADA', licencia: $licencia, clave: $reservada, referencia: $this->referenciaLicencia($licencia),
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
            $this->kardex->registrar('LICENCIA_RETIRADA', licencia: $licencia, clave: $clave, referencia: $this->referenciaLicencia($licencia),
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
        $this->licenciaO404($licencia);

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

    // =====================================================================
    // Referencias
    // =====================================================================

    /** @param array<string, mixed>|null $sw */
    public static function referenciaSoftware(?array $sw): string
    {
        if (null === $sw) {
            return '—';
        }

        return mb_substr(implode(' ', array_filter([$sw['SOF_Nombre'] ?? null, $sw['SOF_Version'] ?? null])), 0, 150);
    }

    public function referenciaLicencia(int $licencia): string
    {
        $sw = $this->claves->softwareDeLicencia($licencia);

        return false === $sw ? "Licencia #{$licencia}" : self::referenciaSoftware($sw)." · licencia #{$licencia}";
    }

    private function sedeDestino(?string $activo, ?string $persona): ?int
    {
        return $this->datos->sedeDeDestino($activo, $persona);
    }
}
