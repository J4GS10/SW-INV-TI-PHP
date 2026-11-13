<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Catalogo\ClasesActivo;
use App\Db\LogicalDelete;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Inventario\Infrastructure\MovimientosStore;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;

/** Tipos de movimiento: el catálogo y su forma canónica, que es la que entienden las reglas (un catálogo puede llamar «Asignación temporal» a una asignación). */
final readonly class TiposMovimiento
{
    public const string ASIGNACION = 'Asignación';
    public const string DEVOLUCION = 'Devolución';
    public const string TRANSFERENCIA = 'Transferencia';
    public const string PRESTAMO = 'Préstamo';
    public const string INGRESO = 'Ingreso';
    public const string BAJA = 'Baja por Retiro';
    private const array TIPOS = [self::INGRESO, self::ASIGNACION, self::DEVOLUCION, self::TRANSFERENCIA, self::PRESTAMO, self::BAJA];

    public function __construct(
        private MovimientosStore $datos,
        private Alcance $alcance,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
        private LogicalDelete $borrado,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function tipos(): array
    {
        return array_map(static fn (array $t): array => ['TMO_Nombre' => $t['TMO_Nombre'], 'TMO_Tipo_Movimiento' => (int) $t['TMO_Tipo_Movimiento']],
            $this->datos->tipos());
    }

    /** @return array<string, mixed> */
    public function tipo(int $id): array
    {
        $n = $this->datos->nombreDeTipo($id);

        return false === $n ? throw ErrorDominio::noEncontrado('MOVEMENT_TYPE_NOT_FOUND') : ['TMO_Nombre' => $n, 'TMO_Tipo_Movimiento' => $id];
    }

    /** @return array<string, mixed> */
    public function crearTipo(string $nombre, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($nombre, $usuario, $ip): array {
            if (null !== $this->datos->idDeTipo($nombre)) {
                throw ErrorDominio::invalido('MOVEMENT_TYPE_ALREADY_EXISTS');
            }
            $id = $this->datos->crearTipo($nombre);
            $this->gobierno->registrarAuditoria('CREATE', 'INV_TIPO_MOVIMIENTO', ['nombre' => $nombre], $usuario, $ip);

            return $this->tipo($id);
        });
    }

    /**
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    public function cambiarTipo(int $id, array $cambios, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $cambios, $usuario, $ip): array {
            $this->tipo($id);
            if (\array_key_exists('TMO_Nombre', $cambios)) {
                $this->datos->renombrarTipo($id, $cambios['TMO_Nombre']);
            }
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_TIPO_MOVIMIENTO', ['id' => $id, 'cambios' => (object) $cambios], $usuario, $ip);

            return $this->tipo($id);
        });
    }

    public function eliminarTipo(int $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $t = $this->tipo($id);
            if ($this->alcance->elevado(fn (): bool => $this->datos->tipoEnUso($id))) {
                throw ErrorDominio::conflicto('CANNOT_DELETE_TYPE_HAS_MOVEMENTS');
            }
            $this->borrado->archive('INV_TIPO_MOVIMIENTO', 'TMO_Tipo_Movimiento', $id);
            $this->gobierno->registrarAuditoria('DELETE', 'INV_TIPO_MOVIMIENTO', ['id' => $id, 'nombre' => $t['TMO_Nombre']], $usuario, $ip);
        });
    }

    /** Tipo canónico del catálogo (exacto o contenido en el nombre, sin tildes). */
    public function tipoCanonico(int $id): ?string
    {
        $nombre = $this->datos->nombreDeTipo($id);
        if (false === $nombre) {
            return null;
        }
        foreach (self::TIPOS as $t) {
            if ($t === $nombre) {
                return $t;
            }
        }
        $normal = ClasesActivo::normalizar((string) $nombre);
        foreach (self::TIPOS as $t) {
            if (str_contains($normal, ClasesActivo::normalizar($t))) {
                return $t;
            }
        }

        return null;
    }

    public function idTipo(string $nombre): ?int
    {
        return $this->datos->idDeTipo($nombre);
    }
}
