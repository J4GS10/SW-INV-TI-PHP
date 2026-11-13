<?php

declare(strict_types=1);

namespace App\Documentos;

/** Tipos MIME de los documentos que el sistema genera. */
final class TiposMime
{
    public const string CSV = 'text/csv; charset=utf-8';
    public const string PDF = 'application/pdf';
    public const string DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    public const string XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
}
