<?php

declare(strict_types=1);

namespace App\Controller;

use App\Accounting\Application\AccountingCategories;
use App\Accounting\Application\AssetRegisterCsv;
use App\Accounting\Application\AssetValuation;
use App\Crud\Campo;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Descarga;
use App\Http\ErrorApi;
use App\Http\Limite;
use App\Http\Peticion;
use App\Http\Versioned;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use App\Validacion\Entrada;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fixed-asset accounting: categories with their depreciation rule, the value of an asset with its
 * components, of a person's workstation and of the whole books. Amounts only reach the roles that see costs.
 */
#[Route('/api/v1/accounting', requirements: ['id' => '\d+'])]
#[Autenticado(roles: Roles::VALORACION)]
final readonly class AccountingController
{
    public function __construct(
        private AccountingCategories $categories,
        private AssetValuation $valuation,
        private AssetRegisterCsv $register,
        private GobiernoRepositorio $audit,
        private UsuarioActual $current,
        private \App\Config\Ajustes $settings,
    ) {
    }

    #[Route('/categories', methods: ['GET'])]
    public function categories(): JsonResponse
    {
        return new JsonResponse($this->categories->list());
    }

    #[Route('/categories', methods: ['POST'])]
    #[Autenticado(roles: Roles::CONFIGURACION_CONTABLE)]
    public function createCategory(Request $request): JsonResponse
    {
        return new JsonResponse($this->categories->create(self::body($request), $this->user(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/categories/{id}', methods: ['PATCH'])]
    #[Versioned('FIN_ASSET_CATEGORY', 'FAC_ASSET_CATEGORY')]
    #[Autenticado(roles: Roles::CONFIGURACION_CONTABLE)]
    public function updateCategory(Request $request): JsonResponse
    {
        return new JsonResponse($this->categories->update(Peticion::enteroDeRuta($request), self::body($request), $this->user(), ClienteHttp::ip($request)));
    }

    /** An asset with every component installed in it, its yearly table and the totals of the equipment. */
    #[Route('/assets/{asset}/valuation', methods: ['GET'])]
    #[Autenticado(roles: Roles::VALORACION_DETALLE)]
    public function asset(Request $request): JsonResponse
    {
        return new JsonResponse($this->valuation->forAsset(Peticion::uuidDeRuta($request, 'asset'), $this->asOf($request)));
    }

    /** What a person has in custody, components included: how much their workstation is worth. */
    #[Route('/persons/{person}/workstation', methods: ['GET'])]
    #[Autenticado(roles: Roles::VALORACION_DETALLE)]
    public function workstation(Request $request): JsonResponse
    {
        return new JsonResponse($this->valuation->forPerson(Peticion::uuidDeRuta($request, 'person'), $this->asOf($request)));
    }

    #[Route('/summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        // Por persona y por departamento solo para quien lee personas: Compras ve categorías, tipos y sedes.
        $people = \in_array($this->current->usuario()->rol, Roles::LECTURA_PERSONAS, true);

        return new JsonResponse($this->valuation->summary($this->asOf($request), self::site($request), $people));
    }

    /** The fixed-asset register as a file. Every export leaves its record. */
    #[Route('/register.csv', methods: ['GET'], priority: 10)]
    #[Limite('10/minute')]
    public function export(Request $request): Response
    {
        $asOf = $this->asOf($request);
        $site = self::site($request);
        $input = Peticion::consulta($request);
        $language = $input->idioma();
        $input->validar();
        $content = $this->register->build($asOf, $site, $language);
        $this->audit->registrarAuditoria('REPORT_EXPORT', 'REPORT', ['reporte' => 'fixed_asset_register', 'fecha' => $asOf->format('Y-m-d'), 'sede' => $site],
            $this->user(), ClienteHttp::ip($request));

        return Descarga::csv($content, 'activos_fijos_'.$asOf->format('Ymd').'.csv');
    }

    private function user(): string
    {
        return $this->current->usuario()->id;
    }

    /** @return array<string, mixed> */
    private static function body(Request $request): array
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new ErrorApi(400, 'INVALID_JSON_BODY');
        }
        Entrada::exigirTextoLimpio($body);

        return $body;
    }

    /** The date of the valuation (`as_of`, YYYY-MM-DD); without it, today in the organization's time zone (not UTC). */
    private function asOf(Request $request): \DateTimeImmutable
    {
        $input = Peticion::consulta($request);
        [, $date] = $input->campo(new Campo('as_of', Campo::FECHA), false);
        $input->validar();
        $today = Reloj::ahora()->setTimezone(new \DateTimeZone($this->settings->zonaHoraria))->format('Y-m-d');

        return new \DateTimeImmutable(null === $date ? $today : (string) $date);
    }

    private static function site(Request $request): ?int
    {
        $input = Peticion::consulta($request);
        [, $site] = $input->campo(Campo::entero('site_id'), false);
        $input->validar();

        return null === $site ? null : (int) $site;
    }
}
