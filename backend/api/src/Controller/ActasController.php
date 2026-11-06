<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Documentos\Actas\Actas;
use App\Documentos\Actas\FormatoActa;
use App\Documentos\Actas\TipoActa;
use App\Documentos\Descarga;
use App\Documentos\Idioma;
use App\Dominio\Roles;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Http\ErrorValidacion;
use App\Seguridad\Autenticado;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Actas de entrega y hojas de descargo (Word o PDF) de uno o varios movimientos. */
#[Route('/api/v1/trazabilidad')]
#[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
#[Autenticado(roles: Roles::OPERATIVO)]
final readonly class ActasController
{
    public function __construct(private Actas $actas)
    {
    }

    #[Route('/acta/{movimiento}', methods: ['GET'])]
    public function acta(Request $request): Response
    {
        $movimiento = Entrada::uuidDeRuta($request, 'movimiento');
        [$formato, $tipo, $idioma, $mensajero] = self::opciones($request);

        return $this->descarga($this->actas->generar([$movimiento], $formato, $tipo, $mensajero, $idioma), $formato,
            $this->actas->nombreArchivo($tipo, $idioma, $movimiento));
    }

    /** Una sola acta para varios movimientos de la misma persona (hasta 50). */
    #[Route('/acta/lote', methods: ['POST'], priority: 1)]
    public function lote(Request $request): Response
    {
        $cuerpo = Entrada::json($request);
        if (!$cuerpo->tiene('movimientos_ids')) {
            throw new ErrorValidacion([['type' => 'missing', 'loc' => ['body', 'movimientos_ids'], 'msg' => 'Field required', 'input' => null]]);
        }
        // El tope (50) se aplica después, con su propio código, como en el backend anterior.
        $movimientos = $cuerpo->uuids('movimientos_ids', \PHP_INT_MAX) ?? [];
        $cuerpo->validar();
        [$formato, $tipo, $idioma, $mensajero] = self::opciones($request);
        if (\count($movimientos) > Actas::MAX_MOVIMIENTOS) {
            throw new ErrorApi(400, 'TOO_MANY_MOVEMENTS_REQUESTED');
        }

        return $this->descarga($this->actas->generar($movimientos, $formato, $tipo, $mensajero, $idioma), $formato,
            $this->actas->nombreArchivo($tipo, $idioma, null));
    }

    /** @return array{0: FormatoActa, 1: TipoActa, 2: Idioma, 3: ?string} */
    private static function opciones(Request $request): array
    {
        $e = Entrada::consulta($request);
        $formato = FormatoActa::from($e->patron('formato', FormatoActa::PATRON) ?? FormatoActa::Docx->value);
        $tipo = TipoActa::from($e->patron('tipo', TipoActa::PATRON) ?? TipoActa::Entrega->value);
        $idioma = $e->idioma();
        [, $mensajero] = $e->campo(Campo::texto('mensajero', max: 120), false);
        $e->validar();

        return [$formato, $tipo, $idioma, null === $mensajero ? null : (string) $mensajero];
    }

    private function descarga(string $contenido, FormatoActa $formato, string $nombre): Response
    {
        return Descarga::archivo($contenido, $formato->tipoMime(), "{$nombre}.{$formato->value}");
    }
}
