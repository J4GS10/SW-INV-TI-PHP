<?php

declare(strict_types=1);

namespace App\Adjuntos;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Volumen local (UPLOAD_DIR): adjuntos anteriores a MinIO o despliegues sin S3. */
final class AlmacenLocal extends AlmacenFlysystem
{
    public const string NOMBRE = 'local';

    private ?FilesystemOperator $sistema = null;

    public function __construct(private readonly string $directorio)
    {
    }

    public function nombre(): string
    {
        return self::NOMBRE;
    }

    protected function sistema(?string $bucket): FilesystemOperator
    {
        // Raíz perezosa: el volumen puede no existir en un despliegue solo-S3.
        return $this->sistema ??= new Filesystem(new LocalFilesystemAdapter($this->directorio, lazyRootCreation: true));
    }

    protected function bucketRegistrado(): ?string
    {
        return null;
    }

    /** El directorio existe y el proceso puede escribir en él. */
    public function disponible(): bool
    {
        return is_dir($this->directorio) && is_writable($this->directorio);
    }

    /** En disco se distingue el archivo borrado (410) de un fallo de lectura (503). */
    protected function errorDeLectura(FilesystemOperator $sistema, string $clave, FilesystemException $error): AlmacenNoDisponible
    {
        try {
            $existe = $sistema->fileExists($clave);
        } catch (FilesystemException) {
            $existe = true;
        }

        return AlmacenNoDisponible::por($existe ? AlmacenNoDisponible::LECTURA : AlmacenNoDisponible::ARCHIVO_FALTANTE, $error);
    }
}
