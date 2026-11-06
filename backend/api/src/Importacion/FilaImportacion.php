<?php

declare(strict_types=1);

namespace App\Importacion;

/**
 * Una fila del Excel durante la validación: los datos leídos, lo que se resolvió
 * contra los catálogos y los errores (impiden crearla) y avisos (se crea igual).
 */
final class FilaImportacion
{
    /** @var list<string> */
    public array $errores = [];
    /** @var list<string> */
    public array $avisos = [];

    /** @var array{TAC_Tipo_Activo: int, TAC_Nombre: string, TAC_Prefijo: ?string}|null */
    public ?array $tipo = null;
    /** @var array{ARE_Area: int, ARE_Nombre: string}|null */
    public ?array $area = null;
    public string $marca = '';
    public string $modelo = '';
    public ?string $fecha = null;
    public ?string $costo = null;
    public string $moneda = ImportacionActivos::MONEDA_POR_DEFECTO;
    /** Marca que se creará (nombre normalizado). */
    public ?string $marcaNueva = null;
    /** Modelo que se creará: «marca|modelo» normalizados. */
    public ?string $modeloNuevo = null;

    /** @param array<string, mixed> $datos campo => valor (texto, salvo fecha y costo, que van tal cual) */
    public function __construct(public readonly int $numero, public array $datos)
    {
    }

    public function texto(string $campo): string
    {
        return (string) ($this->datos[$campo] ?? '');
    }

    public function error(string $codigo): void
    {
        $this->errores[] = $codigo;
    }

    public function aviso(string $codigo): void
    {
        $this->avisos[] = $codigo;
    }

    public function valida(): bool
    {
        return [] === $this->errores;
    }

    /** @return array<string, mixed> resultado fila por fila de la vista previa */
    public function resumen(): array
    {
        $o = static fn (string $v): ?string => '' === $v ? null : $v;

        return [
            'fila' => $this->numero,
            'codigo' => $o($this->texto('codigo')),
            'categoria' => $this->tipo['TAC_Nombre'] ?? $o($this->texto('categoria')),
            'marca' => $o($this->marca),
            'modelo' => $o($this->modelo),
            'serie' => $o($this->texto('serie')),
            'ubicacion' => $this->area['ARE_Nombre'] ?? $o($this->texto('ubicacion')),
            'estado' => [] !== $this->errores ? 'error' : ([] !== $this->avisos ? 'aviso' : 'ok'),
            'errores' => $this->errores,
            'avisos' => $this->avisos,
        ];
    }
}
