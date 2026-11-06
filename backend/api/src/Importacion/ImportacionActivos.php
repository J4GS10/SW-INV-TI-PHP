<?php

declare(strict_types=1);

namespace App\Importacion;

use App\Db\InsercionOracle;
use App\Db\Transaccion;
use App\Documentos\FormatoLocal;
use App\Documentos\LibroExcel;
use App\Http\ErrorApi;
use App\Inventario\Activos;
use App\Inventario\Secuencias;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * Importación de activos desde Excel con vista previa. El usuario descarga la
 * plantilla (o usa su hoja con los mismos encabezados), la sube, revisa fila por
 * fila lo que se va a crear y confirma. La confirmación vuelve a validar y crea
 * todo en UNA transacción con el alta normal de activos (código automático,
 * kardex y auditoría). Los activos entran disponibles en la ubicación indicada.
 */
final readonly class ImportacionActivos
{
    public const string MONEDA_POR_DEFECTO = 'GTQ';
    public const array COLUMNAS_PLANTILLA = ['Código', 'Categoría', 'Descripción', 'Marca', 'Modelo', 'Serie', 'Hostname', 'Ubicación',
        'Fecha de compra', 'Factura', 'Costo', 'Moneda', 'Observaciones'];
    private const array ANCHOS_PLANTILLA = [12, 18, 36, 16, 18, 22, 14, 18, 16, 14, 10, 9, 30];
    /** Series que no identifican un equipo: se usa el código interno. */
    private const array SERIES_GENERICAS = ['', 'generico', 'n/a', 'na', 's/n', 'sin serie', 'none', '-'];
    private const string COSTO_MAXIMO = '9999999999.99';
    private const array ESPECIFICACIONES = ['Factura', 'Observaciones', 'Nota de serie'];
    private const string NOTA_SIN_SERIE = 'Sin serie del fabricante: se usa el código interno.';

    public function __construct(
        private Connection $conexion,
        private LectorExcel $lector,
        private Activos $activos,
        private Secuencias $secuencias,
        private Alcance $alcance,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
        private FormatoLocal $formato,
    ) {
    }

    /** @return array{resumen: array<string, int>, filas: list<array<string, mixed>>} */
    public function previsualizar(string $contenido): array
    {
        [$filas, $resumen] = $this->analizar($contenido);

        return ['resumen' => $resumen, 'filas' => array_map(static fn (FilaImportacion $f): array => $f->resumen(), $filas)];
    }

    /** @return array{creados: int, omitidos: int, codigos: list<string>} */
    public function confirmar(string $contenido, bool $omitirErrores, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($contenido, $omitirErrores, $usuario, $ip): array {
            [$filas, $resumen] = $this->analizar($contenido);
            if ($resumen['con_error'] > 0 && !$omitirErrores) {
                throw new ErrorApi(400, 'IMPORT_HAS_ERRORS');
            }
            $validas = array_values(array_filter($filas, static fn (FilaImportacion $f): bool => $f->valida()));
            if ([] === $validas) {
                throw new ErrorApi(400, 'IMPORT_NOTHING_TO_IMPORT');
            }
            $disponible = (int) ($this->conexion->fetchOne('SELECT "EOP_Estado_Operativo" FROM "INV_ESTADO_OPERATIVO" WHERE "EOP_Nombre" = :n', ['n' => 'En Bodega'])
                ?: throw new \LogicException('Falta el estado operativo «En Bodega».'));
            $especificaciones = $this->tiposEspecificacion();
            [, $marcas, $modelos] = $this->catalogos();

            $creados = [];
            foreach ($validas as $f) {
                $marca = $marcas[Texto::normalizar($f->marca)] ??= (int) InsercionOracle::insertar($this->conexion, 'INV_MARCA',
                    ['MAR_Nombre' => mb_substr($f->marca, 0, 100)], 'MAR_Marca');
                $modelo = $modelos[$marca.'|'.Texto::normalizar($f->modelo)] ??= (int) InsercionOracle::insertar($this->conexion, 'INV_MODELO',
                    ['MOD_Nombre' => $f->modelo, 'MAR_Marca' => $marca, 'TAC_Tipo_Activo' => $f->tipo['TAC_Tipo_Activo']], 'MOD_Modelo');
                $codigo = $f->texto('codigo');
                if ('' === $codigo) {
                    $prefijo = (string) $f->tipo['TAC_Prefijo'];
                    $codigo = $this->secuencias->siguiente("ASSET_{$prefijo}", $prefijo);
                }
                $serie = $f->texto('serie');
                $specs = [];
                foreach (['Factura' => $f->texto('factura'), 'Observaciones' => $f->texto('observaciones'),
                    'Nota de serie' => '' === $serie ? self::NOTA_SIN_SERIE : ''] as $nombre => $valor) {
                    if ('' !== $valor) {
                        $specs[] = ['TES_Tipo_Especificacion' => $especificaciones[$nombre], 'ESP_Valor' => mb_substr($valor, 0, 255)];
                    }
                }
                try {
                    $activo = $this->activos->crear([
                        'ACT_Codigo_Interno' => $codigo, 'ACT_Serie_Fabricante' => '' === $serie ? $codigo : $serie,
                        'ACT_Hostname' => '' === $f->texto('hostname') ? null : $f->texto('hostname'), 'ACT_Fecha_Compra' => $f->fecha,
                        'ACT_Fin_Garantia' => null, 'ACT_Costo' => $f->costo, 'ACT_Moneda' => $f->moneda, 'PRV_Proveedor' => null, 'ACT_Factura' => null,
                        'MOD_Modelo' => $modelo, 'TAC_Tipo_Activo' => $f->tipo['TAC_Tipo_Activo'], 'EOP_Estado_Operativo' => $disponible,
                        'ACT_Activo_Padre' => null, 'SED_Sede' => $this->sedeDeArea($f->area['ARE_Area']),
                    ], $specs, $usuario);
                } catch (ErrorApi $e) {
                    throw new ErrorApi($e->estado, "IMPORT_ROW_FAILED:{$f->numero}:{$e->detalle}");
                }
                $this->conexion->executeStatement('UPDATE "INV_ACTIVO" SET "ARE_Area_Ubicacion" = :a WHERE "ACT_Activo" = :id',
                    ['a' => $f->area['ARE_Area'], 'id' => \App\Dominio\Ident::aHex((string) $activo['ACT_Activo'])]);
                $creados[] = $codigo;
            }
            $this->ajustarSecuencias();
            $omitidos = \count($filas) - \count($validas);
            $this->gobierno->registrarAuditoria('ASSET_IMPORT', 'INV_ACTIVO',
                ['creados' => \count($creados), 'omitidos' => $omitidos, 'codigos' => \array_slice($creados, 0, 200)], $usuario, $ip);

            return ['creados' => \count($creados), 'omitidos' => $omitidos, 'codigos' => $creados];
        });
    }

    /** Plantilla .xlsx: hoja INVENTARIO con los encabezados y hoja Listas con categorías, ubicaciones, marcas y monedas. */
    public function plantilla(): string
    {
        $libro = new LibroExcel();
        $inventario = $libro->hoja('INVENTARIO', self::COLUMNAS_PLANTILLA, ['color' => 'FFFFFF', 'fondo' => '3F4E5F']);
        LibroExcel::anchos($inventario, self::ANCHOS_PLANTILLA);
        $listas = $libro->hoja('Listas', ['Categorías', 'Ubicaciones', 'Marcas existentes', 'Monedas'], ['negrita' => false]);
        $columnas = [
            $this->conexion->fetchFirstColumn('SELECT "TAC_Nombre" FROM "INV_TIPO_ACTIVO" ORDER BY "TAC_Nombre"'),
            $this->conexion->fetchFirstColumn('SELECT "ARE_Nombre" FROM "INV_AREA" ORDER BY "ARE_Nombre"'),
            $this->conexion->fetchFirstColumn('SELECT "MAR_Nombre" FROM "INV_MARCA" ORDER BY "MAR_Nombre"'),
            Activos::MONEDAS,
        ];
        $alto = max(array_map('count', $columnas));
        for ($i = 0; $i < $alto; ++$i) {
            // Nombres escritos por usuarios: una marca «=HYPERLINK(...)» queda como texto.
            $libro->fila($listas, array_map(static fn (array $c): ?string => $c[$i] ?? null, $columnas));
        }

        return $libro->contenido();
    }

    /** @return array{0: list<FilaImportacion>, 1: array<string, int>} */
    private function analizar(string $contenido): array
    {
        $filas = $this->lector->leer($contenido);
        [$tipos, $marcas, $modelos, $areas] = $this->catalogos();
        $series = array_values(array_filter(array_map(static fn (FilaImportacion $f): string => $f->texto('serie'), $filas),
            static fn (string $s): bool => !\in_array(Texto::normalizar($s), self::SERIES_GENERICAS, true)));
        // Unicidad en toda la empresa (no solo en el alcance) y sin importar ceros a la izquierda.
        [$codigosBd, $seriesBd] = $this->alcance->elevado(fn (): array => [
            array_fill_keys(array_map(static fn (string $c): string => Texto::claveCodigo($c),
                $this->conexion->fetchFirstColumn('SELECT "ACT_Codigo_Interno" FROM "INV_ACTIVO"')), true),
            $this->seriesExistentes($series),
        ]);

        $vistosCodigo = [];
        $vistasSerie = [];
        foreach ($filas as $f) {
            $f->datos['codigo'] = mb_strtoupper((string) preg_replace('/\s+/u', '', $f->texto('codigo')));
            $this->validarTipo($f, $tipos);
            $this->validarMarcaYModelo($f, $marcas, $modelos);
            $this->validarCodigo($f, $codigosBd, $vistosCodigo);
            $this->validarSerie($f, $seriesBd, $vistasSerie);
            $this->validarUbicacion($f, $areas);
            $this->validarFecha($f);
            $this->validarCostoYMoneda($f);
        }
        $validas = array_filter($filas, static fn (FilaImportacion $f): bool => $f->valida());

        return [$filas, [
            'filas' => \count($filas),
            'validas' => \count($validas),
            'con_error' => \count($filas) - \count($validas),
            // Solo lo que realmente se creará (filas sin error).
            'marcas_nuevas' => \count(array_unique(array_filter(array_map(static fn (FilaImportacion $f): ?string => $f->marcaNueva, $validas)))),
            'modelos_nuevos' => \count(array_unique(array_filter(array_map(static fn (FilaImportacion $f): ?string => $f->modeloNuevo, $validas)))),
        ]];
    }

    /** @param array<string, array{TAC_Tipo_Activo: int, TAC_Nombre: string, TAC_Prefijo: ?string}> $tipos */
    private function validarTipo(FilaImportacion $f, array $tipos): void
    {
        if ('' === $f->texto('categoria')) {
            $f->error('MISSING_TYPE');

            return;
        }
        $f->tipo = $tipos[Texto::normalizar($f->texto('categoria'))] ?? null;
        $codigo = $f->texto('codigo');
        if (null === $f->tipo && '' !== $codigo) {
            // El prefijo del código identifica el tipo aunque el nombre difiera («Power Adapter» / «Cargador de laptop»).
            foreach ($tipos as $tipo) {
                $prefijo = mb_strtoupper((string) $tipo['TAC_Prefijo']);
                if ('' !== $prefijo && str_starts_with($codigo, $prefijo)
                    && (null === $f->tipo || mb_strlen($prefijo) > mb_strlen((string) $f->tipo['TAC_Prefijo']))) {
                    $f->tipo = $tipo;
                }
            }
            if (null !== $f->tipo) {
                $f->aviso('TYPE_FROM_CODE');
            }
        }
        if (null === $f->tipo) {
            $f->error('TYPE_NOT_FOUND');
        }
    }

    /**
     * Marca y modelo se crean si no existen. El modelo combina la descripción y el
     * código de modelo («Latitude 5440 (P123G)»), hasta 150 caracteres.
     *
     * @param array<string, int> $marcas
     * @param array<string, int> $modelos
     */
    private function validarMarcaYModelo(FilaImportacion $f, array $marcas, array $modelos): void
    {
        $f->marca = $f->texto('marca');
        if ('' === $f->marca) {
            $f->error('MISSING_BRAND');
        }
        $descripcion = $f->texto('descripcion');
        $codigoModelo = $f->texto('modelo');
        if (\in_array(Texto::normalizar($codigoModelo), self::SERIES_GENERICAS, true)) {
            $codigoModelo = '';
        }
        $f->modelo = mb_substr('' !== $descripcion && '' !== $codigoModelo && !str_contains(Texto::normalizar($descripcion), Texto::normalizar($codigoModelo))
            ? "{$descripcion} ({$codigoModelo})"
            : ('' !== $descripcion ? $descripcion : $codigoModelo), 0, 150);
        if ('' === $f->modelo) {
            $f->error('MISSING_MODEL');
        }
        if ('' !== $f->marca && '' !== $f->modelo) {
            $marca = $marcas[Texto::normalizar($f->marca)] ?? null;
            if (null === $marca) {
                $f->marcaNueva = Texto::normalizar($f->marca);
                $f->aviso('NEW_BRAND');
            }
            if (null === $marca || !isset($modelos[$marca.'|'.Texto::normalizar($f->modelo)])) {
                $f->modeloNuevo = Texto::normalizar($f->marca).'|'.Texto::normalizar($f->modelo);
                $f->aviso('NEW_MODEL');
            }
        }
    }

    /**
     * @param array<string, true> $codigosBd
     * @param array<string, true> $vistos
     */
    private function validarCodigo(FilaImportacion $f, array $codigosBd, array &$vistos): void
    {
        $codigo = $f->texto('codigo');
        $prefijo = mb_strtoupper((string) ($f->tipo['TAC_Prefijo'] ?? ''));
        if ('' !== $codigo) {
            $clave = Texto::claveCodigo($codigo);
            if (isset($codigosBd[$clave])) {
                $f->error('CODE_EXISTS');
            } elseif (isset($vistos[$clave])) {
                $f->error('CODE_DUPLICATED_IN_FILE');
            }
            $vistos[$clave] = true;
            if (null !== $f->tipo && '' !== $prefijo && !str_starts_with($codigo, $prefijo)) {
                $f->aviso('CODE_PREFIX_MISMATCH');
            }
        } elseif (null !== $f->tipo && '' === $prefijo) {
            $f->error('TYPE_WITHOUT_PREFIX');
        } else {
            $f->aviso('AUTO_CODE');
        }
    }

    /**
     * @param array<string, true> $seriesBd en minúsculas
     * @param array<string, true> $vistas
     */
    private function validarSerie(FilaImportacion $f, array $seriesBd, array &$vistas): void
    {
        $serie = $f->texto('serie');
        $clave = mb_strtolower($serie);
        if (\in_array(Texto::normalizar($serie), self::SERIES_GENERICAS, true)) {
            $f->datos['serie'] = '';
            $f->aviso('NO_SERIAL_USES_CODE');
        } elseif (isset($seriesBd[$clave])) {
            $f->error('SERIAL_EXISTS');
        } elseif (isset($vistas[$clave])) {
            $f->error('SERIAL_DUPLICATED_IN_FILE');
        } elseif (mb_strlen($serie) < 3) {
            $f->error('SERIAL_TOO_SHORT');
        }
        $vistas[$clave] = true;
    }

    /** @param array<string, array{ARE_Area: int, ARE_Nombre: string}> $areas */
    private function validarUbicacion(FilaImportacion $f, array $areas): void
    {
        if ('' === $f->texto('ubicacion')) {
            $f->error('MISSING_AREA');

            return;
        }
        $f->area = $areas[Texto::normalizar($f->texto('ubicacion'))] ?? null;
        if (null === $f->area) {
            $f->error('AREA_NOT_FOUND');
        }
    }

    /** Fecha de Excel, «AAAA-MM-DD» o «DD/MM/AAAA»; sin fecha, hoy (con aviso). */
    private function validarFecha(FilaImportacion $f): void
    {
        $valor = $f->datos['fecha'] ?? null;
        if ($valor instanceof \DateTimeInterface) {
            $f->fecha = $valor->format('Y-m-d');

            return;
        }
        $texto = mb_substr(Texto::limpio($valor), 0, 10);
        if ('' === $texto) {
            $f->fecha = $this->formato->ahora()->format('Y-m-d');
            $f->aviso('NO_DATE_USES_TODAY');

            return;
        }
        foreach (['!Y-m-d', '!d/m/Y'] as $formato) {
            $fecha = \DateTimeImmutable::createFromFormat($formato, $texto);
            $avisos = \DateTimeImmutable::getLastErrors();
            if (false !== $fecha && (false === $avisos || 0 === $avisos['warning_count'] + $avisos['error_count'])) {
                $f->fecha = $fecha->format('Y-m-d');

                return;
            }
        }
        $f->error('INVALID_DATE');
    }

    private function validarCostoYMoneda(FilaImportacion $f): void
    {
        $crudo = $f->datos['costo'] ?? null;
        $costo = str_replace(',', '', Texto::limpio($crudo));
        // bcmath no admite exponentes («1E3», o un número muy pequeño de Excel): se expande.
        if (is_numeric($costo) && 1 === preg_match('/[eE]/', $costo)) {
            $costo = sprintf('%.4F', (float) $costo);
        }
        if ('' !== $costo) {
            if (is_numeric($costo) && bccomp($costo = bcround(trim($costo), 2, \RoundingMode::HalfEven), '0', 2) >= 0
                && bccomp($costo, self::COSTO_MAXIMO, 2) <= 0) {
                $f->costo = $costo;
            } else {
                $f->error('INVALID_COST');
            }
        }
        $moneda = mb_strtoupper($f->texto('moneda'));
        if ('' !== $moneda && !\in_array($moneda, Activos::MONEDAS, true)) {
            $f->error('INVALID_CURRENCY');
        }
        $f->moneda = '' === $moneda ? self::MONEDA_POR_DEFECTO : $moneda;
    }

    /**
     * Catálogos indexados por nombre normalizado (el último gana, como un dict).
     *
     * @return array{0: array<string, array{TAC_Tipo_Activo: int, TAC_Nombre: string, TAC_Prefijo: ?string}>, 1: array<string, int>,
     *     2: array<string, int>, 3: array<string, array{ARE_Area: int, ARE_Nombre: string}>}
     */
    private function catalogos(): array
    {
        $tipos = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT "TAC_Tipo_Activo", "TAC_Nombre", "TAC_Prefijo" FROM "INV_TIPO_ACTIVO" ORDER BY "TAC_Tipo_Activo"') as $t) {
            $tipos[Texto::normalizar($t['TAC_Nombre'])] = ['TAC_Tipo_Activo' => (int) $t['TAC_Tipo_Activo'], 'TAC_Nombre' => $t['TAC_Nombre'], 'TAC_Prefijo' => $t['TAC_Prefijo']];
        }
        $marcas = [];
        foreach ($this->conexion->fetchAllNumeric('SELECT "MAR_Marca", "MAR_Nombre" FROM "INV_MARCA" ORDER BY "MAR_Marca"') as [$id, $nombre]) {
            $marcas[Texto::normalizar($nombre)] = (int) $id;
        }
        $modelos = [];
        foreach ($this->conexion->fetchAllNumeric('SELECT "MOD_Modelo", "MAR_Marca", "MOD_Nombre" FROM "INV_MODELO" ORDER BY "MOD_Modelo"') as [$id, $marca, $nombre]) {
            $modelos[(int) $marca.'|'.Texto::normalizar($nombre)] = (int) $id;
        }
        $areas = [];
        foreach ($this->conexion->fetchAllNumeric('SELECT "ARE_Area", "ARE_Nombre" FROM "INV_AREA" ORDER BY "ARE_Area"') as [$id, $nombre]) {
            $areas[Texto::normalizar($nombre)] = ['ARE_Area' => (int) $id, 'ARE_Nombre' => $nombre];
        }

        return [$tipos, $marcas, $modelos, $areas];
    }

    /**
     * @param list<string> $series
     *
     * @return array<string, true> las que ya existen, en minúsculas
     */
    private function seriesExistentes(array $series): array
    {
        $existentes = [];
        foreach (array_chunk(array_values(array_unique(array_map('mb_strtolower', $series))), 500) as $lote) {
            $p = [];
            foreach ($lote as $i => $s) {
                $p["s{$i}"] = $s;
            }
            foreach ($this->conexion->fetchFirstColumn('SELECT LOWER("ACT_Serie_Fabricante") FROM "INV_ACTIVO" WHERE LOWER("ACT_Serie_Fabricante") IN (:'
                .implode(', :', array_keys($p)).')', $p) as $s) {
                $existentes[(string) $s] = true;
            }
        }

        return $existentes;
    }

    /**
     * Tipos de especificación Factura, Observaciones y Nota de serie (se crean si faltan).
     *
     * @return array<string, int> nombre → id
     */
    private function tiposEspecificacion(): array
    {
        $ids = [];
        foreach (self::ESPECIFICACIONES as $nombre) {
            $id = $this->conexion->fetchOne('SELECT "TES_Tipo_Especificacion" FROM "INV_TIPO_ESPECIFICACION" WHERE LOWER("TES_Nombre") = :n '
                .'ORDER BY "TES_Tipo_Especificacion" FETCH FIRST 1 ROWS ONLY', ['n' => mb_strtolower($nombre)]);
            $ids[$nombre] = false !== $id ? (int) $id
                : (int) InsercionOracle::insertar($this->conexion, 'INV_TIPO_ESPECIFICACION', ['TES_Nombre' => $nombre], 'TES_Tipo_Especificacion');
        }

        return $ids;
    }

    /** Sede del área (Área → Nivel → Edificio → Sede); el alta valida que esté en el alcance. */
    private function sedeDeArea(int $area): ?int
    {
        $sede = $this->conexion->fetchOne('SELECT e."SED_Sede" FROM "INV_AREA" a JOIN "INV_NIVEL" n ON n."NIV_Nivel" = a."NIV_Nivel" '
            .'JOIN "INV_EDIFICIO" e ON e."EDI_Edificio" = n."EDI_Edificio" WHERE a."ARE_Area" = :a', ['a' => $area]);

        return false === $sede || null === $sede ? null : (int) $sede;
    }

    /** Lleva cada secuencia al mayor número usado: el próximo código automático no choca con uno importado. */
    private function ajustarSecuencias(): void
    {
        foreach ($this->conexion->fetchFirstColumn('SELECT "TAC_Prefijo" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Prefijo" IS NOT NULL') as $prefijo) {
            $prefijo = (string) $prefijo;
            $codigos = $this->alcance->elevado(fn (): array => $this->conexion->fetchFirstColumn(
                "SELECT \"ACT_Codigo_Interno\" FROM \"INV_ACTIVO\" WHERE UPPER(\"ACT_Codigo_Interno\") LIKE :p ESCAPE '\\'",
                ['p' => mb_strtoupper(addcslashes($prefijo, '%_\\')).'%']));
            $mayor = 0;
            foreach ($codigos as $codigo) {
                if (1 === preg_match('/^'.preg_quote($prefijo, '/').'(\d+)$/i', (string) $codigo, $m)) {
                    $mayor = max($mayor, (int) $m[1]);
                }
            }
            if ($mayor > 0) {
                $this->secuencias->asegurarMinimo("ASSET_{$prefijo}", $mayor);
            }
        }
    }
}
