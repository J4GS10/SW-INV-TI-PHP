<?php

declare(strict_types=1);

namespace App\Adjuntos;

/**
 * Dónde viven los binarios de los adjuntos. Cada adjunto recuerda el backend con
 * que se guardó (`ADJ_Storage_Backend`): los antiguos del volumen local se siguen
 * leyendo aunque los nuevos vayan a MinIO/S3.
 *
 * Todas las operaciones fallan con AlmacenNoDisponible (código del contrato).
 */
interface Almacen
{
    public function nombre(): string;

    public function guardar(string $clave, string $contenido, string $tipoMime): ObjetoGuardado;

    public function leer(string $clave, ?string $bucket = null): string;

    public function borrar(string $clave, ?string $bucket = null): void;

    /** Sonda para /health/full y /metrics: nunca lanza. */
    public function disponible(): bool;
}
