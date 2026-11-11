<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Documentos\Idioma;
use App\Dominio\Ident;
use App\Dominio\Roles;
use App\GoodsReceipt\Application\GoodsReceiptAct;
use App\GoodsReceipt\Application\GoodsReceiptService;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorApi;
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
        Entrada::exigirTextoLimpio($body);

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
        return new JsonResponse($this->service->register(self::body($request), $this->user(), ClienteHttp::ip($request)), 201);
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
        $e = Entrada::de(self::body($request));
        $lista = $e->textos('keys', 500) ?? [];
        $e->validar();
        $keys = array_values(array_filter(array_map(trim(...), $lista), static fn (string $k): bool => '' !== $k));

        return new JsonResponse($this->service->completeLicenseKeys($line, $keys, $this->user(), ClienteHttp::ip($request)));
    }

    #[Route('/catalog/models', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function createModel(Request $request): JsonResponse
    {
        $e = Entrada::de(self::body($request));
        [, $tipo] = $e->campo(Campo::entero('asset_type_id', true, minimo: 1), true);
        $marca = $e->texto('brand', 1, 100);
        $modelo = $e->texto('model', 1, 150);
        $e->validar();
        $result = $this->service->createModel((int) $tipo, (string) $marca, (string) $modelo, $this->user(), ClienteHttp::ip($request));

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
        $e = Entrada::de(self::body($request));
        $valores = [];
        // {"<id del tipo de especificación>": valor}: el valor es texto o número, nunca lista, objeto ni sí/no.
        $crudos = $e->crudo('values') ?? [];
        if (!\is_array($crudos)) {
            $e->errorDeTipo('model_attributes_type', 'values', 'Input should be a valid dictionary', $crudos);
            $crudos = [];
        }
        foreach ($crudos as $tipo => $valor) {
            if (1 !== preg_match('/^\d{1,9}$/', (string) $tipo) || !(\is_string($valor) || \is_int($valor) || \is_float($valor)) || mb_strlen((string) $valor) > 255) {
                $e->errorDeTipo('value_error', "values.{$tipo}", 'Value error, INVALID_SPECIFICATION_VALUE', $valor);
                continue;
            }
            $valores[(int) $tipo] = (string) $valor;
        }
        $e->validar();
        $this->service->setModelDefaults($model, $valores, $this->user(), ClienteHttp::ip($request));

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
        $e = Entrada::de(self::body($request));
        $motivo = $e->texto('reason', max: 500, opcional: true) ?? '';
        $e->validar();

        return new JsonResponse($this->service->cancel($id, $motivo, $this->user(), ClienteHttp::ip($request)));
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

        return \is_string($asset) && Ident::esValido($asset) ? Ident::aHex($asset) : null;
    }
}
