<?php

declare(strict_types=1);

namespace App\Documentos;

/**
 * CSV en UTF-8 con BOM (Excel en Windows detecta así la codificación y muestra
 * bien tildes y eñes), separado por comas y con fin de línea CRLF, como el módulo
 * `csv` del backend anterior. Cada celda de datos pasa por CeldaSegura.
 *
 * Dos estilos de comillas: mínimas (solo si la celda lo necesita) o en todas las
 * celdas (exportaciones de /export).
 */
final class ArchivoCsv
{
    private const string BOM = "\u{FEFF}";

    private string $contenido;

    /** @param list<string> $cabeceras */
    private function __construct(array $cabeceras, private readonly bool $comillasSiempre)
    {
        $this->contenido = self::BOM.$this->linea($cabeceras);
    }

    /** @param list<string> $cabeceras */
    public static function minimo(array $cabeceras): self
    {
        return new self($cabeceras, false);
    }

    /** @param list<string> $cabeceras */
    public static function entreComillas(array $cabeceras): self
    {
        return new self($cabeceras, true);
    }

    /** @param list<mixed> $celdas */
    public function fila(array $celdas): self
    {
        $this->contenido .= $this->linea(array_map(CeldaSegura::csv(...), $celdas));

        return $this;
    }

    /** @param iterable<list<mixed>> $filas */
    public function filas(iterable $filas): self
    {
        foreach ($filas as $celdas) {
            $this->fila($celdas);
        }

        return $this;
    }

    public function contenido(): string
    {
        return $this->contenido;
    }

    /** @param list<string> $celdas */
    private function linea(array $celdas): string
    {
        return implode(',', array_map($this->celda(...), $celdas))."\r\n";
    }

    private function celda(string $texto): string
    {
        $necesita = $this->comillasSiempre || strpbrk($texto, ",\"\r\n") !== false;

        return $necesita ? '"'.str_replace('"', '""', $texto).'"' : $texto;
    }
}
