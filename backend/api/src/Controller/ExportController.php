<?php

declare(strict_types=1);

namespace App\Controller;

use App\Crud\Campo;
use App\Documentos\Descarga;
use App\Documentos\Exportaciones;
use App\Documentos\Idioma;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Http\ClienteHttp;
use App\Http\Entrada;
use App\Http\Limite;
use App\Repositorio\BitacoraRepositorio;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Autenticado;
use App\Seguridad\UsuarioActual;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exportaciones CSV (/export). Cada exporte exige su rol: valores (finanzas),
 * compras, control o lectura de la bitácora (también para seguridad, que no
 * tiene rol de inventario: por eso la bitácora se publica además en /gov).
 */
#[Route('/api/v1/export')]
#[Autenticado]
final readonly class ExportController
{
    public function __construct(
        private Exportaciones $exportaciones,
        private BitacoraRepositorio $bitacora,
        private GobiernoRepositorio $gobierno,
        private UsuarioActual $actual,
    ) {
    }

    private static function idiomaDe(Request $request): Idioma
    {
        $e = Entrada::consulta($request);
        $idioma = $e->idioma();
        $e->validar();

        return $idioma;
    }

    /** Archivo «<nombre>_<AAAAMMDD_HHMMSS UTC>.csv». */
    private static function descarga(string $contenido, string $exporte, Idioma $idioma): Response
    {
        return Descarga::csv($contenido, $idioma->de(Exportaciones::ARCHIVOS[$exporte]).'_'.Reloj::ahora()->format('Ymd_His').'.csv');
    }

    #[Route('/activos.csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION_FINANZAS)]
    #[Limite('10/minute')]
    public function activos(Request $request): Response
    {
        $idioma = self::idiomaDe($request);

        $e = \App\Http\Entrada::consulta($request);
        $filtros = [];
        [, $filtros['q']] = $e->campo(\App\Crud\Campo::texto('q'), false);
        foreach (['modelo_id', 'tipo_activo_id', 'estado_operativo_id', 'sede_id'] as $campo) {
            [, $valor] = $e->campo(\App\Crud\Campo::entero($campo), false);
            $filtros[$campo] = null === $valor ? null : (int) $valor;
        }
        [, $filtros['fecha_compra_start']] = $e->campo(new \App\Crud\Campo('fecha_compra_start', \App\Crud\Campo::FECHA), false);
        [, $filtros['fecha_compra_end']] = $e->campo(new \App\Crud\Campo('fecha_compra_end', \App\Crud\Campo::FECHA), false);

        return self::descarga($this->exportaciones->activos($idioma, $filtros), 'activos', $idioma);
    }

    #[Route('/movimientos.csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION)]
    #[Limite('10/minute')]
    public function movimientos(Request $request): Response
    {
        $e = Entrada::consulta($request);
        [, $limite] = $e->campo(Campo::entero('limit', minimo: 1, maximo: 10000), false);
        [, $tipo] = $e->campo(Campo::texto('tipo', max: 100), false);
        [, $estado] = $e->campo(Campo::texto('estado', max: 10), false);
        [, $busqueda] = $e->campo(Campo::texto('q', max: 100), false);
        $idioma = $e->idioma();
        $e->validar();

        return self::descarga($this->exportaciones->movimientos(null === $limite ? 5000 : (int) $limite, $idioma, ['tipo' => $tipo, 'estado' => $estado, 'q' => $busqueda]), 'movimientos', $idioma);
    }

    #[Route('/consumibles.csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION_FINANZAS)]
    #[Limite('10/minute')]
    public function consumibles(Request $request): Response
    {
        $e = Entrada::consulta($request);
        [, $busqueda] = $e->campo(Campo::texto('q', max: 100), false);
        [, $soloBajo] = $e->campo(Campo::logico('solo_bajo'), false);
        $idioma = $e->idioma();
        $e->validar();

        return self::descarga($this->exportaciones->consumibles($idioma, $busqueda, true === $soloBajo), 'consumibles', $idioma);
    }

    #[Route('/mantenimientos.csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION)]
    #[Limite('10/minute')]
    public function mantenimientos(Request $request): Response
    {
        $idioma = self::idiomaDe($request);

        return self::descarga($this->exportaciones->mantenimientos($idioma), 'mantenimientos', $idioma);
    }

    #[Route('/personas.csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION)]
    #[Limite('10/minute')]
    public function personas(Request $request): Response
    {
        $idioma = self::idiomaDe($request);

        return self::descarga($this->exportaciones->personas($idioma), 'personas', $idioma);
    }

    #[Route('/garantias.csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION)]
    #[Limite('10/minute')]
    public function garantias(Request $request): Response
    {
        $idioma = self::idiomaDe($request);

        return self::descarga($this->exportaciones->garantias($idioma), 'garantias', $idioma);
    }

    #[Route('/proveedores.csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION_COMPRAS)]
    #[Limite('10/minute')]
    public function proveedores(Request $request): Response
    {
        $idioma = self::idiomaDe($request);

        return self::descarga($this->exportaciones->proveedores($idioma), 'proveedores', $idioma);
    }

    #[Route('/ordenes.csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::EXPORTACION_COMPRAS)]
    #[Limite('10/minute')]
    public function ordenes(Request $request): Response
    {
        $idioma = self::idiomaDe($request);

        return self::descarga($this->exportaciones->ordenes($idioma), 'ordenes', $idioma);
    }

    /**
     * Bitácora filtrada por el alcance del lector. La propia exportación queda
     * registrada (quién, cuándo, qué rango y cuántos eventos) antes de entregarla.
     */
    #[Route('/auditoria.csv', name: 'export_auditoria_csv', methods: ['GET'])]
    #[Autenticado(roles: Roles::LECTORES_AUDITORIA)]
    #[Limite('5/minute')]
    public function auditoria(Request $request): Response
    {
        $e = Entrada::consulta($request);
        [, $limite] = $e->campo(Campo::entero('limit', minimo: 1, maximo: 50000), false);
        $accion = $e->texto('accion', max: 50, opcional: true);
        $entidad = $e->texto('entidad', max: 50, opcional: true);
        $idioma = $e->idioma();
        [$desde, $hasta] = GobiernoController::rangoAuditoria($e);

        [$contenido, $registros] = $this->exportaciones->auditoria($this->bitacora->filtro($desde, $hasta, $accion, $entidad),
            null === $limite ? 10000 : (int) $limite, $idioma);
        $this->gobierno->registrarAuditoria('AUDIT_EXPORT', 'INV_AUDITORIA_SISTEMA', ['registros' => $registros, 'desde' => Reloj::iso($desde),
            'hasta' => Reloj::iso($hasta), 'idioma' => $idioma->value, 'accion' => $accion, 'entidad' => $entidad],
            $this->actual->usuario()->id, ClienteHttp::ip($request), ClienteHttp::userAgent($request));

        return self::descarga($contenido, 'auditoria', $idioma);
    }
}
