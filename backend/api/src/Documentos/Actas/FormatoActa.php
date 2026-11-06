<?php

declare(strict_types=1);

namespace App\Documentos\Actas;

use App\Documentos\Descarga;

/** Formato del archivo del acta: Word (por defecto) o PDF. */
enum FormatoActa: string
{
    case Docx = 'docx';
    case Pdf = 'pdf';

    public const string PATRON = '^(docx|pdf)$';

    public function tipoMime(): string
    {
        return self::Pdf === $this ? Descarga::PDF : Descarga::DOCX;
    }
}
