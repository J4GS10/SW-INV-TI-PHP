<?php

declare(strict_types=1);

namespace App\Adjuntos;

use App\Config\Ajustes;

/** Elige el almacén: el configurado para los nuevos adjuntos y el de cada adjunto guardado. */
final readonly class Almacenes
{
    private AlmacenLocal $local;
    private AlmacenS3 $s3;

    public function __construct(private Ajustes $ajustes)
    {
        $this->local = new AlmacenLocal($ajustes->directorioAdjuntos);
        $this->s3 = new AlmacenS3($ajustes);
    }

    /** Donde se guardan los adjuntos nuevos (STORAGE_BACKEND). */
    public function actual(): Almacen
    {
        return $this->de($this->ajustes->almacenamiento);
    }

    /** El almacén con que se guardó un adjunto (los antiguos sin backend son locales). */
    public function de(?string $backend): Almacen
    {
        return AlmacenS3::NOMBRE === $backend ? $this->s3 : $this->local;
    }
}
