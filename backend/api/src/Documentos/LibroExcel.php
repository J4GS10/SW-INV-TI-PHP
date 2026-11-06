<?php

declare(strict_types=1);

namespace App\Documentos;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Libro .xlsx con celdas de tipo explícito: ningún texto se guarda como fórmula
 * (inyección de fórmulas, ver CeldaSegura). Un texto que parece fórmula se
 * escribe como texto con «quotePrefix»; números y fechas, con su tipo nativo.
 */
final class LibroExcel
{
    public const string FORMATO_FECHA = 'yyyy-mm-dd';
    public const string FORMATO_FECHA_HORA = 'yyyy-mm-dd h:mm:ss';

    private readonly Spreadsheet $libro;
    /** @var array<int, int> siguiente fila libre de cada hoja */
    private array $siguiente = [];

    public function __construct()
    {
        $this->libro = new Spreadsheet();
        $this->libro->removeSheetByIndex(0);
    }

    /**
     * Hoja nueva (nombre de hasta 31 caracteres, el máximo de Excel) con su fila de encabezados.
     *
     * @param list<string>          $encabezados
     * @param array{color?: string, fondo?: string, negrita?: bool} $estiloEncabezado color del texto y fondo (RGB hex); negrita por defecto
     */
    public function hoja(string $nombre, array $encabezados, array $estiloEncabezado = []): Worksheet
    {
        $hoja = $this->libro->createSheet();
        $this->siguiente[spl_object_id($hoja)] = 1;
        $hoja->setTitle(mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $nombre), 0, 31));
        $this->fila($hoja, $encabezados);
        $fuente = $hoja->getStyle([1, 1, max(1, \count($encabezados)), 1])->getFont()->setBold($estiloEncabezado['negrita'] ?? true);
        if (isset($estiloEncabezado['color'])) {
            $fuente->getColor()->setRGB($estiloEncabezado['color']);
        }
        if (isset($estiloEncabezado['fondo'])) {
            $hoja->getStyle([1, 1, max(1, \count($encabezados)), 1])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($estiloEncabezado['fondo']);
        }

        return $hoja;
    }

    /**
     * Agrega una fila al final. Cada valor se escribe según su tipo PHP:
     * int/float → número; DiaCalendario → fecha; \DateTimeInterface → fecha y hora;
     * null → vacía; el resto → texto.
     *
     * @param list<mixed> $valores
     */
    public function fila(Worksheet $hoja, array $valores): void
    {
        $fila = $this->siguiente[spl_object_id($hoja)]++;
        foreach ($valores as $i => $valor) {
            $celda = $hoja->getCell([$i + 1, $fila]);
            match (true) {
                null === $valor => null,
                \is_int($valor), \is_float($valor) => $celda->setValueExplicit($valor, DataType::TYPE_NUMERIC),
                \is_bool($valor) => $celda->setValueExplicit($valor, DataType::TYPE_BOOL),
                $valor instanceof DiaCalendario => $this->fecha($hoja, $i + 1, $fila, $valor->fecha, self::FORMATO_FECHA),
                $valor instanceof \DateTimeInterface => $this->fecha($hoja, $i + 1, $fila, $valor, self::FORMATO_FECHA_HORA),
                default => $this->texto($hoja, $i + 1, $fila, (string) $valor),
            };
        }
    }

    /** @param list<int|float> $anchos ancho de cada columna (en caracteres) */
    public static function anchos(Worksheet $hoja, array $anchos): void
    {
        foreach ($anchos as $i => $ancho) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($ancho);
        }
    }

    public function contenido(): string
    {
        $this->libro->setActiveSheetIndex(0);
        $salida = fopen('php://memory', 'w+b');
        if (false === $salida) {
            throw new \RuntimeException('No se pudo abrir el búfer del libro.');
        }
        try {
            (new Xlsx($this->libro))->save($salida);
            rewind($salida);

            return (string) stream_get_contents($salida);
        } finally {
            fclose($salida);
            $this->libro->disconnectWorksheets();
        }
    }

    private function texto(Worksheet $hoja, int $columna, int $fila, string $texto): void
    {
        $hoja->getCell([$columna, $fila])->setValueExplicit($texto, DataType::TYPE_STRING);
        if (CeldaSegura::esPeligrosa($texto)) {
            // Excel tampoco lo evalúa si el usuario edita la celda.
            $hoja->getStyle([$columna, $fila])->setQuotePrefix(true);
        }
    }

    private function fecha(Worksheet $hoja, int $columna, int $fila, \DateTimeInterface $fecha, string $formato): void
    {
        $hoja->getCell([$columna, $fila])->setValueExplicit(Date::dateTimeToExcel($fecha), DataType::TYPE_NUMERIC);
        $hoja->getStyle([$columna, $fila])->getNumberFormat()->setFormatCode($formato);
    }
}
