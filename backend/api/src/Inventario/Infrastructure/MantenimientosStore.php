<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure;

use App\Db\InsercionOracle;
use App\Dominio\Reloj;
use App\Inventario\Activos;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Maintenance tickets, their work items and their types, as stored: all that SQL lives here. */
final readonly class MantenimientosStore
{
    private const string DETALLE = '"DMA_Detalle_Mant", "DMA_Accion_Realizada", "DMA_Costo_Item", "AMA_Accion"';

    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /** @return list<array<string, mixed>> TMA_Tipo_Mantenimiento and TMA_Nombre, by id */
    public function tipos(): array
    {
        return $this->conexion->fetchAllAssociative('SELECT "TMA_Tipo_Mantenimiento", "TMA_Nombre" FROM "INV_TIPO_MANTENIMIENTO" ORDER BY "TMA_Tipo_Mantenimiento"');
    }

    /** @return mixed the name of a maintenance type; false when it does not exist */
    public function nombreDeTipo(mixed $id): mixed
    {
        return $this->conexion->fetchOne('SELECT "TMA_Nombre" FROM "INV_TIPO_MANTENIMIENTO" WHERE "TMA_Tipo_Mantenimiento" = :id', ['id' => $id]);
    }

    public function crearTipo(string $nombre): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_TIPO_MANTENIMIENTO', ['TMA_Nombre' => $nombre], 'TMA_Tipo_Mantenimiento');
    }

    public function renombrarTipo(int $id, mixed $nombre): void
    {
        $this->conexion->executeStatement('UPDATE "INV_TIPO_MANTENIMIENTO" SET "TMA_Nombre" = :n WHERE "TMA_Tipo_Mantenimiento" = :id', ['n' => $nombre, 'id' => $id]);
    }

    /** Whether any ticket uses that type (run it elevated to check every site). */
    public function tipoEnUso(int $id): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_MANTENIMIENTO" WHERE "TMA_Tipo_Mantenimiento" = :id FETCH FIRST 1 ROWS ONLY', ['id' => $id]);
    }

    /**
     * The tickets the current user may see, newest first, with their type, asset and requester: the one asked for, or the last 50.
     *
     * @return list<array<string, mixed>>
     */
    public function tickets(?string $id = null): array
    {
        [$alcance, $parametros] = $this->alcance->lectura('INV_MANTENIMIENTO', 'm');
        $donde = null === $id ? '1 = 1' : 'm."MAN_Mantenimiento" = :id';

        return $this->conexion->fetchAllAssociative('SELECT m.*, t."TMA_Nombre", a."ACT_Codigo_Interno", a."ACT_Hostname", p."PER_Primer_Nombre", '
            .'p."PER_Primer_Apellido", p."PER_Email_Corporativo" FROM "INV_MANTENIMIENTO" m LEFT JOIN "INV_TIPO_MANTENIMIENTO" t ON t."TMA_Tipo_Mantenimiento" = m."TMA_Tipo_Mantenimiento" '
            .'LEFT JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = m."PER_Persona_Solicita" WHERE '
            .$donde.' AND '.$alcance.' ORDER BY m."MAN_Fecha_Ingreso" DESC, m."MAN_Mantenimiento" FETCH FIRST '.(null === $id ? 50 : 1).' ROWS ONLY',
            [...(null === $id ? [] : ['id' => $id]), ...$parametros]);
    }

    /** @return list<array<string, mixed>> the work items of a ticket, oldest first */
    public function detallesDe(mixed $ticket): array
    {
        return $this->conexion->fetchAllAssociative('SELECT '.self::DETALLE.' FROM "INV_DETALLE_MANT" WHERE "MAN_Mantenimiento" = :m ORDER BY "DMA_Detalle_Mant"', ['m' => $ticket]);
    }

    /** @return array<string, mixed>|false a work item with its ticket */
    public function detalle(int $id): array|false
    {
        return $this->conexion->fetchAssociative('SELECT '.self::DETALLE.', "MAN_Mantenimiento" FROM "INV_DETALLE_MANT" WHERE "DMA_Detalle_Mant" = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $columnas */
    public function insertarDetalle(array $columnas): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_DETALLE_MANT', $columnas, 'DMA_Detalle_Mant');
    }

    public function actualizarDetalle(int $id, mixed $texto, mixed $accion, mixed $costo): void
    {
        $this->conexion->executeStatement('UPDATE "INV_DETALLE_MANT" SET "DMA_Accion_Realizada" = :t, "AMA_Accion" = :a, "DMA_Costo_Item" = :c WHERE "DMA_Detalle_Mant" = :id',
            ['t' => $texto, 'a' => $accion, 'c' => $costo, 'id' => $id]);
    }

    public function accionPideDetalle(mixed $accion): bool
    {
        return 1 === (int) $this->conexion->fetchOne('SELECT "AMA_Pide_Detalle" FROM "INV_ACCION_MANTENIMIENTO" WHERE "AMA_Accion" = :a', ['a' => $accion]);
    }

    public function fallaPideDetalle(mixed $tipoDeFalla): bool
    {
        return 1 === (int) $this->conexion->fetchOne('SELECT "TFA_Pide_Detalle" FROM "INV_TIPO_FALLA" WHERE "TFA_Tipo_Falla" = :t', ['t' => $tipoDeFalla]);
    }

    /** @return array<string, mixed>|false the asset row, locked, if the current user may see it */
    public function activoVisibleBloqueado(mixed $activo): array|false
    {
        [$alcance, $parametros] = $this->alcance->lectura('INV_ACTIVO', 'a');

        return $this->conexion->fetchAssociative('SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE a."ACT_Activo" = :id AND '.$alcance.' FOR UPDATE', [...$parametros, 'id' => $activo]);
    }

    /** @return array<string, mixed>|false the asset row, whatever its site */
    public function activo(mixed $activo): array|false
    {
        return $this->conexion->fetchAssociative('SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE a."ACT_Activo" = :a', ['a' => $activo]);
    }

    public function cambiarEstadoDeActivo(mixed $activo, int $estado): void
    {
        $this->conexion->executeStatement('UPDATE "INV_ACTIVO" SET "EOP_Estado_Operativo" = :e WHERE "ACT_Activo" = :a', ['e' => $estado, 'a' => $activo]);
    }

    /** @return array<string, mixed>|false PER_Primer_Nombre, PER_Primer_Apellido, PER_Email_Corporativo and PER_Estado */
    public function persona(mixed $persona): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "PER_Primer_Nombre", "PER_Primer_Apellido", "PER_Email_Corporativo", "PER_Estado" FROM "INV_PERSONA" '
            .'WHERE "PER_Persona" = :p', ['p' => $persona]);
    }

    public function tieneTicketAbierto(mixed $activo): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_MANTENIMIENTO" WHERE "ACT_Activo" = :a AND "MAN_Fecha_Cierre" IS NULL FETCH FIRST 1 ROWS ONLY', ['a' => $activo]);
    }

    /** The oldest operational state whose name contains that text (lowercase); null when none does. */
    public function estadoCuyoNombreContiene(string $texto): ?int
    {
        $id = $this->conexion->fetchOne('SELECT "EOP_Estado_Operativo" FROM "INV_ESTADO_OPERATIVO" WHERE LOWER("EOP_Nombre") LIKE :t '
            .'ORDER BY "EOP_Estado_Operativo" FETCH FIRST 1 ROWS ONLY', ['t' => "%{$texto}%"]);

        return false === $id || null === $id ? null : (int) $id;
    }

    /** @param array{id: string, descripcion: string, falla: mixed, costo: mixed, moneda: mixed, activo: mixed, persona: mixed, tipo: mixed} $ticket */
    public function abrir(array $ticket, \DateTimeImmutable $ingreso): void
    {
        $this->conexion->executeStatement('INSERT INTO "INV_MANTENIMIENTO" ("MAN_Mantenimiento", "MAN_Fecha_Ingreso", "MAN_Descripcion_Falla", "TFA_Tipo_Falla", '
            .'"MAN_Costo_Total", "MAN_Moneda", "ACT_Activo", "PER_Persona_Solicita", "TMA_Tipo_Mantenimiento") VALUES (:id, :f, :desc, :tfa, :costo, :moneda, :a, :p, :t)',
            ['id' => $ticket['id'], 'f' => Reloj::aBd($ingreso), 'desc' => $ticket['descripcion'], 'tfa' => $ticket['falla'], 'costo' => $ticket['costo'], 'moneda' => $ticket['moneda'],
                'a' => $ticket['activo'], 'p' => $ticket['persona'], 't' => $ticket['tipo']], ['desc' => ParameterType::LARGE_OBJECT]);
    }

    public function fechaDeIngreso(string $ticket): mixed
    {
        return $this->conexion->fetchOne('SELECT "MAN_Fecha_Ingreso" FROM "INV_MANTENIMIENTO" WHERE "MAN_Mantenimiento" = :id', ['id' => $ticket]);
    }

    /** Closes the ticket if it is still open; false when someone else closed it first. */
    public function cerrar(string $ticket, \DateTimeImmutable $fecha, string $costo, mixed $moneda): bool
    {
        return 1 === $this->conexion->executeStatement('UPDATE "INV_MANTENIMIENTO" SET "MAN_Fecha_Cierre" = :f, "MAN_Costo_Total" = :c, "MAN_Moneda" = :m '
            .'WHERE "MAN_Mantenimiento" = :id AND "MAN_Fecha_Cierre" IS NULL', ['f' => Reloj::aBd($fecha), 'c' => $costo, 'm' => $moneda, 'id' => $ticket]);
    }
}
