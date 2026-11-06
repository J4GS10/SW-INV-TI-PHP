<?php

declare(strict_types=1);

namespace App\Documentos\Actas;

/** Dibuja un acta en un formato de archivo. */
interface GeneradorActa
{
    public function generar(DatosActa $acta): string;
}
