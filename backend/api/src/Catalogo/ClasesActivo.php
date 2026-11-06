<?php

declare(strict_types=1);

namespace App\Catalogo;

/**
 * Clase de un tipo de activo: decide cómo se registra y opera cada equipo.
 *
 * - uso PERSONAL     se asigna o presta a una persona (o se instala en un área).
 * - uso COMPARTIDO   se instala en un área, con responsable opcional.
 * - uso COMPONENTE   va dentro de otro equipo (o en bodega como repuesto).
 * - uso INSUMO       no es un activo: se reclasifica a consumible.
 * `software`, `componentes`, `red` y `consumibles` indican qué admite.
 */
final class ClasesActivo
{
    public const string PERSONAL = 'PERSONAL';
    public const string COMPARTIDO = 'COMPARTIDO';
    public const string COMPONENTE = 'COMPONENTE';
    public const string INSUMO = 'INSUMO';
    public const string DEFECTO = 'OTRO';

    public const array CLASES = [
        'COMPUTO' => ['uso' => self::PERSONAL, 'software' => true, 'componentes' => true, 'red' => true, 'consumibles' => false],
        'MOVIL' => ['uso' => self::PERSONAL, 'software' => true, 'componentes' => false, 'red' => true, 'consumibles' => false],
        'PERIFERICO' => ['uso' => self::PERSONAL, 'software' => false, 'componentes' => false, 'red' => false, 'consumibles' => false],
        'IMPRESION' => ['uso' => self::COMPARTIDO, 'software' => false, 'componentes' => true, 'red' => true, 'consumibles' => true],
        'RED' => ['uso' => self::COMPARTIDO, 'software' => false, 'componentes' => true, 'red' => true, 'consumibles' => false],
        'ENERGIA' => ['uso' => self::COMPARTIDO, 'software' => false, 'componentes' => true, 'red' => false, 'consumibles' => false],
        'AUDIOVISUAL' => ['uso' => self::COMPARTIDO, 'software' => false, 'componentes' => false, 'red' => true, 'consumibles' => true],
        'COMPONENTE' => ['uso' => self::COMPONENTE, 'software' => false, 'componentes' => false, 'red' => false, 'consumibles' => false],
        'HERRAMIENTA' => ['uso' => self::PERSONAL, 'software' => false, 'componentes' => false, 'red' => false, 'consumibles' => false],
        'INSUMO' => ['uso' => self::INSUMO, 'software' => false, 'componentes' => false, 'red' => false, 'consumibles' => false],
        'OTRO' => ['uso' => self::PERSONAL, 'software' => false, 'componentes' => false, 'red' => false, 'consumibles' => false],
    ];

    /** Clasificación inicial de los tipos por su nombre (el administrador la puede cambiar). */
    private const array POR_NOMBRE = [
        'COMPUTO' => ['laptop', 'desktop', 'servidor', 'all in one', 'all-in-one', 'workstation', 'computadora', 'pc'],
        'MOVIL' => ['tablet', 'telefono', 'celular', 'smartphone', 'movil'],
        'PERIFERICO' => ['monitor', 'mouse', 'teclado', 'docking', 'headset', 'camara', 'bocina', 'adaptador',
            'cargador de docking', 'webcam', 'audifonos', 'diadema'],
        'IMPRESION' => ['impresora', 'multifuncional', 'escaner', 'plotter'],
        'RED' => ['switch', 'router', 'access point', 'firewall', 'modem'],
        'ENERGIA' => ['ups', 'regulador', 'no break'],
        'AUDIOVISUAL' => ['proyector', 'televisor', 'pantalla', 'smart home'],
        'COMPONENTE' => ['disco', 'memoria ram', 'ram', 'bateria', 'cargador de laptop', 'fuente de poder', 'tarjeta de video'],
        'INSUMO' => ['tinta', 'toner', 'cable', 'aire comprimido', 'limpia contactos', 'espuma limpiadora',
            'pasta termica', 'limpia pantalla', 'desengrasante', 'kit de limpieza', 'brochas'],
        'HERRAMIENTA' => ['herramientas', 'aspiradora', 'herramienta'],
    ];

    /** @return array{clase: string, uso: string, software: bool, componentes: bool, red: bool, consumibles: bool} */
    public static function reglas(?string $clase): array
    {
        $clase = null === $clase || '' === $clase ? self::DEFECTO : $clase;

        return ['clase' => $clase, ...(self::CLASES[$clase] ?? self::CLASES[self::DEFECTO])];
    }

    public static function porNombre(string $nombre): string
    {
        $buscado = self::normalizar($nombre);
        foreach (self::POR_NOMBRE as $clase => $nombres) {
            if (\in_array($buscado, $nombres, true)) {
                return $clase;
            }
        }

        return self::DEFECTO;
    }

    /** Sin acentos, recortado y en minúsculas. */
    public static function normalizar(string $texto): string
    {
        $descompuesto = \Normalizer::normalize($texto, \Normalizer::FORM_KD) ?: $texto;

        return mb_strtolower(trim((string) preg_replace('/\p{Mn}+/u', '', $descompuesto)));
    }
}
