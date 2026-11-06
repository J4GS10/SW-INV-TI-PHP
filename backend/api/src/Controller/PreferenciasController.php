<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Entrada;
use App\Http\Limite;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Preferencias de interfaz (tema y guías de ayuda), guardadas como JSON en
 * INV_USUARIO.USU_Preferencias para que sigan a la persona en cualquier equipo.
 */
#[Route('/api/v1/me/preferencias')]
final readonly class PreferenciasController
{
    private const array TEMAS = ['claro', 'oscuro', 'sistema'];
    private const int MAX_GUIAS = 50;
    private const array DEFECTO = ['tema' => 'sistema', 'guias_desactivadas' => false, 'guias_vistas' => []];

    public function __construct(private UsuarioRepositorio $usuarios)
    {
    }

    #[Route('', methods: ['GET'])]
    #[Autenticado(permitirRestringida: true)]
    public function leer(UsuarioActual $actual): JsonResponse
    {
        return new JsonResponse($this->actuales($actual->usuario()->id));
    }

    /** Cambio parcial: solo se aplican los campos enviados. */
    #[Route('', methods: ['PATCH'])]
    #[Autenticado(permitirRestringida: true)]
    #[Limite('60/minute')]
    public function cambiar(Request $request, UsuarioActual $actual): JsonResponse
    {
        $entrada = Entrada::json($request);
        $tema = $entrada->opcion('tema', self::TEMAS);
        $desactivadas = $entrada->logico('guias_desactivadas');
        $guias = $entrada->lista('guias_vistas', self::MAX_GUIAS);
        $guias = null === $guias ? null : self::guiasValidas($guias, $entrada);
        $entrada->validar();

        $id = $actual->usuario()->id;
        $nuevas = array_merge($this->actuales($id), array_filter(
            ['tema' => $tema, 'guias_desactivadas' => $desactivadas, 'guias_vistas' => $guias],
            static fn (mixed $v): bool => null !== $v,
        ));
        $this->usuarios->guardarPreferencias($id, json_encode($nuevas, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        return new JsonResponse($nuevas);
    }

    /** @return array{tema: string, guias_desactivadas: bool, guias_vistas: list<string>} */
    private function actuales(string $usuarioHex): array
    {
        $crudo = $this->usuarios->preferencias($usuarioHex);
        $datos = null === $crudo ? null : json_decode($crudo, true);
        if (!\is_array($datos)) {
            return self::DEFECTO;
        }
        // Un valor dañado vuelve a los valores por defecto.
        $entrada = Entrada::de($datos);
        $tema = $entrada->opcion('tema', self::TEMAS);
        $desactivadas = $entrada->logico('guias_desactivadas');
        $guias = $entrada->lista('guias_vistas', self::MAX_GUIAS);
        $guias = null === $guias ? null : self::guiasValidas($guias, $entrada);
        try {
            $entrada->validar();
        } catch (\Throwable) {
            return self::DEFECTO;
        }

        return ['tema' => $tema ?? 'sistema', 'guias_desactivadas' => $desactivadas ?? false, 'guias_vistas' => $guias ?? []];
    }

    /**
     * Identificadores de módulo (letras, dígitos, _ y -), sin repetir.
     *
     * @param list<mixed> $guias
     *
     * @return list<string>|null
     */
    private static function guiasValidas(array $guias, Entrada $entrada): ?array
    {
        $limpias = [];
        foreach ($guias as $guia) {
            $guia = \is_string($guia) ? trim($guia) : '';
            if ('' === $guia || mb_strlen($guia) > 40 || 1 !== preg_match('/^[\p{L}\p{N}_-]+$/u', $guia)) {
                $entrada->errorDeValor('guias_vistas', 'GUIA_INVALIDA', $guias);

                return null;
            }
            if (!\in_array($guia, $limpias, true)) {
                $limpias[] = $guia;
            }
        }

        return $limpias;
    }
}
