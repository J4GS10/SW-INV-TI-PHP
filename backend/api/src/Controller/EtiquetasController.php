<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Peticion;
use App\Http\Descarga;
use App\Documentos\Etiquetas;
use App\Dominio\Roles;
use App\Validacion\ErrorValidacion;
use App\Seguridad\Autenticado;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Etiquetas QR imprimibles de activos (por id o por código interno). */
#[Route('/api/v1/core')]
#[Autenticado(roles: Roles::LECTURA_EXISTENCIAS)]
final readonly class EtiquetasController
{
    public function __construct(private Etiquetas $etiquetas)
    {
    }

    #[Route('/activos/etiquetas.pdf', methods: ['POST'], priority: 1)]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function generar(Request $request): Response
    {
        $cuerpo = Peticion::json($request);
        $activos = $cuerpo->uuids('activos_ids', \PHP_INT_MAX) ?? [];
        $codigos = $cuerpo->textos('codigos', \PHP_INT_MAX) ?? [];
        $cuerpo->validar();
        $total = \count($activos) + \count($codigos);
        if (0 === $total) {
            throw ErrorValidacion::deModelo('LABEL_ASSETS_REQUIRED', Peticion::cuerpo($request));
        }
        if ($total > Etiquetas::MAXIMO) {
            throw ErrorValidacion::deModelo('TOO_MANY_LABELS_REQUESTED', Peticion::cuerpo($request));
        }
        $e = Peticion::consulta($request);
        $idioma = $e->idioma();
        $e->validar();

        return Descarga::archivo($this->etiquetas->generar($activos, $codigos, $idioma), \App\Documentos\TiposMime::PDF, Etiquetas::nombreArchivo($idioma));
    }
}
