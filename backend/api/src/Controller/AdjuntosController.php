<?php

declare(strict_types=1);

namespace App\Controller;

use App\Adjuntos\Adjuntos;
use App\Adjuntos\Dueno;
use App\Adjuntos\Subida;
use App\Adjuntos\TiposArchivo;
use App\Crud\Campo;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\Limite;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Adjuntos (/adjuntos): factura, foto, acta firmada… de activos, ingresos
 * y licencias. Cada adjunto hereda el permiso de su dueño (App\Adjuntos\TipoDueno).
 */
#[Route('/api/v1/adjuntos', requirements: ['id' => '\d+'])]
#[Autenticado(roles: Roles::APP)]
final readonly class AdjuntosController
{
    public function __construct(private Adjuntos $adjuntos, private UsuarioActual $actual)
    {
    }

    #[Route('/activos/{activo}', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
    public function deActivo(Request $request): JsonResponse
    {
        return new JsonResponse($this->adjuntos->listar(Dueno::activo(Entrada::uuidDeRuta($request, 'activo'))));
    }

    #[Route('/activos/{activo}', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    #[Limite('30/minute')]
    public function subirAActivo(Request $request): JsonResponse
    {
        return $this->subir($request, Dueno::activo(Entrada::uuidDeRuta($request, 'activo')), true);
    }

    #[Route('/ingresos/{id}', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_COMPRAS)]
    public function deIngreso(int $id): JsonResponse
    {
        return new JsonResponse($this->adjuntos->listar(Dueno::ingreso($id)));
    }

    #[Route('/ingresos/{id}', methods: ['POST'])]
    #[Autenticado(roles: Roles::ESCRITURA_COMPRAS)]
    #[Limite('30/minute')]
    public function subirAIngreso(int $id, Request $request): JsonResponse
    {
        return $this->subir($request, Dueno::ingreso($id), true);
    }

    /** Archivos de licencia: solo administradores (pueden contener la activación). */
    #[Route('/licencias/{id}', methods: ['GET'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function deLicencia(int $id): JsonResponse
    {
        return new JsonResponse($this->adjuntos->listar(Dueno::licencia($id)));
    }

    #[Route('/licencias/{id}', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    #[Limite('30/minute')]
    public function subirALicencia(int $id, Request $request): JsonResponse
    {
        return $this->subir($request, Dueno::licencia($id), false);
    }

    /** Descarga forzada, con el MIME del servidor y en un contexto aislado (sandbox). */
    #[Route('/{adjunto}/download', methods: ['GET'])]
    public function descargar(Request $request): Response
    {
        [$fila, $contenido] = $this->adjuntos->descargar(Entrada::uuidDeRuta($request, 'adjunto'), $this->actual->usuario()->rol);
        $nombre = TiposArchivo::sanearNombre($fila['ADJ_Nombre_Original']);
        $ascii = str_replace(['"', '\\'], '', (string) iconv('UTF-8', 'ASCII//IGNORE', $nombre)) ?: 'archivo';

        return new Response($contenido, 200, [
            'Content-Type' => TiposArchivo::mime(TiposArchivo::extension($nombre)),
            'Content-Disposition' => \sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($nombre)),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }

    #[Route('/{adjunto}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminar(Request $request): Response
    {
        $this->adjuntos->eliminar(Entrada::uuidDeRuta($request, 'adjunto'), $this->actual->usuario()->id, ClienteHttp::ip($request));

        return new Response(null, 204);
    }

    /** Formulario multipart: `file` (obligatorio), `categoria` (si el dueño la admite) y `descripcion`. */
    private function subir(Request $request, Dueno $dueno, bool $conCategoria): JsonResponse
    {
        $formulario = Entrada::de($request->request->all());
        [, $descripcion] = $formulario->campo(Campo::texto('descripcion', max: 255), true);
        $formulario->validar();
        $subida = Subida::deFormulario($request, 'file');
        $categoria = $conCategoria ? $request->request->getString('categoria') : null;

        return new JsonResponse($this->adjuntos->subir($dueno, $subida, '' === $categoria ? null : $categoria, $descripcion,
            $this->actual->usuario()->id, ClienteHttp::ip($request)), 201);
    }
}
