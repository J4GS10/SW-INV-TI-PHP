<?php

declare(strict_types=1);

namespace App\Http;

use App\Adjuntos\Subida;
use App\Crud\Campo;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Validacion\Entrada;
use App\Validacion\ErrorValidacion;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lo que una petición HTTP trae, listo para validar: su cuerpo JSON, sus parámetros de consulta y los de su
 * ruta, como App\Validacion\Entrada. Es el único lugar donde la validación de entradas toca la petición.
 *
 *   $e = Peticion::json($request);
 *   $codigo = $e->texto('code', min: 4, max: 10);
 *   $e->validar();
 */
final class Peticion
{
    /** Cuerpo JSON (objeto). Sin cuerpo = objeto vacío; JSON inválido = 422. */
    public static function json(Request $request): Entrada
    {
        $contenido = trim($request->getContent());
        if ('' === $contenido) {
            return Entrada::de([]);
        }
        try {
            $datos = json_decode($contenido, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ErrorValidacion([['type' => 'json_invalid', 'loc' => ['body', 0], 'msg' => 'JSON decode error',
                'input' => [], 'ctx' => ['error' => $e->getMessage()]]]);
        }
        // «[]» y «{}» se decodifican igual: la lista vacía se distingue por el texto.
        if (!\is_array($datos) || array_is_list($datos) && ([] !== $datos || str_starts_with($contenido, '['))) {
            throw new ErrorValidacion([['type' => 'model_attributes_type', 'loc' => ['body'],
                'msg' => 'Input should be a valid dictionary or object to extract fields from', 'input' => $datos]]);
        }
        Entrada::exigirTextoLimpio($datos);

        return Entrada::de($datos);
    }

    /** Parámetros de la URL (?a=1&b=2). Los valores llegan como texto. */
    public static function consulta(Request $request): Entrada
    {
        $datos = $request->query->all();
        Entrada::exigirTextoLimpio($datos, 'query');

        return Entrada::de($datos, 'query');
    }

    /** Parámetros de la ruta (/recurso/{id}). */
    public static function ruta(Request $request): Entrada
    {
        return Entrada::de((array) $request->attributes->get('_route_params', []), 'path');
    }

    /** Parámetro de ruta entero (p. ej. {id}); 422 si no lo es. */
    public static function enteroDeRuta(Request $request, string $nombre = 'id'): int
    {
        $e = self::ruta($request);
        [, $valor] = $e->campo(Campo::entero($nombre, requerido: true), true);
        $e->validar();

        return (int) $valor;
    }

    /** Parámetro de ruta UUID (hexadecimal para la base); 422 si no lo es. */
    public static function uuidDeRuta(Request $request, string $nombre = 'id'): string
    {
        $e = self::ruta($request);
        [, $valor] = $e->campo(new Campo($nombre, Campo::UUID, true), true);
        $e->validar();

        return Ident::aHex((string) $valor);
    }

    /**
     * Paginación skip/limit con cota superior fija (anti-abuso).
     *
     * @return array{0: int, 1: int} [saltar, límite]
     */
    public static function paginacion(Request $request, int $maximo = 200, int $defecto = 50): array
    {
        $e = self::consulta($request);
        [$hay, $saltar] = $e->campo(Campo::entero('skip', minimo: 0), false);
        [$hayLimite, $limite] = $e->campo(Campo::entero('limit', minimo: 1, maximo: $maximo), false);
        $e->validar();

        return [$hay && null !== $saltar ? (int) $saltar : 0, $hayLimite && null !== $limite ? (int) $limite : $defecto];
    }

    /** El cuerpo JSON ya decodificado, tal como llegó (null si no es JSON): para citarlo en un error de validación. */
    public static function cuerpo(Request $request): mixed
    {
        return json_decode($request->getContent(), true);
    }
    /**
     * El archivo obligatorio de un formulario multipart; si falta, error de validación con `loc: ["body", "<campo>"]`.
     * Se leen hasta `$maximo` + 1 bytes: quien llama decide qué hacer con un archivo demasiado grande.
     */
    public static function archivo(Request $request, string $campo, ?int $maximo = null): Subida
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

        return new Subida($archivo->getClientOriginalName(), false === $contenido ? '' : $contenido);
    }
}
