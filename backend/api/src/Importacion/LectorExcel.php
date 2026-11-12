<?php

declare(strict_types=1);

namespace App\Importacion;

use App\Dominio\ErrorDominio;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lee el Excel de importación: busca la hoja (primero la llamada «INVENTARIO») y,
 * en sus primeras 15 filas, la de encabezados; devuelve las filas con datos.
 * Acepta la plantilla y la hoja del Excel original de inventario (encabezados en
 * español, inglés o italiano, sin importar tildes ni mayúsculas).
 *
 * Las fórmulas NO se calculan: se usa el último valor guardado por Excel.
 */
final class LectorExcel
{
    public const int MAX_FILAS = 1000;
    public const int MAX_BYTES = 5 * 1024 * 1024;
    /**
     * Límites del contenido descomprimido (defensa contra «bombas zip», fase 7): un .xlsx real de
     * MAX_FILAS filas ocupa pocos MB al descomprimirse. Se comprueban con el tamaño que DECLARA el
     * directorio del ZIP, antes de que PhpSpreadsheet descomprima nada.
     */
    public const int MAX_BYTES_DESCOMPRIMIDOS = 25 * 1024 * 1024;
    public const int MAX_PROPORCION = 100;
    public const int MAX_ENTRADAS = 500;
    /** Columnas que se leen: la plantilla tiene 13 y el Excel original de inventario unas 20. */
    private const int MAX_COLUMNAS = 60;
    private const int FILAS_BUSQUEDA_ENCABEZADO = 15;

    /** Encabezado normalizado → campo. */
    public const array ENCABEZADOS = [
        'codigo' => 'codigo', 'code' => 'codigo', 'codice' => 'codigo',
        'categoria' => 'categoria', 'tipo' => 'categoria', 'tipo de activo' => 'categoria', 'type' => 'categoria', 'category' => 'categoria',
        'descripcion breve' => 'descripcion', 'descripcion' => 'descripcion', 'description' => 'descripcion',
        'marca' => 'marca', 'brand' => 'marca',
        'modelo' => 'modelo', 'model' => 'modelo',
        'serie' => 'serie', 'numero de serie' => 'serie', 'serie del fabricante' => 'serie', 'serial' => 'serie',
        'hostname' => 'hostname', 'host' => 'hostname',
        'ubicacion actual' => 'ubicacion', 'ubicacion' => 'ubicacion', 'area' => 'ubicacion', 'location' => 'ubicacion',
        'fecha entrada' => 'fecha', 'fecha de compra' => 'fecha', 'fecha compra' => 'fecha', 'fecha' => 'fecha', 'purchase date' => 'fecha',
        'id factura' => 'factura', 'factura' => 'factura', 'invoice' => 'factura',
        'observaciones' => 'observaciones', 'notas' => 'observaciones', 'notes' => 'observaciones',
        'costo' => 'costo', 'cost' => 'costo', 'moneda' => 'moneda', 'currency' => 'moneda',
    ];
    /** Campos cuyo valor se conserva con su tipo (fecha de Excel, número). */
    private const array CRUDOS = ['fecha', 'costo'];

    /** @return list<FilaImportacion> */
    public function leer(string $contenido): array
    {
        if (\strlen($contenido) > self::MAX_BYTES) {
            throw ErrorDominio::demasiadoGrande('FILE_TOO_LARGE');
        }
        $hojas = $this->hojas($contenido);
        // La hoja «INVENTARIO» primero; las demás, en su orden.
        usort($hojas, static fn (Worksheet $a, Worksheet $b): int => (int) (Texto::normalizar($b->getTitle()) === 'inventario')
            <=> (int) (Texto::normalizar($a->getTitle()) === 'inventario'));
        foreach ($hojas as $hoja) {
            $filas = self::valores($hoja);
            foreach (\array_slice($filas, 0, self::FILAS_BUSQUEDA_ENCABEZADO, true) as $i => $encabezado) {
                $mapa = self::mapa($encabezado);
                if (!isset($mapa['categoria'], $mapa['marca']) || (!isset($mapa['modelo']) && !isset($mapa['descripcion']))) {
                    continue;
                }
                $resultado = [];
                foreach (\array_slice($filas, $i + 1, null, true) as $k => $celdas) {
                    $datos = [];
                    $vacia = true;
                    foreach ($mapa as $campo => $j) {
                        $valor = $celdas[$j] ?? null;
                        $vacia = $vacia && '' === Texto::limpio($valor);
                        $datos[$campo] = \in_array($campo, self::CRUDOS, true) ? $valor : Texto::limpio($valor);
                    }
                    if (!$vacia) {
                        $resultado[] = new FilaImportacion($k + 1, $datos);
                    }
                }
                if (\count($resultado) > self::MAX_FILAS) {
                    throw ErrorDominio::invalido('IMPORT_TOO_MANY_ROWS:'.self::MAX_FILAS);
                }
                if ([] === $resultado) {
                    throw ErrorDominio::invalido('IMPORT_EMPTY');
                }

                return $resultado;
            }
        }
        throw ErrorDominio::invalido('IMPORT_HEADERS_NOT_FOUND');
    }

    /** @return list<Worksheet> */
    private function hojas(string $contenido): array
    {
        $temporal = tempnam(sys_get_temp_dir(), 'imp');
        if (false === $temporal) {
            throw new \RuntimeException('No se pudo crear el archivo temporal de la importación.');
        }
        try {
            file_put_contents($temporal, $contenido);
            self::verificarZip($temporal);
            $lector = new Xlsx();
            if (!$lector->canRead($temporal)) {
                throw ErrorDominio::invalido('IMPORT_INVALID_FILE');
            }
            // Sin celdas vacías; con formatos (para reconocer las fechas). Solo las filas y columnas
            // que se usan: lo demás no se guarda en memoria aunque venga en el archivo.
            $lector->setReadEmptyCells(false);
            $lector->setReadFilter(new class(self::MAX_FILAS + 20, self::MAX_COLUMNAS) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                public function __construct(private readonly int $filas, private readonly int $columnas)
                {
                }

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->filas
                        && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= $this->columnas;
                }
            });

            return $lector->load($temporal)->getAllSheets();
        } catch (ErrorDominio $e) {
            throw $e;
        } catch (\Throwable) {
            // Archivo dañado o que no es un .xlsx.
            throw ErrorDominio::invalido('IMPORT_INVALID_FILE');
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * Rechaza el .xlsx si su directorio ZIP declara más contenido del razonable: demasiadas
     * entradas, demasiados bytes descomprimidos o una proporción de compresión propia de una
     * «bomba». Solo lee el directorio (no descomprime).
     */
    private static function verificarZip(string $ruta): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($ruta, \ZipArchive::RDONLY)) {
            throw ErrorDominio::invalido('IMPORT_INVALID_FILE');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRADAS) {
                throw ErrorDominio::invalido('IMPORT_INVALID_FILE');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entrada = $zip->statIndex($i);
                if (false === $entrada) {
                    throw ErrorDominio::invalido('IMPORT_INVALID_FILE');
                }
                $total += $entrada['size'];
                if ($total > self::MAX_BYTES_DESCOMPRIMIDOS
                    || ($entrada['size'] > 1024 * 1024 && $entrada['size'] > self::MAX_PROPORCION * max(1, $entrada['comp_size']))) {
                    throw ErrorDominio::invalido('IMPORT_INVALID_FILE');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Valores de la hoja (hasta MAX_FILAS + 20 filas), fila → [columna 0.. → valor].
     *
     * @return array<int, array<int, mixed>> índice 0 = fila 1 de Excel
     */
    private static function valores(Worksheet $hoja): array
    {
        $ultima = min($hoja->getHighestDataRow(), self::MAX_FILAS + 20);
        $filas = [];
        for ($f = 1; $f <= $ultima; ++$f) {
            $fila = [];
            foreach ($hoja->getRowIterator($f, $f)->current()->getCellIterator() as $celda) {
                $fila[\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($celda->getColumn()) - 1] = self::valor($celda);
            }
            $filas[$f - 1] = $fila;
        }

        return $filas;
    }

    /** Valor como lo ve el usuario: fechas como \DateTimeImmutable, números enteros como int. */
    private static function valor(Cell $celda): mixed
    {
        $valor = DataType::TYPE_FORMULA === $celda->getDataType() ? $celda->getOldCalculatedValue() : $celda->getValue();
        if ($valor instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            return $valor->getPlainText();
        }
        if (\is_float($valor) || \is_int($valor)) {
            if (Date::isDateTime($celda)) {
                return \DateTimeImmutable::createFromMutable(Date::excelToDateTimeObject($valor));
            }
        }

        return $valor;
    }

    /**
     * @param array<int, mixed> $encabezado
     *
     * @return array<string, int> campo → columna (si se repite, gana la primera)
     */
    private static function mapa(array $encabezado): array
    {
        $mapa = [];
        ksort($encabezado);
        foreach ($encabezado as $j => $celda) {
            $campo = self::ENCABEZADOS[Texto::normalizar($celda)] ?? null;
            if (null !== $campo && !isset($mapa[$campo])) {
                $mapa[$campo] = $j;
            }
        }

        return $mapa;
    }
}
