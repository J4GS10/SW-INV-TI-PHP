<?php

declare(strict_types=1);

namespace App\Catalogo;

use App\Dominio\Reloj;
use App\Dominio\ErrorDominio;
use App\Seguridad\Alcance;
use App\Catalogo\Infrastructure\CatalogoStore;
use App\Db\Transaccion;

/**
 * Un solo servicio para todos los catálogos estándar: listar (con uso e
 * inactivos), crear, editar, desactivar/reactivar, reordenar y fusionar
 * duplicados. Fusionar «Kingstom» en «Kingston» reasigna en una transacción
 * todo lo que apunta al origen, deja el origen inactivo con la referencia al
 * destino (<P>_Fusionado_En) y registra CATALOG_MERGE.
 */
final class CatalogoGenerico
{
    public function __construct(
        private readonly CatalogoStore $datos,
        private readonly Transaccion $transaccion,
        private readonly Alcance $alcance,
        private readonly \App\Db\SchemaReferences $esquema,
        private readonly \App\Db\LogicalDelete $borrado,
    ) {
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    public static function describir(array $d): array
    {
        $extras = [];
        foreach ($d['extras'] as $columna => [$tipo, $largo, $catalogo]) {
            $extras[] = ['columna' => $columna, 'tipo' => $tipo, 'largo' => $largo, 'catalogo' => $catalogo];
        }

        return ['clave' => $d['clave'], 'grupo' => $d['grupo'], 'tiene_codigo' => $d['codigo'], 'tiene_descripcion' => $d['descripcion'],
            'largo_nombre' => $d['largo_nombre'], 'permite_crear' => $d['permite_crear'], 'extras' => $extras];
    }

    /** @return list<array<string, mixed>> */
    public function listar(string $clave, bool $inactivos): array
    {
        $d = RegistroCatalogos::definicion($clave);
        $p = $d['prefijo'];
        $filas = $this->datos->filas($d['tabla'], $p, $inactivos);
        $uso = $this->uso($d);

        return array_map(fn (array $f): array => $this->serializar($d, $f, $uso[(int) $f[$d['pk']]] ?? 0), $filas);
    }

    /**
     * Nombre de un registro activo (o la columna `fuente` si tiene valor); 422
     * CATALOG_ITEM_NOT_FOUND:<clave> si no existe o está inactivo.
     */
    public function nombreVigente(string $clave, int $id, ?string $fuente = null): string
    {
        $d = RegistroCatalogos::definicion($clave);
        $f = $this->fila($d, $id);
        if (null === $f || 1 !== (int) $f[$d['prefijo'].'_Activo']) {
            throw ErrorDominio::noProcesable("CATALOG_ITEM_NOT_FOUND:{$clave}");
        }
        $nombre = (string) $f[$d['prefijo'].'_Nombre'];

        return null !== $fuente && null !== ($f[$fuente] ?? null) && '' !== $f[$fuente] ? (string) $f[$fuente] : $nombre;
    }

    /**
     * Registro por su código interno (la lógica nunca depende del nombre).
     *
     * @return array<string, mixed>|null
     */
    public function porCodigo(string $clave, string $codigo, bool $soloActivos = true): ?array
    {
        $d = RegistroCatalogos::definicion($clave);
        $p = $d['prefijo'];
        $f = $this->datos->porCodigo($d['tabla'], $p, $codigo, $soloActivos);

        return false === $f ? null : $f;
    }

    /**
     * @param array<string, mixed> $datos nombre, descripcion, orden, extras (validados por forma)
     *
     * @return array<string, mixed>
     */
    public function crear(string $clave, array $datos, callable $auditar): array
    {
        $d = RegistroCatalogos::definicion($clave);
        $p = $d['prefijo'];
        if (!$d['permite_crear']) {
            throw ErrorDominio::conflicto('CATALOG_CLOSED');
        }
        $nombre = trim((string) $datos['nombre']);
        if (mb_strlen($nombre) > $d['largo_nombre']) {
            throw ErrorDominio::noProcesable('CATALOG_NAME_TOO_LONG');
        }

        return $this->transaccion->ejecutar(function () use ($d, $p, $datos, $nombre, $auditar): array {
            $this->nombreLibre($d, $nombre);
            $valores = ["{$p}_Nombre" => $nombre, "{$p}_Descripcion" => self::textoOpcional($datos['descripcion'] ?? null)];
            if ($d['codigo_automatico']) {
                $valores["{$p}_Codigo"] = $this->codigoLibre($d, $nombre);
            }
            $valores["{$p}_Orden"] = $datos['orden'] ?? ($this->datos->mayorOrden($d['tabla'], $p) + 10);
            $valores = [...$valores, ...$this->validarExtras($d, (array) ($datos['extras'] ?? []))];
            $id = $this->datos->insertar($d['tabla'], self::aBase($valores), $d['pk']);
            $auditar('CREATE', $d['tabla'], ['id' => $id, 'nombre' => $nombre]);

            return $this->serializar($d, (array) $this->fila($d, $id));
        });
    }

    /**
     * @param array<string, mixed> $datos campos enviados (exclude_unset)
     *
     * @return array<string, mixed>
     */
    public function editar(string $clave, int $id, array $datos, callable $auditar): array
    {
        $d = RegistroCatalogos::definicion($clave);
        $p = $d['prefijo'];

        return $this->transaccion->ejecutar(function () use ($d, $p, $id, $datos, $auditar): array {
            $f = $this->fila($d, $id) ?? throw ErrorDominio::noEncontrado('CATALOG_ITEM_NOT_FOUND');
            $esSistema = $d['codigo'] && \in_array($f["{$p}_Codigo"], $d['sistema'], true);
            $cambios = [];
            if (null !== ($datos['nombre'] ?? null)) {
                $nombre = trim((string) $datos['nombre']);
                if ($nombre !== $f["{$p}_Nombre"]) {
                    // La interfaz traduce los registros del sistema por su código: renombrarlos no tendría efecto.
                    if ($esSistema) {
                        throw ErrorDominio::conflicto('CATALOG_SYSTEM_ITEM');
                    }
                    if (mb_strlen($nombre) > $d['largo_nombre']) {
                        throw ErrorDominio::noProcesable('CATALOG_NAME_TOO_LONG');
                    }
                    $this->nombreLibre($d, $nombre, $id);
                    $cambios["{$p}_Nombre"] = $nombre;
                }
            }
            if (\array_key_exists('descripcion', $datos)) {
                $cambios["{$p}_Descripcion"] = self::textoOpcional($datos['descripcion']);
            }
            if (null !== ($datos['orden'] ?? null)) {
                $cambios["{$p}_Orden"] = $datos['orden'];
            }
            if (null !== ($datos['activo'] ?? null) && $datos['activo'] !== (1 === (int) $f["{$p}_Activo"])) {
                if ($esSistema && !$datos['activo']) {
                    throw ErrorDominio::conflicto('CATALOG_SYSTEM_ITEM');
                }
                if ($datos['activo'] && null !== $f["{$p}_Fusionado_En"]) {
                    throw ErrorDominio::conflicto('CATALOG_ITEM_MERGED');
                }
                $cambios["{$p}_Activo"] = $datos['activo'];
            }
            if (!empty($datos['extras'])) {
                $cambios = [...$cambios, ...$this->validarExtras($d, (array) $datos['extras'])];
            }
            if ([] !== $cambios) {
                $this->actualizar($d, $id, $cambios);
                $auditar('UPDATE', $d['tabla'], ['id' => $id, 'cambios' => $cambios]);
            }

            return $this->serializar($d, (array) $this->fila($d, $id));
        });
    }

    /**
     * @param list<int> $ids
     */
    public function reordenar(string $clave, array $ids, callable $auditar): void
    {
        $d = RegistroCatalogos::definicion($clave);
        $this->transaccion->ejecutar(function () use ($d, $ids, $auditar): void {
            foreach ($ids as $posicion => $id) {
                $this->datos->fijarOrden($d['tabla'], $d['prefijo'], $d['pk'], $id, ($posicion + 1) * 10);
            }
            $auditar('CATALOG_REORDER', $d['tabla'], ['ids' => $ids]);
        });
    }

    /** @return array{conteos: object, total: int} */
    public function previaFusion(string $clave, int $origenId, int $destinoId): array
    {
        $d = RegistroCatalogos::definicion($clave);
        [$origen] = $this->validarFusion($d, $origenId, $destinoId);
        $conteos = $this->conteos($d, $origen);
        if ('marcas' === $d['clave']) {
            // Los activos de los modelos de la marca también cambian de marca.
            $conteos['INV_ACTIVO.MAR_Marca'] = $this->datos->activosDeMarca($origenId);
        }

        return ['conteos' => (object) $conteos, 'total' => array_sum($conteos)];
    }

    /** @return array{conteos: object, total: int, modelos_unidos: int} */
    public function fusionar(string $clave, int $origenId, int $destinoId, callable $auditar): array
    {
        $d = RegistroCatalogos::definicion($clave);
        $p = $d['prefijo'];
        // Los catálogos son globales: fusionar mueve registros de todas las sedes.
        if ($this->alcance->actual()?->restringido()) {
            throw ErrorDominio::prohibido('CATALOG_MERGE_REQUIRES_GLOBAL_SCOPE');
        }

        return $this->transaccion->ejecutar(function () use ($d, $p, $clave, $origenId, $destinoId, $auditar): array {
            [$origen, $destino] = $this->validarFusion($d, $origenId, $destinoId);
            $previa = $this->previaFusion($clave, $origenId, $destinoId);
            $nombreDestino = (string) $destino["{$p}_Nombre"];
            $unidos = $this->transaccion->sinViolaciones(function () use ($d, $p, $origen, $destino, $origenId, $destinoId, $nombreDestino): int {
                $unidos = 'marcas' === $d['clave'] ? $this->fusionarModelosDeMarca($origenId, $destinoId) : 0;
                foreach ($this->referencias($d['tabla'], $d['pk']) as $ref) {
                    [$columnaTexto, $fuente] = $d['textos']["{$ref['tabla']}.{$ref['columna']}"] ?? [null, null];
                    $this->datos->reapuntar($ref['tabla'], $ref['columna'], $origenId, $destinoId, $columnaTexto, null === $columnaTexto ? null
                        : mb_substr((string) (($destino[$fuente] ?? null) ?: $nombreDestino), 0, $this->largoColumna($ref['tabla'], $columnaTexto)));
                }
                $codigoOrigen = $d['codigo'] ? $origen["{$p}_Codigo"] : null;
                $codigoDestino = $d['codigo'] ? $destino["{$p}_Codigo"] : null;
                if (null !== $codigoOrigen && null !== $codigoDestino) {
                    foreach ($d['codigos'] as [$tabla, $columna]) {
                        $this->datos->reapuntar($tabla, $columna, $codigoOrigen, $codigoDestino);
                    }
                }

                return $unidos;
            }, ErrorDominio::conflicto('CATALOG_MERGE_CONFLICT'));
            $this->actualizar($d, $origenId, ["{$p}_Activo" => false, "{$p}_Fusionado_En" => $destinoId, "{$p}_Fusionado_Fecha" => Reloj::aBd(Reloj::ahora())]);
            $auditar('CATALOG_MERGE', $d['tabla'], [
                'origen' => $origenId, 'origen_nombre' => $origen["{$p}_Nombre"], 'destino' => $destinoId,
                'destino_nombre' => $nombreDestino, 'conteos' => $previa['conteos'], 'modelos_unidos' => $unidos,
            ]);

            return [...$previa, 'modelos_unidos' => $unidos];
        });
    }

    // ---- internos --------------------------------------------------------

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function serializar(array $d, array $f, int $uso = 0): array
    {
        $p = $d['prefijo'];
        $codigo = $d['codigo'] ? $f["{$p}_Codigo"] : null;
        $extras = [];
        foreach ($d['extras'] as $columna => [$tipo]) {
            $valor = $f[$columna] ?? null;
            $extras[$columna] = null === $valor ? null : match ($tipo) {
                'bool' => 1 === (int) $valor,
                'catalogo' => (int) $valor,
                'aplica_a' => json_decode((string) $valor, true),
                default => (string) $valor,
            };
        }

        return [
            'id' => (int) $f[$d['pk']],
            'nombre' => $f["{$p}_Nombre"],
            'codigo' => $codigo,
            'descripcion' => $f["{$p}_Descripcion"] ?? null,
            'orden' => (int) $f["{$p}_Orden"],
            'activo' => 1 === (int) $f["{$p}_Activo"],
            'fusionado_en' => null === $f["{$p}_Fusionado_En"] ? null : (int) $f["{$p}_Fusionado_En"],
            'sistema' => null !== $codigo && \in_array($codigo, $d['sistema'], true),
            'uso' => $uso,
            'extras' => (object) $extras,
        ];
    }

    /**
     * @param array<string, mixed> $d
     * @return array<int, int> id => número de referencias
     */
    private function uso(array $d): array
    {
        $totales = [];
        foreach ($this->referencias($d['tabla'], $d['pk']) as $ref) {
            foreach ($this->datos->usoPorValor($ref['tabla'], $ref['columna']) as [$valor, $n]) {
                $totales[(int) $valor] = ($totales[(int) $valor] ?? 0) + (int) $n;
            }
        }
        if ([] !== $d['codigos']) {
            $ids = $this->datos->idsPorCodigo($d['tabla'], $d['prefijo'], $d['pk']);
            foreach ($d['codigos'] as [$tabla, $columna]) {
                foreach ($this->datos->usoPorValor($tabla, $columna) as [$codigo, $n]) {
                    if (isset($ids[$codigo])) {
                        $totales[(int) $ids[$codigo]] = ($totales[(int) $ids[$codigo]] ?? 0) + (int) $n;
                    }
                }
            }
        }

        return $totales;
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $origen
     *
     * @return array<string, int>
     */
    private function conteos(array $d, array $origen): array
    {
        $conteos = [];
        $id = (int) $origen[$d['pk']];
        foreach ($this->referencias($d['tabla'], $d['pk']) as $ref) {
            $n = $this->datos->cuantos($ref['tabla'], $ref['columna'], $id);
            if ($n > 0) {
                $conteos["{$ref['tabla']}.{$ref['columna']}"] = $n;
            }
        }
        $codigo = $d['codigo'] ? $origen[$d['prefijo'].'_Codigo'] : null;
        foreach (null === $codigo ? [] : $d['codigos'] as [$tabla, $columna]) {
            $n = $this->datos->cuantos($tabla, $columna, $codigo);
            if ($n > 0) {
                $conteos["{$tabla}.{$columna}"] = $n;
            }
        }

        return $conteos;
    }

    /**
     * @param array<string, mixed> $d
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validarFusion(array $d, int $origenId, int $destinoId): array
    {
        $p = $d['prefijo'];
        if ($origenId === $destinoId) {
            throw ErrorDominio::noProcesable('CATALOG_MERGE_SAME_ITEM');
        }
        $origen = $this->fila($d, $origenId) ?? throw ErrorDominio::noEncontrado('CATALOG_ITEM_NOT_FOUND');
        $destino = $this->fila($d, $destinoId) ?? throw ErrorDominio::noEncontrado('CATALOG_ITEM_NOT_FOUND');
        if ($d['codigo'] && \in_array($origen["{$p}_Codigo"], $d['sistema'], true)) {
            throw ErrorDominio::conflicto('CATALOG_SYSTEM_ITEM');
        }
        if (null !== $origen["{$p}_Fusionado_En"]) {
            throw ErrorDominio::conflicto('CATALOG_ITEM_MERGED');
        }
        if (1 !== (int) $destino["{$p}_Activo"]) {
            throw ErrorDominio::conflicto('CATALOG_MERGE_TARGET_INACTIVE');
        }
        // «RAM» (number, GB) and «Tipo de RAM» (text) are different data: merging them would corrupt what the assets recorded.
        if ('tipos-especificacion' === $d['clave'] && ([$origen['TES_Data_Type'], $origen['UME_Unidad']] != [$destino['TES_Data_Type'], $destino['UME_Unidad']])) {
            throw ErrorDominio::noProcesable('CATALOG_MERGE_INCOMPATIBLE');
        }

        return [$origen, $destino];
    }

    /**
     * Modelos de la marca absorbida que ya existen en el destino (mismo nombre):
     * sus referencias pasan al modelo del destino y el duplicado se elimina.
     */
    private function fusionarModelosDeMarca(int $origenId, int $destinoId): int
    {
        $destino = [];
        foreach ($this->datos->modelosDeMarca($destinoId) as [$id, $nombre]) {
            $destino[mb_strtolower(trim((string) $nombre))] = (int) $id;
        }
        $unidos = 0;
        foreach ($this->datos->modelosDeMarca($origenId) as [$id, $nombre]) {
            $gemelo = $destino[mb_strtolower(trim((string) $nombre))] ?? null;
            if (null === $gemelo) {
                continue;
            }
            foreach ($this->referencias('INV_MODELO', 'MOD_Modelo') as $ref) {
                $this->datos->reapuntar($ref['tabla'], $ref['columna'], $id, $gemelo);
            }
            // Sin referencias ya (se movieron al gemelo): se archiva, no se borra.
            $this->borrado->archive('INV_MODELO', 'MOD_Modelo', (int) $id);
            ++$unidos;
        }

        return $unidos;
    }

    /**
     * Columnas de otras tablas con llave foránea hacia la clave del catálogo
     * (del diccionario de Oracle; sin la propia de fusión).
     *
     * @return list<array{tabla: string, columna: string}>
     */
    public function referencias(string $tabla, string $pk): array
    {
        return array_map(static fn (array $r): array => ['tabla' => $r['table'], 'columna' => $r['column']], $this->esquema->childrenOf($tabla, $pk));
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>|null
     */
    private function fila(array $d, int $id): ?array
    {
        $f = $this->datos->fila($d['tabla'], $d['pk'], $id);

        return false === $f ? null : $f;
    }

    /**
     * @param array<string, mixed> $d
     */
    private function nombreLibre(array $d, string $nombre, ?int $excepto = null): void
    {
        if ($this->datos->existeNombre($d['tabla'], $d['prefijo'], $d['pk'], $nombre, $excepto)) {
            throw ErrorDominio::conflicto('CATALOG_ITEM_ALREADY_EXISTS');
        }
    }

    /**
     * «Código de suscripción + PIN» → CODIGO_DE_SUSCRIPCION_PIN (único).
     *
     * @param array<string, mixed> $d
     */
    private function codigoLibre(array $d, string $nombre): string
    {
        $base = self::codigoDesdeNombre($nombre, $d['largo_codigo'] - 3);
        $codigo = $base;
        for ($n = 2; $this->datos->existeCodigo($d['tabla'], $d['prefijo'], $codigo); ++$n) {
            $codigo = "{$base}_{$n}";
        }

        return $codigo;
    }

    public static function codigoDesdeNombre(string $nombre, int $largo = 30): string
    {
        $ascii = (string) preg_replace('/[^\x00-\x7F]/', '', \Normalizer::normalize($nombre, \Normalizer::FORM_KD) ?: $nombre);
        $base = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $ascii), '_'));

        return substr('' === $base ? 'ITEM' : $base, 0, $largo);
    }

    /**
     * Valida los campos propios del catálogo; 422 CATALOG_FIELD_NOT_ALLOWED / CATALOG_FIELD_INVALID.
     *
     * @param array<string, mixed> $d
     * @param array<string, mixed> $extras
     *
     * @return array<string, mixed>
     */
    private function validarExtras(array $d, array $extras): array
    {
        $limpios = [];
        foreach ($extras as $columna => $valor) {
            $e = $d['extras'][$columna] ?? throw ErrorDominio::noProcesable("CATALOG_FIELD_NOT_ALLOWED:{$columna}");
            [$tipo, $largo, $catalogo, $texto] = $e;
            $invalido = ErrorDominio::noProcesable("CATALOG_FIELD_INVALID:{$columna}");
            switch ($tipo) {
                case 'bool':
                    \is_bool($valor) || throw $invalido;
                    break;
                case 'texto':
                    if (null !== $valor && (!\is_string($valor) || mb_strlen($valor) > $largo)) {
                        throw $invalido;
                    }
                    $valor = self::textoOpcional($valor);
                    break;
                case 'tipo_dato':
                    \in_array($valor, ['TEXT', 'NUMBER', 'LIST', 'BOOLEAN'], true) || throw $invalido;
                    break;
                case 'numero':
                    if (null !== $valor && !is_numeric($valor)) {
                        throw $invalido;
                    }
                    $valor = null === $valor ? null : (float) $valor;
                    break;
                case 'aplica_a':
                    if (null !== $valor && (!\is_array($valor) || !array_is_list($valor)
                        || \count(array_filter($valor, 'is_string')) !== \count($valor) || [] !== array_diff($valor, RegistroCatalogos::APLICA_A))) {
                        throw $invalido;
                    }
                    $valor = [] === ($valor ?? []) ? null : array_values(array_unique($valor));
                    if (null !== $valor) {
                        sort($valor);
                    }
                    break;
                case 'catalogo':
                    $referido = null;
                    if (null !== $valor) {
                        $ref = RegistroCatalogos::definicion((string) $catalogo);
                        $referido = \is_int($valor) ? $this->fila($ref, $valor) : null;
                        if (null === $referido || 1 !== (int) $referido[$ref['prefijo'].'_Activo']) {
                            throw $invalido;
                        }
                    }
                    if (null !== $texto) {
                        [$columnaTexto, $fuente] = $texto;
                        $contenido = null === $referido ? null : (($referido[$fuente] ?? null) ?: $referido[RegistroCatalogos::definicion((string) $catalogo)['prefijo'].'_Nombre']);
                        $limpios[$columnaTexto] = null === $contenido ? null : mb_substr((string) $contenido, 0, $this->largoColumna($d['tabla'], $columnaTexto));
                    }
                    break;
            }
            $limpios[$columna] = $valor;
        }

        return $limpios;
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $cambios
     */
    private function actualizar(array $d, int $id, array $cambios): void
    {
        $this->datos->actualizar($d['tabla'], $d['pk'], $id, self::aBase($cambios));
    }

    /**
     * @param array<string, mixed> $valores
     * @return array<string, scalar|null>
     */
    private static function aBase(array $valores): array
    {
        return array_map(static fn (mixed $v): mixed => match (true) {
            \is_bool($v) => $v ? 1 : 0,
            \is_array($v) => json_encode($v, \JSON_THROW_ON_ERROR),
            default => $v,
        }, $valores);
    }

    private function largoColumna(string $tabla, string $columna): int
    {
        return $this->esquema->charLength($tabla, $columna) ?? 4000;
    }

    private static function textoOpcional(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return '' === $texto ? null : $texto;
    }
}
