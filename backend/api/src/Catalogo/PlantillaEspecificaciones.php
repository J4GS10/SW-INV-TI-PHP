<?php

declare(strict_types=1);

namespace App\Catalogo;

use App\Catalogo\Infrastructure\SpecificationStore;

/**
 * Especificaciones por tipo de activo: un tipo sin ninguna configurada recibe
 * la plantilla de su nombre o, si no hay, la de su clase (p. ej. impresora →
 * tecnología, color, IP). Nunca pisa lo que el usuario armó.
 */
final readonly class PlantillaEspecificaciones
{
    /** (especificación, código de unidad, obligatoria) por nombre de tipo. */
    private const array POR_TIPO = [
        'laptop' => [['RAM', 'GB', true], ['Almacenamiento', 'GB', true], ['Procesador', null, true], ['Sistema Operativo', null, false]],
        'desktop' => [['RAM', 'GB', true], ['Almacenamiento', 'GB', true], ['Procesador', null, true], ['Sistema Operativo', null, false]],
        'monitor' => [['Tamaño Pantalla', 'PULGADAS', false]],
        'ups' => [['Potencia', 'VA', false]],
        'memoria ram' => [['RAM', 'GB', true]],
        'disco' => [['Almacenamiento', 'GB', true]],
        'batería' => [['Salud de batería', 'PORCENTAJE', false]],
    ];

    private const array SI_NO = ['Sí', 'No'];
    private const array TIPOS_RAM = ['DDR3', 'DDR4', 'DDR5', 'LPDDR4', 'LPDDR4X', 'LPDDR5', 'LPDDR5X'];
    private const array TIPOS_DISCO = ['SSD', 'NVMe', 'HDD'];

    /** (especificación, código de unidad, obligatoria, valores permitidos) por clase. */
    private const array POR_CLASE = [
        'COMPUTO' => [['Procesador', null, true, null], ['RAM', 'GB', true, null], ['Tipo de RAM', null, false, self::TIPOS_RAM],
            ['Almacenamiento', 'GB', true, null], ['Tipo de disco', null, false, self::TIPOS_DISCO],
            ['Sistema Operativo', null, false, null], ['Dirección MAC', null, false, null]],
        'MOVIL' => [['Número de IMEI', null, true, null], ['Número de línea', null, false, null],
            ['Sistema Operativo', null, false, null], ['Almacenamiento', 'GB', false, null]],
        'IMPRESION' => [['Tecnología de impresión', null, true, ['Láser', 'Inyección de tinta', 'Térmica', 'Matriz de puntos']],
            ['Impresión a color', null, true, self::SI_NO], ['Dirección IP', null, false, null],
            ['Dirección MAC', null, false, null], ['Contador de páginas', null, false, null]],
        'RED' => [['Dirección IP', null, false, null], ['Dirección MAC', null, false, null],
            ['Puertos', null, false, null], ['Firmware', null, false, null]],
        'ENERGIA' => [['Potencia', 'VA', true, null]],
        'AUDIOVISUAL' => [['Tamaño Pantalla', 'PULGADAS', false, null], ['Dirección IP', null, false, null]],
    ];

    public function __construct(private SpecificationStore $store)
    {
    }

    /** Aplica la plantilla al tipo si aún no tiene especificaciones. Devuelve cuántas creó. */
    public function aplicar(int $tipoId): int
    {
        $tipo = $this->store->assetType($tipoId);
        if (null === $tipo || $this->store->hasSpecifications($tipoId)) {
            return 0;
        }
        /** @var list<array{0: string, 1: ?string, 2: bool, 3: list<string>|null}> $especificaciones */
        $especificaciones = [];
        foreach (self::POR_TIPO[mb_strtolower(trim($tipo['TAC_Nombre']))] ?? [] as [$n, $u, $o]) {
            $especificaciones[] = [$n, $u, $o, null];
        }
        if ([] === $especificaciones) {
            $especificaciones = self::POR_CLASE[$tipo['TAC_Clase']] ?? [];
        }

        $creadas = 0;
        foreach ($especificaciones as $orden => [$nombre, $unidad, $obligatoria, $valores]) {
            $tes = $this->tipoEspecificacion($nombre, $unidad);
            if (!$this->store->uses($tipoId, $tes)) {
                $this->store->addToAssetType($tipoId, $tes, $obligatoria, ($orden + 1) * 10);
                foreach ($valores ?? [] as $posicion => $valor) {
                    $this->store->addAllowedValue($tipoId, $tes, $valor, ($posicion + 1) * 10);
                }
                ++$creadas;
            }
        }

        return $creadas;
    }

    /** Tipo de especificación por nombre (sin distinguir mayúsculas); se crea si no existe. */
    private function tipoEspecificacion(string $nombre, ?string $unidad): int
    {
        $tes = $this->store->specificationTypeByName($nombre);
        if (null !== $tes) {
            return $tes;
        }
        $this->store->createSpecificationType($nombre, $unidad);

        return (int) $this->store->specificationTypeByName($nombre);
    }
}
