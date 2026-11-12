<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Dominio\Roles;
use App\Http\Entrada;
use App\Http\Limite;
use App\Inventario\Infrastructure\Productividad;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Productividad (búsqueda global, kit sugerido, ficha de persona, calidad de datos) y tablero (/stats). Importación desde Excel: fase 4. */
final readonly class ProductividadController
{
    public function __construct(private Productividad $productividad, private UsuarioActual $actual)
    {
    }

    #[Route('/api/v1/productividad/buscar', methods: ['GET'])]
    #[Autenticado(roles: Roles::APP)]
    #[Limite('120/minute')]
    public function buscar(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        [, $q] = $e->campo(Campo::texto('q', true, 2, 100), true);
        $e->validar();

        return new JsonResponse($this->productividad->buscar((string) $q, $this->actual->usuario()->rol));
    }

    #[Route('/api/v1/productividad/kit-sugerido/{persona}', methods: ['GET'])]
    #[Autenticado(roles: Roles::APP)]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function kitSugerido(Request $request): JsonResponse
    {
        return new JsonResponse($this->productividad->kitSugerido(Entrada::uuidDeRuta($request, 'persona')));
    }

    #[Route('/api/v1/productividad/personas/{persona}/ficha', methods: ['GET'])]
    #[Autenticado(roles: Roles::APP)]
    #[Autenticado(roles: Roles::LECTURA_PERSONAS)]
    public function ficha(Request $request): JsonResponse
    {
        return new JsonResponse($this->productividad->ficha(Entrada::uuidDeRuta($request, 'persona')));
    }

    #[Route('/api/v1/productividad/calidad-datos', methods: ['GET'])]
    #[Autenticado(roles: Roles::APP)]
    #[Autenticado(roles: Roles::LECTURA_CONTROL)]
    public function calidadDatos(): JsonResponse
    {
        return new JsonResponse($this->productividad->calidadDatos());
    }

    /** KPIs del tablero, solo con las sedes del alcance; los costos los quita el filtro de roles sin valores. */
    #[Route('/api/v1/stats/dashboard', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_EXISTENCIAS)]
    public function tablero(): JsonResponse
    {
        return new JsonResponse($this->productividad->tablero($this->actual->usuario()->rol));
    }
}
