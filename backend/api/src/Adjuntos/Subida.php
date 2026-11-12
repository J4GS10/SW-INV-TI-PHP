<?php

declare(strict_types=1);

namespace App\Adjuntos;

use App\Dominio\ErrorDominio;
use App\Http\ErrorValidacion;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/** Archivo recibido en un formulario multipart: nombre del cliente y contenido. */
final readonly class Subida
{
    public function __construct(public ?string $nombre, public string $contenido)
    {
    }

    /**
     * El campo de archivo obligatorio del formulario; si falta, 422 como FastAPI
     * (`loc: ["body", "<campo>"]`). Se leen hasta `$maximo` + 1 bytes: quien llama
     * decide qué hacer con un archivo demasiado grande.
     */
    public static function deFormulario(Request $request, string $campo, ?int $maximo = null): self
    {
        $archivo = $request->files->get($campo);
        if (!$archivo instanceof UploadedFile) {
            throw new ErrorValidacion([['type' => 'missing', 'loc' => ['body', $campo], 'msg' => 'Field required', 'input' => null]]);
        }
        if (!$archivo->isValid()) {
            // Excede upload_max_filesize de PHP o llegó incompleto.
            throw \in_array($archivo->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? ErrorDominio::demasiadoGrande('FILE_TOO_LARGE')
                : ErrorDominio::invalido('UPLOAD_FAILED');
        }
        $contenido = file_get_contents($archivo->getPathname(), length: null === $maximo ? null : $maximo + 1);

        return new self($archivo->getClientOriginalName(), false === $contenido ? '' : $contenido);
    }
}
