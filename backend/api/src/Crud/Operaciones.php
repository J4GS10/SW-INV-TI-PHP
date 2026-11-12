<?php

declare(strict_types=1);

namespace App\Crud;

use App\Http\ClienteHttp;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\UsuarioActual;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Pasos comunes de los CRUD: obtener o 404, comprobar hijos, y escribir con
 * su evento de auditoría en la MISMA transacción (sin auditoría no hay cambio).
 */
final readonly class Operaciones
{
    public function __construct(
        public RepositorioEntidad $repositorio,
        private GobiernoRepositorio $gobierno,
        private UsuarioActual $actual,
        private Connection $conexion,
    ) {
    }

    /** @return array<string, mixed> */
    public function obtenerO404(Entidad $e, int|string $id, string $codigo): array
    {
        return $this->repositorio->obtener($e, $id) ?? throw ErrorDominio::noEncontrado($codigo);
    }

    /** 409 si la tabla hija tiene filas que apuntan al registro. */
    public function sinHijos(string $tablaHija, string $columna, int|string $id, string $codigo): void
    {
        $hay = $this->conexion->fetchOne(
            'SELECT 1 FROM "'.$tablaHija.'" WHERE "'.$columna.'" = :id FETCH FIRST 1 ROWS ONLY',
            ['id' => $id],
        );
        if (false !== $hay) {
            throw ErrorDominio::conflicto($codigo);
        }
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed> registro creado (formato API)
     */
    public function crear(Entidad $e, array $datos, Request $request, array $snapshot, ?int $sede = null): array
    {
        return $this->conexion->transactional(function () use ($e, $datos, $request, $snapshot, $sede): array {
            $id = $this->repositorio->insertar($e, $datos);
            $this->auditar('CREATE', $e->tabla, $snapshot, $request, $sede);

            return (array) $this->repositorio->obtener($e, $id);
        });
    }

    /**
     * Actualización parcial; el snapshot registra los cambios enviados.
     *
     * @param array<string, mixed>      $cambios
     * @param array<string, mixed>|null $snapshot
     *
     * @return array<string, mixed>
     */
    public function actualizar(Entidad $e, int|string $id, array $cambios, Request $request, ?array $snapshot = null, ?int $sede = null): array
    {
        return $this->conexion->transactional(function () use ($e, $id, $cambios, $request, $snapshot, $sede): array {
            $this->repositorio->actualizar($e, $id, $cambios);
            $this->auditar('UPDATE', $e->tabla, $snapshot ?? ['id' => $id, 'cambios' => (object) $cambios], $request, $sede);

            return (array) $this->repositorio->obtener($e, $id);
        });
    }

    /** @param array<string, mixed> $snapshot */
    public function eliminar(Entidad $e, int|string $id, Request $request, array $snapshot, ?int $sede = null): void
    {
        $this->conexion->transactional(function () use ($e, $id, $request, $snapshot, $sede): void {
            $this->repositorio->eliminar($e, $id);
            $this->auditar('DELETE', $e->tabla, $snapshot, $request, $sede);
        });
    }

    /** @param array<string, mixed> $snapshot */
    public function auditar(string $accion, string $entidad, array $snapshot, Request $request, ?int $sede = null): void
    {
        $this->gobierno->registrarAuditoria($accion, $entidad, $snapshot, $this->actual->usuario()->id,
            ClienteHttp::ip($request), ClienteHttp::userAgent($request), $sede);
    }
}
