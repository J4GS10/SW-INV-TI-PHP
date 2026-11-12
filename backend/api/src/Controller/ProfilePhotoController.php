<?php

declare(strict_types=1);

namespace App\Controller;

use App\Adjuntos\Subida;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Http\Limite;
use App\ProfilePhoto\Domain\ProfilePhoto;
use App\ProfilePhoto\Infrastructure\ProfilePhotoRepository;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use App\Db\Transaccion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Profile photos: each person uploads or removes their own; whoever manages accounts sees the others'.
 * The image is re-encoded on upload (square, 256 px, WebP), so nothing else is ever stored or served.
 */
final readonly class ProfilePhotoController
{
    private const string OWN_PATH = '/api/v1/me/foto';

    public function __construct(
        private ProfilePhotoRepository $photos,
        private GobiernoRepositorio $audit,
        private Transaccion $transaccion,
        private UsuarioActual $current,
    ) {
    }

    #[Route(self::OWN_PATH, methods: ['PUT'])]
    #[Autenticado]
    #[Limite('10/minute')]
    public function upload(Request $request): JsonResponse
    {
        $upload = Subida::deFormulario($request, 'file', ProfilePhoto::MAX_UPLOAD_BYTES);
        if (\strlen($upload->contenido) > ProfilePhoto::MAX_UPLOAD_BYTES) {
            throw new ErrorApi(413, 'FILE_TOO_LARGE');
        }
        $photo = ProfilePhoto::fromUpload($upload->contenido) ?? throw new ErrorApi(400, 'PHOTO_MUST_BE_AN_IMAGE');
        $user = $this->current->usuario()->id;
        $this->transaccion->ejecutar(function () use ($user, $photo, $request): void {
            $this->photos->save($user, $photo);
            $this->audit->registrarAuditoria('UPDATE', 'INV_USUARIO_FOTO', ['cambios' => ['foto' => 'nueva']], $user, ClienteHttp::ip($request));
        });

        return new JsonResponse(['foto_url' => ProfilePhoto::url(self::OWN_PATH, $this->photos->updatedAt($user))]);
    }

    #[Route(self::OWN_PATH, methods: ['DELETE'])]
    #[Autenticado]
    public function remove(Request $request): Response
    {
        $user = $this->current->usuario()->id;
        $this->transaccion->ejecutar(function () use ($user, $request): void {
            if ($this->photos->delete($user)) {
                $this->audit->registrarAuditoria('DELETE', 'INV_USUARIO_FOTO', ['cambios' => ['foto' => 'quitada']], $user, ClienteHttp::ip($request));
            }
        });

        return new Response(null, 204);
    }

    #[Route(self::OWN_PATH, methods: ['GET'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('300/minute')]
    public function own(): Response
    {
        return $this->serve($this->current->usuario()->id);
    }

    #[Route('/api/v1/org/usuarios/{id}/foto', methods: ['GET'], priority: 10)]
    #[Autenticado(roles: Roles::IAM)]
    #[Limite('600/minute')]
    public function ofUser(Request $request): Response
    {
        return $this->serve(Entrada::uuidDeRuta($request));
    }

    /** The URL carries the version, so the copy in the browser never goes stale. */
    private function serve(string $userHex): Response
    {
        $photo = $this->photos->find($userHex) ?? throw new ErrorApi(404, 'PHOTO_NOT_FOUND');

        return new Response($photo['bytes'], 200, [
            'Content-Type' => $photo['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
