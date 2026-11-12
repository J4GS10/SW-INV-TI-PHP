<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Crud\Esquema;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use Doctrine\DBAL\Connection;

/**
 * Edición en lote: ubicación (área), costo y moneda, garantía, proveedor y
 * factura, y especificaciones para varios activos. Todo en una transacción: si
 * un activo no admite el cambio, nada se aplica (BATCH_ITEM_FAILED:<código>:<motivo>).
 * La respuesta trae los valores anteriores para «Deshacer» (…/lote/restaurar).
 */
final readonly class EdicionLote
{
    public const array CAMPOS = ['ARE_Area_Ubicacion', 'ACT_Costo', 'ACT_Moneda', 'ACT_Fin_Garantia', 'PRV_Proveedor', 'ACT_Factura'];

    public function __construct(
        private Connection $conexion,
        private Activos $activos,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
        private \App\Catalogo\SpecificationRules $specificationRules,
    ) {
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $items [activo hex, cambios (solo los enviados; `especificaciones` aparte)]
     *
     * @return array{total: int, anteriores: list<array<string, mixed>>}
     */
    public function aplicar(array $items, string $usuario, ?string $ip, string $accion = 'UPDATE_BATCH'): array
    {
        return $this->transaccion->ejecutar(function () use ($items, $usuario, $ip, $accion): array {
            $this->activos->bloquearEnOrden(array_column($items, 0));
            $anteriores = [];
            $codigos = [];
            $todos = [];
            foreach ($items as [$id, $cambios]) {
                $activo = $this->activos->fila($id) ?? throw ErrorDominio::noEncontrado('BATCH_ITEM_FAILED:'.Ident::aTexto($id).':ASSET_NOT_FOUND');
                $campos = array_intersect_key($cambios, array_flip(self::CAMPOS));
                $todos = [...$todos, ...array_keys($cambios)];
                try {
                    $this->validar($activo, $campos);
                    $previos = [];
                    $diferencias = [];
                    foreach ($campos as $c => $nuevo) {
                        $previos[$c] = self::valor($c, $activo[$c]);
                        if ($previos[$c] !== $nuevo) {
                            $diferencias[$c] = [$previos[$c], $nuevo];
                        }
                    }
                    if ([] !== $campos) {
                        $this->activos->escribirCambios($id, $campos);
                    }
                    if ([] !== $diferencias) {
                        $this->kardex->registrar('CAMBIO_ESTADO', activo: $activo, usuario: $usuario, motivo: 'Edición en lote', detalle: ['campos' => $diferencias]);
                    }
                    $specs = $this->especificaciones($activo, $cambios['especificaciones'] ?? [], $usuario);
                } catch (ErrorDominio $e) {
                    throw new ErrorDominio($e->motivo, "BATCH_ITEM_FAILED:{$activo['ACT_Codigo_Interno']}:".explode(':', $e->detalle)[0]);
                }
                $codigos[] = $activo['ACT_Codigo_Interno'];
                $previo = array_intersect_key($previos, $diferencias);
                if ([] !== $specs) {
                    $previo['especificaciones'] = $specs;
                }
                $anteriores[] = ['ACT_Activo' => Ident::aTexto($id), 'codigo' => $activo['ACT_Codigo_Interno'], 'cambios' => (object) $previo];
            }
            $todos = array_values(array_unique($todos));
            sort($todos);
            $this->gobierno->registrarAuditoria($accion, 'INV_ACTIVO', ['activos' => $codigos, 'campos' => $todos], $usuario, $ip);

            return ['total' => \count($codigos), 'anteriores' => $anteriores];
        });
    }

    /** Valor actual en el formato de la API (para comparar y para «Deshacer»). */
    private static function valor(string $campo, mixed $v): mixed
    {
        return match (true) {
            null === $v => null,
            'ACT_Costo' === $campo => Esquema::decimal((string) $v, 2),
            'ACT_Fin_Garantia' === $campo => substr((string) $v, 0, 10),
            \in_array($campo, ['ARE_Area_Ubicacion', 'PRV_Proveedor'], true) => (int) $v,
            default => (string) $v,
        };
    }

    /**
     * @param array<string, mixed> $activo
     * @param array<string, mixed> $campos
     */
    private function validar(array $activo, array $campos): void
    {
        if (null !== ($campos['ARE_Area_Ubicacion'] ?? null)) {
            $area = (int) $campos['ARE_Area_Ubicacion'];
            $sede = $this->conexion->fetchOne('SELECT e."SED_Sede" FROM "INV_AREA" a JOIN "INV_NIVEL" n ON n."NIV_Nivel" = a."NIV_Nivel" '
                .'JOIN "INV_EDIFICIO" e ON e."EDI_Edificio" = n."EDI_Edificio" WHERE a."ARE_Area" = :a', ['a' => $area]);
            if (false === $sede) {
                if (false === $this->conexion->fetchOne('SELECT 1 FROM "INV_AREA" WHERE "ARE_Area" = :a', ['a' => $area])) {
                    throw ErrorDominio::noEncontrado('AREA_NOT_FOUND');
                }
                $sede = null;
            }
            if (null !== $activo['SED_Sede'] && (null === $sede ? null : (int) $sede) !== (int) $activo['SED_Sede']) {
                throw ErrorDominio::invalido('ASSET_AREA_OTHER_SITE');
            }
            // Asignado: su ubicación la da la custodia (se cambia transfiriéndolo).
            if (null !== $this->activos->movimientoVigente((string) $activo['ACT_Activo'])) {
                throw ErrorDominio::invalido('ASSET_LOCATION_FOLLOWS_CUSTODY');
            }
        }
        if (null !== ($campos['ACT_Fin_Garantia'] ?? null) && null !== $activo['ACT_Fecha_Compra']
            && $campos['ACT_Fin_Garantia'] < substr((string) $activo['ACT_Fecha_Compra'], 0, 10)) {
            throw ErrorDominio::invalido('WARRANTY_DATE_CANNOT_BE_BEFORE_PURCHASE_DATE');
        }
        if (null !== ($campos['PRV_Proveedor'] ?? null)
            && false === $this->conexion->fetchOne('SELECT 1 FROM "INV_PROVEEDOR" WHERE "PRV_Proveedor" = :p', ['p' => $campos['PRV_Proveedor']])) {
            throw ErrorDominio::noEncontrado('SUPPLIER_NOT_FOUND');
        }
    }

    /**
     * Aplica las especificaciones y devuelve sus valores anteriores.
     *
     * @param array<string, mixed>                                      $activo
     * @param list<array{TES_Tipo_Especificacion: int, valor: ?string}> $specs
     *
     * @return list<array{TES_Tipo_Especificacion: int, valor: ?string}>
     */
    private function especificaciones(array $activo, array $specs, string $usuario): array
    {
        if ([] === $specs) {
            return [];
        }
        $anteriores = [];
        $detalle = [];
        foreach ($specs as $s) {
            $tipo = $s['TES_Tipo_Especificacion'];
            if (false === $this->conexion->fetchOne('SELECT 1 FROM "INV_TIPO_ESPECIFICACION" WHERE "TES_Tipo_Especificacion" = :t', ['t' => $tipo])) {
                throw ErrorDominio::noEncontrado('SPECIFICATION_TYPE_NOT_FOUND');
            }
            $valor = trim((string) ($s['valor'] ?? ''));
            $valor = '' === $valor ? null : $this->specificationRules->normalize((int) $activo['TAC_Tipo_Activo'], (int) $tipo, $valor);
            $actual = $this->conexion->fetchAssociative('SELECT "ESP_Especificacion", "ESP_Valor" FROM "INV_ESPECIFICACION" WHERE "ACT_Activo" = :a AND '
                .'"TES_Tipo_Especificacion" = :t FETCH FIRST 1 ROWS ONLY', ['a' => $activo['ACT_Activo'], 't' => $tipo]);
            $antes = false === $actual ? null : $actual['ESP_Valor'];
            if ($antes === $valor) {
                continue;
            }
            $anteriores[] = ['TES_Tipo_Especificacion' => $tipo, 'valor' => $antes];
            $detalle[] = ['tipo' => $tipo, 'antes' => $antes, 'despues' => $valor];
            if (null === $valor && false !== $actual) {
                $this->conexion->executeStatement('DELETE FROM "INV_ESPECIFICACION" WHERE "ESP_Especificacion" = :id', ['id' => $actual['ESP_Especificacion']]);
            } elseif (false !== $actual) {
                $this->conexion->executeStatement('UPDATE "INV_ESPECIFICACION" SET "ESP_Valor" = :v WHERE "ESP_Especificacion" = :id', ['v' => $valor, 'id' => $actual['ESP_Especificacion']]);
            } else {
                $this->conexion->executeStatement('INSERT INTO "INV_ESPECIFICACION" ("ESP_Valor", "ACT_Activo", "TES_Tipo_Especificacion") VALUES (:v, :a, :t)',
                    ['v' => $valor, 'a' => $activo['ACT_Activo'], 't' => $tipo]);
            }
        }
        if ([] !== $detalle) {
            $this->kardex->registrar('ESPECIFICACION', activo: $activo, usuario: $usuario, motivo: 'Edición en lote', detalle: ['cambios' => $detalle]);
        }

        return $anteriores;
    }
}
