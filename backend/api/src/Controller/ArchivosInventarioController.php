<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Documentos\CierreExcel;
use App\Documentos\Descarga;
use App\Documentos\FormatoLocal;
use App\Documentos\HojaDeVida;
use App\Documentos\Idioma;
use App\Documentos\ReportesCsv;
use App\Dominio\Ident;
use App\Dominio\Roles;
use App\Http\ConsultaKardex;
use App\Http\Entrada;
use App\Http\Limite;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Archivos del inventario: CSV del kardex, de la custodia, de las licencias y
 * del resultado de una toma; hoja de vida del activo en PDF y cierre mensual en
 * Excel. Mismo alcance que las pantallas equivalentes.
 */
#[Route('/api/v1/inventario', requirements: ['id' => '\d+'])]
#[Autenticado(roles: Roles::APP)]
final readonly class ArchivosInventarioController
{
    private const array NOMBRES = [
        'kardex' => ['es' => 'kardex', 'en' => 'inventory_ledger', 'it' => 'registro_inventario'],
        'custodia' => ['es' => 'custodia', 'en' => 'custody', 'it' => 'custodia'],
        'licencias' => ['es' => 'licencias', 'en' => 'licenses', 'it' => 'licenze'],
        'toma' => ['es' => 'toma_inventario', 'en' => 'inventory_count', 'it' => 'inventario_fisico'],
    ];

    public function __construct(
        private ReportesCsv $csv,
        private HojaDeVida $hojaDeVida,
        private CierreExcel $cierre,
        private FormatoLocal $formato,
        private UsuarioActual $actual,
    ) {
    }

    #[Route('/kardex.csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION)]
    #[Limite('10/minute')]
    public function kardex(Request $request): Response
    {
        $e = Entrada::consulta($request);
        $filtros = ConsultaKardex::filtros($request, $e);
        $idioma = $e->idioma();
        $e->validar();

        return Descarga::csv($this->csv->kardex($filtros, $idioma), self::nombre('kardex', $idioma, $this->formato->hoyCompacto()));
    }

    #[Route('/custodia.csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION)]
    #[Limite('10/minute')]
    public function custodia(Request $request): Response
    {
        $e = Entrada::consulta($request);
        [, $fecha] = $e->campo(new Campo('fecha', Campo::FECHA), false);
        [, $persona] = $e->campo(new Campo('persona_id', Campo::UUID), false);
        [, $area] = $e->campo(Campo::entero('area_id'), false);
        [, $sede] = $e->campo(Campo::entero('sede_id'), false);
        $idioma = $e->idioma();
        $e->validar();

        $contenido = $this->csv->custodia($fecha, null === $persona ? null : Ident::aHex((string) $persona),
            null === $area ? null : (int) $area, null === $sede ? null : (int) $sede, $idioma);

        return Descarga::csv($contenido, self::nombre('custodia', $idioma, null === $fecha ? $this->formato->hoyCompacto() : str_replace('-', '', (string) $fecha)));
    }

    /** Historia completa del activo; los roles sin costos no ven el costo. */
    #[Route('/activos/{activo}/hoja-de-vida.pdf', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_INVENTARIO)]
    #[Limite('20/minute')]
    public function hojaDeVida(Request $request): Response
    {
        $activo = Entrada::uuidDeRuta($request, 'activo');
        $e = Entrada::consulta($request);
        $idioma = $e->idioma();
        $e->validar();
        [$contenido, $nombre] = $this->hojaDeVida->generar($activo, $idioma, !\in_array($this->actual->usuario()->rol, Roles::SIN_COSTOS, true));

        return Descarga::archivo($contenido, Descarga::PDF, $nombre);
    }

    #[Route('/licencias.csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION_FINANZAS)]
    #[Limite('10/minute')]
    public function licencias(Request $request): Response
    {
        $idioma = self::soloIdioma($request);

        return Descarga::csv($this->csv->licencias($idioma), self::nombre('licencias', $idioma, $this->formato->hoyCompacto()));
    }

    #[Route('/cierre-mensual.xlsx', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION_FINANZAS)]
    #[Limite('10/minute')]
    public function cierreMensual(Request $request): Response
    {
        $e = Entrada::consulta($request);
        [, $anio] = $e->campo(Campo::entero('anio', true, minimo: 2000, maximo: 2100), true);
        [, $mes] = $e->campo(Campo::entero('mes', true, minimo: 1, maximo: 12), true);
        [, $sede] = $e->campo(Campo::entero('sede_id'), false);
        $idioma = $e->idioma();
        $e->validar();

        return Descarga::archivo($this->cierre->generar((int) $anio, (int) $mes, null === $sede ? null : (int) $sede, $idioma), Descarga::XLSX,
            CierreExcel::nombreArchivo((int) $anio, (int) $mes, $idioma));
    }

    /** Lo que la toma no encontró donde debía (faltantes, otra ubicación o sede, de baja, no registrados). */
    #[Route('/tomas/{id}/reporte.csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION)]
    public function toma(int $id, Request $request): Response
    {
        $idioma = self::soloIdioma($request);

        return Descarga::csv($this->csv->toma($id, $idioma), self::nombre('toma', $idioma, (string) $id));
    }

    private static function soloIdioma(Request $request): Idioma
    {
        $e = Entrada::consulta($request);
        $idioma = $e->idioma();
        $e->validar();

        return $idioma;
    }

    private static function nombre(string $reporte, Idioma $idioma, string $sufijo): string
    {
        return $idioma->de(self::NOMBRES[$reporte])."_{$sufijo}.csv";
    }
}
