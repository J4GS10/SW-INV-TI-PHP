<?php

declare(strict_types=1);

namespace App\Inventario\Puertos;

/**
 * Requests that wait for someone else's approval, as stored. The rules are in App\Inventario\Solicitudes
 * and App\Inventario\Aprobaciones; the data layer implements this (App\Repositorio\SolicitudesOracle).
 *
 * A request row: APR_Aprobacion, APR_Tipo, APR_Estado, APR_Payload (JSON text), APR_Resumen, APR_Motivo, ACT_Activo,
 * CON_Consumible, SED_Sede, APR_Solicitante, APR_Fecha_Solicitud, APR_Aprobador, APR_Fecha_Resolucion, APR_Comentario.
 */
interface SolicitudesDatos
{
    /**
     * @param array<string, mixed> $datos   the columns of the new request, without its payload
     * @param array<string, mixed> $payload what will be executed when approved
     *
     * @return int the new request's id
     */
    public function crear(array $datos, array $payload): int;

    /** A pending request of this type (and consumable, when given), whoever asked for it. */
    public function pendienteDe(string $tipo, ?int $consumible = null): ?int;

    /** The pending disposal request of an asset. */
    public function bajaPendienteDe(string $activo): ?int;

    /**
     * The consumable to adjust, if the current user may see it, locked until the transaction ends.
     *
     * @return array{CON_Nombre: string, CON_Stock_Actual: int, CON_Unidad: string|null, SED_Sede: int|null}|null
     */
    public function consumibleParaAjustar(int $consumible): ?array;

    /** Locks the asset type until the transaction ends: two reclassifications of the same type do not both stay pending. */
    public function bloquearTipo(int $tipo): void;

    /** @return array<string, mixed>|null */
    public function porId(int $id, bool $bloquear = false): ?array;

    /**
     * Requests of the sites the current user may see, plus their own, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function visibles(?string $estado, ?string $activo, int $limite): array;

    /** Pending requests among the ones the current user may see. */
    public function pendientesVisibles(): int;

    /** Leaves the request APROBADA, RECHAZADA or ANULADA. */
    public function resolver(int $id, string $estado, ?string $aprobador, \DateTimeImmutable $cuando, ?string $comentario): void;
}
