<?php

declare(strict_types=1);

namespace App\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as ExcepcionDbal;
use Doctrine\DBAL\Exception\ConnectionException;

/** Tells the other layers what a database exception means, so that none of them has to know the driver. */
final readonly class DiagnosticoBd
{
    public function __construct(private Connection $conexion)
    {
    }

    /**
     * @return ?FalloBd null when the error did not come from the database. A lost connection is discarded here:
     *                  in worker mode it outlives the request, and the next one must open another
     */
    public function de(\Throwable $e): ?FalloBd
    {
        if (!$e instanceof ExcepcionDbal) {
            return null;
        }
        $codigo = ErroresOracle::codigo($e);
        $tipo = match (true) {
            $e instanceof ConnectionException, ErroresOracle::esDeConexion($codigo) => TipoFalloBd::Conexion,
            ErroresOracle::esFueraDeAlcance($codigo) => TipoFalloBd::FueraDeAlcance,
            ErroresOracle::esBorradoFisico($codigo) => TipoFalloBd::BorradoFisico,
            ErroresOracle::esDeDatos($codigo) => TipoFalloBd::Datos,
            ErroresOracle::esDeIntegridad($codigo) => TipoFalloBd::Integridad,
            default => TipoFalloBd::Otro,
        };
        if (TipoFalloBd::Conexion === $tipo) {
            $this->conexion->close();
        }
        $mensaje = $e->getPrevious()?->getMessage() ?? $e->getMessage();

        return new FalloBd($tipo, $codigo, TipoFalloBd::Integridad === $tipo ? Unicidad::codigo($e) : null, mb_substr(strtok($mensaje, "\n") ?: '', 0, 300));
    }
}
