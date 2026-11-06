<?php

declare(strict_types=1);

namespace App\Adjuntos;

/** Falla del almacenamiento; el mensaje es el código del contrato (STORAGE_WRITE_FAILED…). */
final class AlmacenNoDisponible extends \RuntimeException
{
    public const string ESCRITURA = 'STORAGE_WRITE_FAILED';
    public const string LECTURA = 'STORAGE_READ_FAILED';
    public const string BORRADO = 'STORAGE_DELETE_FAILED';
    public const string ARCHIVO_FALTANTE = 'ATTACHMENT_FILE_MISSING';

    public static function por(string $codigo, ?\Throwable $causa = null): self
    {
        return new self($codigo, 0, $causa);
    }
}
