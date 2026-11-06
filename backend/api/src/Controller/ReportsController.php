<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Dominio\Roles;
use App\Http\Entrada;
use App\Reporting\PurchasingReports;
use App\Seguridad\Autenticado;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/reports')]
#[Autenticado(roles: Roles::EXPORTACION_FINANZAS)]
final readonly class ReportsController
{
    public function __construct(
        private PurchasingReports $reports,
        private \App\Reporting\PurchasingReportsCsv $files,
        private \App\Repositorio\GobiernoRepositorio $audit,
        private \App\Seguridad\UsuarioActual $currentUser,
    ) {
    }

    /** The same rows as the screen, as a file. Every export leaves its record. */
    #[Route('/{report}.csv', requirements: ['report' => 'stock|entries|assignments'], methods: ['GET'], priority: 10)]
    #[\App\Http\Limite('10/minute')]
    public function export(string $report, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        [$from, $to, $site] = self::period($request);
        $input = Entrada::consulta($request);
        $language = $input->idioma();
        $input->validar();
        $content = $this->files->build($report, $from, $to, $site, $language);
        $this->audit->registrarAuditoria('REPORT_EXPORT', 'REPORT', ['reporte' => $report, 'desde' => $from, 'hasta' => $to], $this->currentUser->usuario()->id, \App\Http\ClienteHttp::ip($request));

        return \App\Documentos\Descarga::csv($content, "{$report}_".\App\Dominio\Reloj::ahora()->format('Ymd_His').'.csv');
    }

    #[Route('/stock', methods: ['GET'])]
    public function stock(Request $request): JsonResponse
    {
        return new JsonResponse($this->reports->stock(self::period($request)[2]));
    }

    #[Route('/entries', methods: ['GET'])]
    public function entries(Request $request): JsonResponse
    {
        return new JsonResponse($this->reports->entries(...self::period($request)));
    }

    #[Route('/assignments', methods: ['GET'])]
    public function assignments(Request $request): JsonResponse
    {
        return new JsonResponse($this->reports->assignments(...self::period($request)));
    }

    /** @return array{0: ?string, 1: ?string, 2: ?int} */
    private static function period(Request $request): array
    {
        $input = Entrada::consulta($request);
        [, $from] = $input->campo(new Campo('desde', Campo::FECHA), false);
        [, $to] = $input->campo(new Campo('hasta', Campo::FECHA), false);
        [, $site] = $input->campo(Campo::entero('sede_id'), false);
        $input->validar();

        return [$from, $to, null === $site ? null : (int) $site];
    }
}
