<?php

declare(strict_types=1);

namespace App\Esquema;

/**
 * Script SQL de DDL (salida de Data Pump): una sentencia por bloque, terminada
 * en «;» al final de una línea y fuera de comillas. Las líneas de comentario
 * (`--`) entre sentencias se ignoran. No admite PL/SQL (bloques con «/»): la
 * capa de seguridad se instala con App\Esquema\CapaSeguridad.
 */
final readonly class ScriptSql
{
    /** @param list<string> $sentencias */
    private function __construct(private array $sentencias)
    {
    }

    public static function desdeArchivo(string $ruta): self
    {
        $texto = @file_get_contents($ruta);
        if (false === $texto) {
            throw new \RuntimeException("No se pudo leer el script SQL {$ruta}.");
        }

        return self::desdeTexto($texto);
    }

    public static function desdeTexto(string $texto): self
    {
        $sentencias = [];
        $actual = '';
        foreach (preg_split('/\R/', $texto) ?: [] as $linea) {
            if ('' === $actual && (str_starts_with(ltrim($linea), '--') || '' === trim($linea))) {
                continue;
            }
            $actual .= ('' === $actual ? '' : "\n").$linea;
            $fin = rtrim($linea);
            // Termina solo si el «;» queda fuera de un literal (número par de comillas simples).
            if (str_ends_with($fin, ';') && 0 === substr_count($actual, "'") % 2) {
                $sentencias[] = rtrim(substr(rtrim($actual), 0, -1));
                $actual = '';
            }
        }
        if ('' !== trim($actual)) {
            throw new \UnexpectedValueException('El script SQL termina con una sentencia sin «;».');
        }

        return new self($sentencias);
    }

    /** @return list<string> */
    public function sentencias(): array
    {
        return $this->sentencias;
    }

    /**
     * Tablas que crea el script (`CREATE TABLE "NOMBRE"`), en orden.
     *
     * @return list<string>
     */
    public function tablasCreadas(): array
    {
        $tablas = [];
        foreach ($this->sentencias as $sql) {
            if (1 === preg_match('/^CREATE TABLE "([A-Za-z0-9_$#]+)"/', $sql, $m)) {
                $tablas[] = $m[1];
            }
        }

        return $tablas;
    }
}
