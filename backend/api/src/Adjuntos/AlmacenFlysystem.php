<?php

declare(strict_types=1);

namespace App\Adjuntos;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * Operaciones comunes sobre un sistema de archivos de Flysystem (método
 * plantilla): cada backend dice qué sistema usa para un bucket y cómo traduce
 * una lectura fallida.
 */
abstract class AlmacenFlysystem implements Almacen
{
    abstract protected function sistema(?string $bucket): FilesystemOperator;

    /** Bucket que se registra en el adjunto (null en el volumen local). */
    abstract protected function bucketRegistrado(): ?string;

    public function guardar(string $clave, string $contenido, string $tipoMime): ObjetoGuardado
    {
        $clave = self::claveSegura($clave, AlmacenNoDisponible::ESCRITURA);
        $sha256 = hash('sha256', $contenido);
        try {
            $this->sistema(null)->write($clave, $contenido, ['ContentType' => $tipoMime, 'Metadata' => ['sha256' => $sha256]]);
        } catch (FilesystemException $e) {
            throw AlmacenNoDisponible::por(AlmacenNoDisponible::ESCRITURA, $e);
        }

        return new ObjetoGuardado($this->nombre(), $clave, $this->bucketRegistrado(), \strlen($contenido), $sha256);
    }

    public function leer(string $clave, ?string $bucket = null): string
    {
        $clave = self::claveSegura($clave, AlmacenNoDisponible::LECTURA);
        $sistema = $this->sistema($bucket);
        try {
            return $sistema->read($clave);
        } catch (FilesystemException $e) {
            throw $this->errorDeLectura($sistema, $clave, $e);
        }
    }

    public function borrar(string $clave, ?string $bucket = null): void
    {
        try {
            $this->sistema($bucket)->delete(self::claveSegura($clave, AlmacenNoDisponible::BORRADO));
        } catch (FilesystemException $e) {
            throw AlmacenNoDisponible::por(AlmacenNoDisponible::BORRADO, $e);
        }
    }

    protected function errorDeLectura(FilesystemOperator $sistema, string $clave, FilesystemException $error): AlmacenNoDisponible
    {
        return AlmacenNoDisponible::por(AlmacenNoDisponible::LECTURA, $error);
    }

    /** Clave relativa sin «.», «..» ni barras de más: nunca sale de la raíz del almacén. */
    private static function claveSegura(string $clave, string $codigoError): string
    {
        $partes = array_values(array_filter(explode('/', ltrim(str_replace('\\', '/', $clave), '/')), static fn (string $p): bool => '' !== $p));
        if ([] === $partes || array_intersect($partes, ['.', '..']) !== []) {
            throw AlmacenNoDisponible::por($codigoError, new \InvalidArgumentException('INVALID_STORAGE_OBJECT_KEY'));
        }

        return implode('/', $partes);
    }
}
