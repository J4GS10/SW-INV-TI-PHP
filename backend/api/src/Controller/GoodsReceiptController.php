<?php

declare(strict_types=1);

namespace App\Controller;

use App\Documentos\Idioma;
use App\Dominio\Ident;
use App\Dominio\Roles;
use App\GoodsReceipt\Application\GoodsReceiptAct;
use App\GoodsReceipt\Application\GoodsReceiptService;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorApi;
use App\Http\Idempotencia;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Purchasing registers; IT (technicians and administrators) confirms and completes; auditors read. */
#[Route('/api/v1/goods-receipts', requirements: ['id' => '\d+', 'line' => '\d+', 'model' => '\d+'])]
#[Autenticado(roles: self::READERS)]
final readonly class GoodsReceiptController
{
    private const array READERS = [Roles::ADMIN_TI, Roles::TECNICO, Roles::AUDITOR, Roles::CONSULTA, Roles::COMPRAS];
    private const array REGISTRARS = Roles::ESCRITURA_COMPRAS;

    public function __construct(
        private GoodsReceiptService $service,
        private GoodsReceiptAct $act,
        private Idempotencia $idempotency,
        private UsuarioActual $current,
    ) {
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

        return $body;
    }

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $status = $request->query->get('status');

        return new JsonResponse($this->service->list(null === $status ? null : (string) $status));
    }

    #[Route('', methods: ['POST'])]
    #[Autenticado(roles: self::REGISTRARS)]
    public function register(Request $request): JsonResponse
    {
        return $this->idempotency->ejecutar($request, fn (): array => $this->service->register(self::body($request), $this->user(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/form-context', methods: ['GET'])]
    #[Autenticado(roles: self::REGISTRARS)]
    public function formContext(): JsonResponse
    {
        return new JsonResponse($this->service->formContext($this->user()));
    }

    #[Route('/checks/document', methods: ['GET'])]
    #[Autenticado(roles: self::REGISTRARS)]
    public function checkDocument(Request $request): JsonResponse
    {
        return new JsonResponse(['exists' => $this->service->documentExists($request->query->getInt('supplier_id'), (string) $request->query->get('document_number', ''))]);
    }

    #[Route('/checks/serial', methods: ['GET'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function checkSerial(Request $request): JsonResponse
    {
        return new JsonResponse(['exists' => $this->service->serialExists((string) $request->query->get('value', ''), self::except($request))]);
    }

    #[Route('/checks/hostname', methods: ['GET'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function checkHostname(Request $request): JsonResponse
    {
        return new JsonResponse(['exists' => $this->service->hostnameExists((string) $request->query->get('value', ''), self::except($request))]);
    }

    #[Route('/classification-suggestion', methods: ['GET'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function suggestion(Request $request): JsonResponse
    {
        return new JsonResponse($this->service->suggestionFor((string) $request->query->get('text', '')));
    }

    #[Route('/pending-units', methods: ['GET'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function pendingUnits(): JsonResponse
    {
        return new JsonResponse($this->service->pendingUnits());
    }

    #[Route('/units/{asset}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function completeUnit(Request $request): JsonResponse
    {
        return new JsonResponse($this->service->completeUnit(Entrada::uuidDeRuta($request, 'asset'), self::body($request), $this->user(), ClienteHttp::ip($request)));
    }

    #[Route('/lines/{line}/license-keys', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function licenseKeys(int $line, Request $request): JsonResponse
    {
        $keys = array_values(array_filter(array_map(static fn (mixed $k): string => trim((string) $k), (array) (self::body($request)['keys'] ?? [])), static fn (string $k): bool => '' !== $k));

        return new JsonResponse($this->service->completeLicenseKeys($line, $keys, $this->user(), ClienteHttp::ip($request)));
    }

    #[Route('/catalog/models', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function createModel(Request $request): JsonResponse
    {
        $body = self::body($request);
        $result = $this->service->createModel((int) ($body['asset_type_id'] ?? 0), (string) ($body['brand'] ?? ''), (string) ($body['model'] ?? ''), $this->user(), ClienteHttp::ip($request));

        return new JsonResponse($result, $result['created'] ? 201 : 200);
    }

    #[Route('/catalog/models/{model}/spec-defaults', methods: ['GET'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function readModelDefaults(int $model): JsonResponse
    {
        return new JsonResponse($this->service->modelDefaults($model));
    }

    #[Route('/catalog/models/{model}/spec-defaults', methods: ['PUT'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function modelDefaults(int $model, Request $request): JsonResponse
    {
        $this->service->setModelDefaults($model, array_map('strval', (array) (self::body($request)['values'] ?? [])), $this->user(), ClienteHttp::ip($request));

        return new JsonResponse(['status' => 'success']);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return new JsonResponse($this->service->present($id));
    }

    #[Route('/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: self::REGISTRARS)]
    public function update(int $id, Request $request): JsonResponse
    {
        return new JsonResponse($this->service->update($id, self::body($request), $this->user(), ClienteHttp::ip($request)));
    }

    #[Route('/{id}/cancel', methods: ['POST'])]
    #[Autenticado(roles: self::REGISTRARS)]
    public function cancel(int $id, Request $request): JsonResponse
    {
        return new JsonResponse($this->service->cancel($id, (string) (self::body($request)['reason'] ?? ''), $this->user(), ClienteHttp::ip($request)));
    }

    #[Route('/{id}/confirm', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function confirm(int $id, Request $request): JsonResponse
    {
        $result = $this->service->confirm($id, self::body($request), $this->user(), ClienteHttp::ip($request));

        return new JsonResponse($result, 'PENDING_APPROVAL' === $result['status'] ? 202 : 200);
    }

    #[Route('/{id}/act.pdf', methods: ['GET'])]
    public function act(int $id, Request $request): Response
    {
        $pdf = $this->act->generate($id, Idioma::desde($request->query->get('lang') ?? null));

        return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"acta_recibido_{$id}.pdf\""]);
    }

    private static function except(Request $request): ?string
    {
        $asset = $request->query->get('except');

        return null === $asset || '' === $asset ? null : Ident::aHex((string) $asset);
    }
}
