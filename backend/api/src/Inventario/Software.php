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

/** Catálogo de software y de tipos de licencia: qué programas existen y bajo qué modalidades se licencian. */
final readonly class Software
{
    public function __construct(
        private SoftwareStore $datos,
        private GobiernoRepositorio $gobierno,
        private CatalogoGenerico $catalogos,
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

    /** @param array<string, mixed>|null $sw */
    public static function referenciaSoftware(?array $sw): string
    {
        if (null === $sw) {
            return '—';
        }

        return mb_substr(implode(' ', array_filter([$sw['SOF_Nombre'] ?? null, $sw['SOF_Version'] ?? null])), 0, 150);
    }
}
