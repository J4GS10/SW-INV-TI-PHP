<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\ErrorValidacion;
use App\Http\Limite;
use App\Inventario\Credenciales;
use App\Inventario\Software;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Software, tipos y licencias, instalaciones y credenciales (/soft). */
#[Route('/api/v1/soft', requirements: ['id' => '\d+'])]
#[Autenticado(roles: Roles::LECTURA_SOFTWARE)]
final readonly class SoftwareController
{
    public function __construct(
        private Software $software,
        private Credenciales $credenciales,
        private UsuarioActual $actual,
    ) {
    }

    private function usuario(): string
    {
        return $this->actual->usuario()->id;
    }

    // ---------------- Tipos de licencia ----------------

    #[Route('/tipos-licencia', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearTipo(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::campos($e, [Campo::texto('TLI_Nombre', true, 2, 50), Campo::texto('TLI_Descripcion', max: 200)], true);
        $e->validar();

        return new JsonResponse($this->software->crearTipo($d, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/tipos-licencia', methods: ['GET'])]
    public function tipos(): JsonResponse
    {
        return new JsonResponse($this->software->tipos());
    }

    #[Route('/tipos-licencia/{id}', methods: ['GET'])]
    public function tipo(int $id): JsonResponse
    {
        return new JsonResponse($this->software->tipo($id));
    }

    #[Route('/tipos-licencia/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function cambiarTipo(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::campos($e, [Campo::texto('TLI_Nombre', min: 2, max: 50), Campo::texto('TLI_Descripcion', max: 200)], false);
        $e->validar();

        return new JsonResponse($this->software->cambiarTipo($id, $d, $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/tipos-licencia/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarTipo(int $id, Request $request): Response
    {
        $this->software->eliminarTipo($id, $this->usuario(), ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    // ---------------- Software ----------------

    #[Route('/software', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearSoftware(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::campos($e, [Campo::texto('SOF_Nombre', true, 2, 100), Campo::texto('SOF_Version', max: 50),
            Campo::texto('SOF_Fabricante', max: 100, defecto: ''), Campo::entero('MAR_Marca')], true);
        $e->validar();

        return new JsonResponse($this->software->crearSoftware($d, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/software', methods: ['GET'])]
    public function listaSoftware(): JsonResponse
    {
        return new JsonResponse($this->software->listaSoftware());
    }

    #[Route('/software/{id}', methods: ['GET'])]
    public function software(int $id): JsonResponse
    {
        return new JsonResponse($this->software->software($id));
    }

    #[Route('/software/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function cambiarSoftware(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::campos($e, [Campo::texto('SOF_Nombre', min: 2, max: 100), Campo::texto('SOF_Version', max: 50),
            Campo::texto('SOF_Fabricante', min: 2, max: 100), Campo::entero('MAR_Marca')], false);
        $e->validar();

        return new JsonResponse($this->software->cambiarSoftware($id, $d, $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/software/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarSoftware(int $id, Request $request): Response
    {
        $this->software->eliminarSoftware($id, $this->usuario(), ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    // ---------------- Licencias ----------------

    #[Route('/licencias', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearLicencia(Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::campos($e, self::camposLicencia(true), true);
        $d['LIC_Moneda'] = $e->moneda('LIC_Moneda');
        $claves = self::leerClaves($e, 'LIC_Claves');
        $credenciales = self::listaCredenciales($e, 'LIC_Credenciales', 0);
        $e->validar();
        if ([] !== $claves && (int) $d['LIC_Cantidad_Total'] !== \count($claves)) {
            throw ErrorValidacion::deModelo('LICENSE_KEY_COUNT_MISMATCH', $request);
        }
        if (null === $d['TLI_Tipo_Licencia'] && null === $d['MCO_Modelo_Comercial']) {
            throw ErrorValidacion::deModelo('LICENSE_COMMERCIAL_MODEL_REQUIRED', $request);
        }

        return new JsonResponse($this->software->crearLicencia($d, $claves, $credenciales, $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/licencias', methods: ['GET'])]
    public function licencias(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        [, $software] = $e->campo(Campo::entero('software_id', true), true);
        $e->validar();

        return new JsonResponse($this->software->licencias((int) $software));
    }

    #[Route('/licencias-disponibles', methods: ['GET'])]
    public function disponibles(Request $request): JsonResponse
    {
        $e = Entrada::consulta($request);
        $forma = $e->patron('forma', '^(EQUIPO|PERSONA|CONCURRENTE|SITIO)$');
        [, $todas] = $e->campo(Campo::logico('todas'), false);
        $e->validar();

        return new JsonResponse($this->software->disponibles($forma, true === $todas));
    }

    #[Route('/licencias/{id}', methods: ['GET'])]
    public function licencia(int $id): JsonResponse
    {
        return new JsonResponse($this->software->licencia($id));
    }

    #[Route('/licencias/{id}/historial', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_PERSONAS)]
    public function historial(int $id): JsonResponse
    {
        return new JsonResponse($this->software->historial($id));
    }

    #[Route('/licencias/{id}/claves', methods: ['GET'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function claves(int $id, Request $request): JsonResponse
    {
        return new JsonResponse($this->software->claves($id, $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/licencias/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function cambiarLicencia(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $d = self::campos($e, self::camposLicencia(false), false);
        if ($e->presente('LIC_Moneda')) {
            $d['LIC_Moneda'] = $e->moneda('LIC_Moneda');
        }
        $e->validar();

        return new JsonResponse($this->software->cambiarLicencia($id, $d, $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/licencias/{id}', methods: ['DELETE'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function eliminarLicencia(int $id, Request $request): Response
    {
        $this->software->eliminarLicencia($id, $this->usuario(), ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    // ---------------- Instalaciones ----------------

    #[Route('/activos/{activo}/instalaciones', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_PERSONAS)]
    public function instalacionesActivo(Request $request): JsonResponse
    {
        return new JsonResponse($this->software->instalacionesDe('ACT_Activo', Entrada::uuidDeRuta($request, 'activo'), self::soloActivas($request)));
    }

    #[Route('/personas/{persona}/instalaciones', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTURA_PERSONAS)]
    public function instalacionesPersona(Request $request, \Doctrine\DBAL\Connection $conexion): JsonResponse
    {
        $persona = Entrada::uuidDeRuta($request, 'persona');
        if (false === $conexion->fetchOne('SELECT 1 FROM "INV_PERSONA" WHERE "PER_Persona" = :p', ['p' => $persona])) {
            throw new \App\Http\ErrorApi(404, 'PERSON_NOT_FOUND');
        }

        return new JsonResponse($this->software->instalacionesDe('PER_Persona', $persona, self::soloActivas($request)));
    }

    #[Route('/instalaciones', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    #[Limite('30/minute')]
    public function instalar(Request $request): JsonResponse
    {
        $i = self::instalacion($request);

        return new JsonResponse($this->software->instalar($i['licencia'], $i['activo'], $i['persona'],
            $i['clave'], $i['fecha'], $this->usuario(), ClienteHttp::ip($request)), 201);
    }

    #[Route('/instalaciones/desinstalar', methods: ['POST'])]
    #[Autenticado(roles: Roles::OPERATIVO)]
    public function desinstalar(Request $request): JsonResponse
    {
        $i = self::instalacion($request);

        return new JsonResponse($this->software->desinstalar($i['licencia'], $i['activo'], $i['persona'], $i['motivo'], $this->usuario(), ClienteHttp::ip($request)));
    }

    // ---------------- Credenciales ----------------

    #[Route('/licencias/{id}/credenciales', methods: ['GET'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function credenciales(int $id): JsonResponse
    {
        return new JsonResponse($this->credenciales->listar($id));
    }

    #[Route('/licencias/{id}/credenciales', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function crearCredenciales(int $id, Request $request): JsonResponse
    {
        $e = Entrada::json($request);
        $lote = self::listaCredenciales($e, 'credenciales', 1);
        $e->validar();
        $this->credenciales->crear($id, $lote, $this->usuario(), ClienteHttp::ip($request));

        return new JsonResponse($this->credenciales->listar($id), 201);
    }

    #[Route('/credenciales/{id}', methods: ['PATCH'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function editarCredencial(int $id, Request $request): Response
    {
        $e = Entrada::json($request);
        $d = self::campos($e, [Campo::texto('usuario', max: 255), Campo::texto('serial', max: 255), Campo::texto('servidor', max: 255),
            Campo::texto('dispositivo', max: 120), Campo::texto('referencia', max: 120), new Campo('vence', Campo::FECHA)], false);
        $estado = $e->patron('estado', '^(DISPONIBLE|SUSPENDIDA|BAJA)$');
        $e->validar();
        if ($e->presente('estado')) {
            $d['estado'] = $estado;
        }
        $this->credenciales->editar($id, $d, $this->usuario(), ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    #[Route('/credenciales/{id}/revelar', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    #[Limite('10/minute')]
    public function revelar(int $id, Request $request): JsonResponse
    {
        return new JsonResponse($this->credenciales->revelar($id, $this->usuario(), ClienteHttp::ip($request)));
    }

    #[Route('/credenciales/{id}/rotar', methods: ['POST'])]
    #[Autenticado(roles: Roles::ADMIN)]
    public function rotar(int $id, Request $request): Response
    {
        $e = Entrada::json($request);
        $d = self::campos($e, [Campo::texto('secreto', min: 1, max: 500), Campo::texto('clave', min: 1, max: 255)], false);
        $e->validar();
        $this->credenciales->rotar($id, array_filter($d, static fn (mixed $v): bool => null !== $v), $this->usuario(), ClienteHttp::ip($request));

        return new Response(status: 204);
    }

    // ---------------- Validación de la entrada ----------------

    /**
     * @param list<Campo> $campos
     *
     * @return array<string, mixed> en la edición, solo los campos enviados
     */
    private static function campos(Entrada $e, array $campos, bool $creacion): array
    {
        $d = [];
        foreach ($campos as $c) {
            [$hay, $v] = $e->campo($c, $creacion);
            if ($hay) {
                $d[$c->nombre] = $v;
            } elseif ($creacion) {
                $d[$c->nombre] = $c->defecto;
            }
        }

        return $d;
    }

    /** @return list<Campo> */
    private static function camposLicencia(bool $creacion): array
    {
        $comunes = [Campo::texto('LIC_Clave_Activacion', max: 255), new Campo('LIC_Fecha_Vencimiento', Campo::FECHA),
            Campo::entero('LIC_Cantidad_Total', $creacion, minimo: 1), new Campo('LIC_Costo', Campo::DECIMAL, max: 14, escala: 2, minimo: 0),
            Campo::entero('MCO_Modelo_Comercial'), Campo::entero('MAC_Metodo_Activacion'), Campo::entero('FCO_Forma_Conteo'), Campo::entero('LIC_Proveedor'),
            new Campo('LIC_Fecha_Inicio', Campo::FECHA), Campo::logico('LIC_Renovacion_Automatica', defecto: $creacion ? false : null),
            Campo::texto('LIC_Notas', max: 1000)];

        return $creacion ? [...$comunes, Campo::entero('SOF_Software', true), Campo::entero('TLI_Tipo_Licencia')] : $comunes;
    }

    /** @return list<array{LCL_Clave_Activacion: string, LCL_Referencia: ?string}> */
    public static function leerClaves(Entrada $e, string $campo): array
    {
        $salida = [];
        foreach ($e->lista($campo, 10000) ?? [] as $item) {
            $sub = Entrada::de(\is_array($item) ? $item : []);
            [, $clave] = $sub->campo(Campo::texto('LCL_Clave_Activacion', true, 1, 255), true);
            [, $ref] = $sub->campo(Campo::texto('LCL_Referencia', max: 120), true);
            $e->absorber($sub);
            $salida[] = ['LCL_Clave_Activacion' => (string) $clave, 'LCL_Referencia' => $ref];
        }

        return $salida;
    }

    /** @return list<array<string, mixed>> */
    public static function listaCredenciales(Entrada $e, string $campo, int $min): array
    {
        $lista = $e->lista($campo, 500);
        if (null === $lista) {
            if ($min > 0) {
                $e->errorDeValor($campo, 'Field required', null);
            }

            return [];
        }
        $salida = [];
        foreach ($lista as $item) {
            $sub = Entrada::de(\is_array($item) ? $item : []);
            $d = self::campos($sub, [Campo::texto('usuario', max: 255), Campo::texto('secreto', max: 500), Campo::texto('serial', max: 255),
                Campo::texto('clave', max: 255), Campo::texto('servidor', max: 255), new Campo('archivo', Campo::UUID), Campo::texto('dispositivo', max: 120),
                Campo::texto('referencia', max: 120), new Campo('vence', Campo::FECHA)], false);
            $e->absorber($sub);
            $salida[] = $d;
        }
        if (\count($salida) < $min) {
            $e->errorDeValor($campo, 'LIST_TOO_SHORT', $lista);
        }

        return $salida;
    }

    private static function soloActivas(Request $request): bool
    {
        $e = Entrada::consulta($request);
        [$hay, $v] = $e->campo(Campo::logico('solo_activas'), false);
        $e->validar();

        return !$hay || null === $v || true === $v;
    }

    /** @return array{licencia: int, activo: ?string, persona: ?string, clave: ?int, fecha: string, motivo: ?string} */
    private static function instalacion(Request $request): array
    {
        $e = Entrada::json($request);
        [, $activo] = $e->campo(new Campo('ACT_Activo', Campo::UUID), true);
        [, $persona] = $e->campo(new Campo('PER_Persona', Campo::UUID), true);
        [, $licencia] = $e->campo(Campo::entero('LIC_Licencia', true), true);
        [, $clave] = $e->campo(Campo::entero('LCL_Licencia_Clave'), true);
        [, $fecha] = $e->campo(new Campo('INS_Fecha_Instalacion', Campo::FECHA), true);
        [, $motivo] = $e->campo(Campo::texto('motivo', max: 255), true);
        $e->validar();
        if ((null === $activo) === (null === $persona)) {
            throw ErrorValidacion::deModelo('INSTALLATION_TARGET_XOR_REQUIRED', $request);
        }

        return ['licencia' => (int) $licencia, 'activo' => null === $activo ? null : Ident::aHex((string) $activo),
            'persona' => null === $persona ? null : Ident::aHex((string) $persona), 'clave' => null === $clave ? null : (int) $clave,
            'fecha' => $fecha ?? Reloj::ahora()->format('Y-m-d'), 'motivo' => $motivo];
    }
}
