<?php

declare(strict_types=1);

namespace App\Controller;

use App\Adjuntos\Subida;
use App\Crud\Campo;
use App\Documentos\Descarga;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Http\Limite;
use App\Importacion\ImportacionActivos;
use App\Importacion\LectorExcel;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Importación de activos desde Excel: plantilla, vista previa (sin guardar) y confirmación. */
#[Route('/api/v1/productividad/importar/activos')]
#[Autenticado(roles: Roles::APP)]
#[Autenticado(roles: Roles::ADMIN)]
final readonly class ImportacionController
{
    public function __construct(private ImportacionActivos $importacion, private UsuarioActual $actual)
    {
    }

    /** Plantilla .xlsx con los encabezados y las listas de categorías, ubicaciones y marcas. */
    #[Route('/plantilla', methods: ['GET'])]
    public function plantilla(): Response
    {
        return Descarga::archivo($this->importacion->plantilla(), Descarga::XLSX, 'plantilla_activos.xlsx');
    }

    /** Valida el archivo sin guardar nada y devuelve el resultado fila por fila. */
    #[Route('/previsualizar', methods: ['POST'])]
    #[Limite('30/minute')]
    public function previsualizar(Request $request): JsonResponse
    {
        return new JsonResponse($this->importacion->previsualizar(self::archivo($request)));
    }

    /** Vuelve a validar y crea los activos en una sola transacción. */
    #[Route('/confirmar', methods: ['POST'])]
    #[Limite('10/minute')]
    public function confirmar(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        [, $omitir] = $e->campo(Campo::logico('omitir_errores'), false);
        $e->validar();
        $contenido = self::archivo($request);

        return new JsonResponse($this->importacion->confirmar($contenido, true === $omitir, $this->actual->usuario()->id, ClienteHttp::ip($request)), 201);
    }

    /** Contenido del campo `archivo` (hasta 5 MB; si pasa, 413). */
    private static function archivo(Request $request): string
    {
        $contenido = Subida::deFormulario($request, 'archivo', LectorExcel::MAX_BYTES)->contenido;
        if (\strlen($contenido) > LectorExcel::MAX_BYTES) {
            throw new ErrorApi(413, 'FILE_TOO_LARGE');
        }

        return $contenido;
    }
}
