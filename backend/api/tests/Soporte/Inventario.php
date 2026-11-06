<?php

declare(strict_types=1);

namespace App\Tests\Soporte;

use App\Tests\Soporte\DatosPrueba as D;

/** Datos de inventario para las pruebas de la fase 3 (se crean si faltan). */
final class Inventario
{
    /** Estado operativo del catálogo por nombre. */
    public static function estado(string $nombre): int
    {
        $c = D::conexion();
        $id = $c->fetchOne('SELECT MIN("EOP_Estado_Operativo") FROM "INV_ESTADO_OPERATIVO" WHERE LOWER("EOP_Nombre") = LOWER(:n)', ['n' => $nombre]);
        if (null === $id) {
            $c->executeStatement('INSERT INTO "INV_ESTADO_OPERATIVO" ("EOP_Nombre") VALUES (:n)', ['n' => $nombre]);
            $id = $c->fetchOne('SELECT MIN("EOP_Estado_Operativo") FROM "INV_ESTADO_OPERATIVO" WHERE LOWER("EOP_Nombre") = LOWER(:n)', ['n' => $nombre]);
        }

        return (int) $id;
    }

    /** Todos los estados canónicos presentes. */
    public static function estados(): void
    {
        foreach (['En Bodega', 'Asignado', 'En Reparación', 'Baja', 'En Tránsito', 'Instalado'] as $e) {
            self::estado($e);
        }
    }

    public static function tipo(string $clase = 'COMPUTO', ?string $prefijo = null): int
    {
        $c = D::conexion();
        $nombre = 'Tipo '.$clase.' '.D::sufijo();
        $prefijo ??= 'T'.strtoupper(substr(D::sufijo(), 0, 5));
        $c->executeStatement('INSERT INTO "INV_TIPO_ACTIVO" ("TAC_Nombre", "TAC_Prefijo", "TAC_Clase", "TAC_Aplica_Depreciacion") VALUES (:n, :p, :c, 1)',
            ['n' => $nombre, 'p' => $prefijo, 'c' => $clase]);

        return (int) $c->fetchOne('SELECT "TAC_Tipo_Activo" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Nombre" = :n', ['n' => $nombre]);
    }

    public static function modelo(?int $tipo = null): int
    {
        $c = D::conexion();
        $marca = 'Marca '.D::sufijo();
        $c->executeStatement('INSERT INTO "INV_MARCA" ("MAR_Nombre") VALUES (:n)', ['n' => $marca]);
        $m = (int) $c->fetchOne('SELECT "MAR_Marca" FROM "INV_MARCA" WHERE "MAR_Nombre" = :n', ['n' => $marca]);
        $nombre = 'Modelo '.D::sufijo();
        $c->executeStatement('INSERT INTO "INV_MODELO" ("MOD_Nombre", "MAR_Marca", "TAC_Tipo_Activo") VALUES (:n, :m, :t)', ['n' => $nombre, 'm' => $m, 't' => $tipo]);

        return (int) $c->fetchOne('SELECT "MOD_Modelo" FROM "INV_MODELO" WHERE "MOD_Nombre" = :n', ['n' => $nombre]);
    }

    public static function area(int $sede): int
    {
        $c = D::conexion();
        $s = D::sufijo();
        $c->executeStatement('INSERT INTO "INV_EDIFICIO" ("EDI_Nombre", "SED_Sede") VALUES (:n, :s)', ['n' => "Edificio {$s}", 's' => $sede]);
        $edi = (int) $c->fetchOne('SELECT "EDI_Edificio" FROM "INV_EDIFICIO" WHERE "EDI_Nombre" = :n', ['n' => "Edificio {$s}"]);
        $c->executeStatement('INSERT INTO "INV_NIVEL" ("NIV_Numero_Piso", "EDI_Edificio") VALUES (:n, :e)', ['n' => "Nivel {$s}", 'e' => $edi]);
        $niv = (int) $c->fetchOne('SELECT "NIV_Nivel" FROM "INV_NIVEL" WHERE "NIV_Numero_Piso" = :n', ['n' => "Nivel {$s}"]);
        $c->executeStatement('INSERT INTO "INV_AREA" ("ARE_Nombre", "ARE_Tipo_Acceso", "NIV_Nivel") VALUES (:n, :t, :v)', ['n' => "Área {$s}", 't' => 'PUBLICO', 'v' => $niv]);

        return (int) $c->fetchOne('SELECT "ARE_Area" FROM "INV_AREA" WHERE "ARE_Nombre" = :n', ['n' => "Área {$s}"]);
    }

    /**
     * Fila de catálogo con código (motivos y destinos de baja, formas de conteo…).
     *
     * @param array<string, mixed> $extra
     */
    public static function codigo(string $tabla, string $prefijo, string $clave, string $codigo, array $extra = []): int
    {
        $c = D::conexion();
        $id = $c->fetchOne("SELECT \"{$clave}\" FROM \"{$tabla}\" WHERE \"{$prefijo}_Codigo\" = :c", ['c' => $codigo]);
        if (false === $id) {
            $cols = ["\"{$prefijo}_Nombre\"", "\"{$prefijo}_Codigo\"", ...array_map(static fn (string $k): string => "\"{$k}\"", array_keys($extra))];
            $vals = [':n', ':c', ...array_map(static fn (int $i): string => ":x{$i}", array_keys(array_values($extra)))];
            $p = ['n' => ucfirst(strtolower($codigo)).' '.D::sufijo(), 'c' => $codigo];
            foreach (array_values($extra) as $i => $v) {
                $p["x{$i}"] = $v;
            }
            $c->executeStatement("INSERT INTO \"{$tabla}\" (".implode(', ', $cols).') VALUES ('.implode(', ', $vals).')', $p);
            $id = $c->fetchOne("SELECT \"{$clave}\" FROM \"{$tabla}\" WHERE \"{$prefijo}_Codigo\" = :c", ['c' => $codigo]);
        }

        return (int) $id;
    }

    public static function motivosBaja(): void
    {
        self::codigo('INV_MOTIVO_BAJA', 'MBA', 'MBA_Motivo', 'OBSOLESCENCIA');
        self::codigo('INV_MOTIVO_BAJA', 'MBA', 'MBA_Motivo', 'PERDIDA');
        self::codigo('INV_MOTIVO_BAJA', 'MBA', 'MBA_Motivo', 'OTRO', ['MBA_Pide_Detalle' => 1]);
        self::codigo('INV_DESTINO_BAJA', 'DBA', 'DBA_Destino', 'RECICLAJE');
        self::codigo('INV_DESTINO_BAJA', 'DBA', 'DBA_Destino', 'NO_RECUPERADO');
    }

    public static function formaConteo(string $codigo): int
    {
        return self::codigo('INV_FORMA_CONTEO', 'FCO', 'FCO_Forma', $codigo);
    }

    public static function software(): int
    {
        $c = D::conexion();
        $n = 'Software '.D::sufijo();
        $c->executeStatement('INSERT INTO "INV_SOFTWARE" ("SOF_Nombre", "SOF_Version", "SOF_Fabricante") VALUES (:n, :v, :f)', ['n' => $n, 'v' => '1.0', 'f' => 'Fabricante']);

        return (int) $c->fetchOne('SELECT "SOF_Software" FROM "INV_SOFTWARE" WHERE "SOF_Nombre" = :n', ['n' => $n]);
    }

    public static function tipoLicencia(): int
    {
        $c = D::conexion();
        $n = 'TL '.D::sufijo();
        $c->executeStatement('INSERT INTO "INV_TIPO_LICENCIA" ("TLI_Nombre") VALUES (:n)', ['n' => $n]);

        return (int) $c->fetchOne('SELECT "TLI_Tipo_Licencia" FROM "INV_TIPO_LICENCIA" WHERE "TLI_Nombre" = :n', ['n' => $n]);
    }

    public static function proveedor(): int
    {
        $c = D::conexion();
        $n = 'Proveedor '.D::sufijo();
        $c->executeStatement('INSERT INTO "INV_PROVEEDOR" ("PRV_Nombre", "PRV_Activo") VALUES (:n, 1)', ['n' => $n]);

        return (int) $c->fetchOne('SELECT "PRV_Proveedor" FROM "INV_PROVEEDOR" WHERE "PRV_Nombre" = :n', ['n' => $n]);
    }

    /** El sellado del kardex es una cadena consecutiva y sin huecos. */
    public static function cadenaKardexIntegra(): bool
    {
        $c = D::conexion();
        $filas = $c->fetchAllAssociative('SELECT "KDX_Secuencia", "KDX_Hash", "KDX_Hash_Anterior" FROM "INV_KARDEX" WHERE "KDX_Secuencia" IS NOT NULL ORDER BY "KDX_Secuencia"');
        $anterior = null;
        foreach ($filas as $f) {
            if (null !== $anterior && ((int) $f['KDX_Secuencia'] !== (int) $anterior['KDX_Secuencia'] + 1 || $f['KDX_Hash_Anterior'] !== $anterior['KDX_Hash'])) {
                return false;
            }
            $anterior = $f;
        }

        return true;
    }
}
