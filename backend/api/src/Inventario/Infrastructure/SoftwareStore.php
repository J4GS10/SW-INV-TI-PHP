<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure;

use App\Db\InsercionOracle;
use App\Dominio\Reloj;
use Doctrine\DBAL\Connection;

/** Software, license types, licenses, their keys and their installations, as stored: all that SQL lives here. */
final readonly class SoftwareStore
{
    private const string TIPO = '"TLI_Tipo_Licencia", "TLI_Nombre", "TLI_Descripcion"';
    private const string SOFTWARE = '"SOF_Software", "SOF_Nombre", "SOF_Version", "SOF_Fabricante", "MAR_Marca"';
    private const string LICENCIA = 'l."LIC_Licencia", l."LIC_Clave_Activacion", l."LIC_Fecha_Vencimiento", l."LIC_Cantidad_Total", '
        .'l."LIC_Cantidad_Usada", l."LIC_Costo", l."LIC_Moneda", l."MCO_Modelo_Comercial", l."MAC_Metodo_Activacion", l."FCO_Forma_Conteo", '
        .'l."LIC_Proveedor", l."LIC_Fecha_Inicio", l."LIC_Renovacion_Automatica", l."LIC_Notas", l."SOF_Software", l."TLI_Tipo_Licencia"';
    private const string INSTALACION = '"INS_Instalacion", "INS_Fecha_Instalacion", "INS_Estado", "INS_Fecha_Retiro", "INS_Motivo_Retiro", '
        .'"ACT_Activo", "PER_Persona", "LIC_Licencia", "LCL_Licencia_Clave"';

    public function __construct(private Connection $conexion)
    {
    }

    // ---- License types and software ----

    /** @return list<array<string, mixed>> */
    public function tiposDeLicencia(): array
    {
        return $this->conexion->fetchAllAssociative('SELECT '.self::TIPO.' FROM "INV_TIPO_LICENCIA" ORDER BY "TLI_Tipo_Licencia"');
    }

    /** @return array<string, mixed>|false */
    public function tipoDeLicencia(int $id): array|false
    {
        return $this->conexion->fetchAssociative('SELECT '.self::TIPO.' FROM "INV_TIPO_LICENCIA" WHERE "TLI_Tipo_Licencia" = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $columnas */
    public function crearTipoDeLicencia(array $columnas): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_TIPO_LICENCIA', $columnas, 'TLI_Tipo_Licencia');
    }

    public function tipoDeLicenciaEnUso(int $id): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_LICENCIA" WHERE "TLI_Tipo_Licencia" = :id FETCH FIRST 1 ROWS ONLY', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function todoElSoftware(): array
    {
        return $this->conexion->fetchAllAssociative('SELECT '.self::SOFTWARE.' FROM "INV_SOFTWARE" ORDER BY "SOF_Software"');
    }

    /** @return array<string, mixed>|false */
    public function software(mixed $id): array|false
    {
        return $this->conexion->fetchAssociative('SELECT '.self::SOFTWARE.' FROM "INV_SOFTWARE" WHERE "SOF_Software" = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $columnas */
    public function crearSoftware(array $columnas): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_SOFTWARE', $columnas, 'SOF_Software');
    }

    public function softwareTieneLicencias(int $id): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_LICENCIA" WHERE "SOF_Software" = :id FETCH FIRST 1 ROWS ONLY', ['id' => $id]);
    }

    /** The active brand with that name, whatever its case and outer spaces. */
    public function marcaActivaPorNombre(string $nombre): ?int
    {
        $marca = $this->conexion->fetchOne('SELECT "MAR_Marca" FROM "INV_MARCA" WHERE LOWER(TRIM("MAR_Nombre")) = :n AND "MAR_Activo" = 1 '
            .'FETCH FIRST 1 ROWS ONLY', ['n' => mb_strtolower($nombre)]);

        return false === $marca ? null : (int) $marca;
    }

    /**
     * Writes the given columns of a row of one of the tables of this module; the columns in `$fechas` hold YYYY-MM-DD dates.
     *
     * @param array<string, mixed> $cambios column => value; table and column names come from the code and the validated form
     * @param list<string>         $fechas
     */
    public function actualizar(string $tabla, string $clave, int $id, array $cambios, array $fechas = []): void
    {
        if ([] === $cambios) {
            return;
        }
        $sets = [];
        $p = ['id' => $id];
        foreach ($cambios as $c => $v) {
            $sets[] = \in_array($c, $fechas, true) ? "\"{$c}\" = TO_DATE(:{$c}, 'YYYY-MM-DD')" : "\"{$c}\" = :{$c}";
            $p[$c] = \is_bool($v) ? (int) $v : $v;
        }
        $this->conexion->executeStatement("UPDATE \"{$tabla}\" SET ".implode(', ', $sets)." WHERE \"{$clave}\" = :id", $p);
    }

    // ---- Licenses and keys ----

    /** @return array<string, mixed>|false the license with its counting form code; with `$bloquear`, its used seats locked */
    public function licencia(int $id, bool $bloquear = false): array|false
    {
        return $this->conexion->fetchAssociative('SELECT '.self::LICENCIA.', fc."FCO_Codigo" FROM "INV_LICENCIA" l '
            .'LEFT JOIN "INV_FORMA_CONTEO" fc ON fc."FCO_Forma" = l."FCO_Forma_Conteo" WHERE l."LIC_Licencia" = :id'
            .($bloquear ? ' FOR UPDATE OF l."LIC_Cantidad_Usada"' : ''), ['id' => $id]);
    }

    /** @return list<array<string, mixed>> the licenses of a software, oldest first */
    public function licenciasDe(int $software): array
    {
        return $this->conexion->fetchAllAssociative('SELECT '.self::LICENCIA.' FROM "INV_LICENCIA" l WHERE l."SOF_Software" = :s ORDER BY l."LIC_Licencia"', ['s' => $software]);
    }

    /**
     * Every license with its software, counting form and activation method codes, by software name.
     *
     * @return list<array<string, mixed>>
     */
    public function licenciasConSoftware(?string $forma): array
    {
        $sql = 'SELECT l."LIC_Licencia", l."LIC_Cantidad_Total", l."LIC_Cantidad_Usada", l."LIC_Fecha_Vencimiento", s."SOF_Software", s."SOF_Nombre", '
            .'s."SOF_Version", fc."FCO_Codigo", ma."MAC_Codigo" FROM "INV_LICENCIA" l JOIN "INV_SOFTWARE" s ON s."SOF_Software" = l."SOF_Software" '
            .'LEFT JOIN "INV_FORMA_CONTEO" fc ON fc."FCO_Forma" = l."FCO_Forma_Conteo" LEFT JOIN "INV_METODO_ACTIVACION" ma ON ma."MAC_Metodo" = l."MAC_Metodo_Activacion"';
        $p = [];
        if (null !== $forma) {
            $sql .= ' WHERE fc."FCO_Codigo" = :forma';
            $p['forma'] = $forma;
        }

        return $this->conexion->fetchAllAssociative($sql.' ORDER BY s."SOF_Nombre", l."LIC_Licencia"', $p);
    }

    /**
     * @param array<string, mixed> $columnas
     * @param ?string              $vence    YYYY-MM-DD
     * @param ?string              $inicio   YYYY-MM-DD
     */
    public function crearLicencia(array $columnas, ?string $vence, ?string $inicio): int
    {
        $id = (int) InsercionOracle::insertar($this->conexion, 'INV_LICENCIA', $columnas, 'LIC_Licencia');
        $this->conexion->executeStatement('UPDATE "INV_LICENCIA" SET "LIC_Fecha_Vencimiento" = TO_DATE(:v, \'YYYY-MM-DD\'), '
            .'"LIC_Fecha_Inicio" = TO_DATE(:i, \'YYYY-MM-DD\') WHERE "LIC_Licencia" = :id', ['v' => $vence, 'i' => $inicio, 'id' => $id]);

        return $id;
    }

    /** Adds an available key; `$cifrada` and `$huella` are its encrypted value and its fingerprint. */
    public function agregarClave(int $licencia, ?string $cifrada, string $huella, mixed $referencia = null): void
    {
        $this->conexion->executeStatement("INSERT INTO \"INV_LICENCIA_CLAVE\" (\"LIC_Licencia\", \"LCL_Clave_Activacion\", \"LCL_Clave_Hash\", "
            ."\"LCL_Referencia\", \"LCL_Estado\", \"LCL_Requiere_Cambio\") VALUES (:l, :c, :h, :r, 'DISPONIBLE', 0)",
            ['l' => $licencia, 'c' => $cifrada, 'h' => $huella, 'r' => $referencia]);
    }

    public function tieneClaves(int $licencia): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_LICENCIA_CLAVE" WHERE "LIC_Licencia" = :id FETCH FIRST 1 ROWS ONLY', ['id' => $licencia]);
    }

    /** @return array<mixed, mixed> key state => how many keys of the license are in it */
    public function clavesPorEstado(int $licencia): array
    {
        return $this->conexion->fetchAllKeyValue('SELECT "LCL_Estado", COUNT(*) FROM "INV_LICENCIA_CLAVE" WHERE "LIC_Licencia" = :id GROUP BY "LCL_Estado"', ['id' => $licencia]);
    }

    /** @return list<array<string, mixed>> the keys of a license with their current installation, asset and person */
    public function clavesConDestino(int $licencia): array
    {
        return $this->conexion->fetchAllAssociative('SELECT c."LCL_Licencia_Clave", c."LIC_Licencia", c."LCL_Clave_Activacion", c."LCL_Referencia", '
            .'c."LCL_Estado", c."CREATED_AT", i."INS_Fecha_Instalacion", i."ACT_Activo", i."PER_Persona", a."ACT_Codigo_Interno", a."ACT_Hostname", '
            .'a."ACT_Serie_Fabricante", p."PER_Primer_Nombre", p."PER_Segundo_Nombre", p."PER_Primer_Apellido", p."PER_Segundo_Apellido", p."PER_Email_Corporativo" '
            .'FROM "INV_LICENCIA_CLAVE" c LEFT JOIN "INV_INSTALACION" i ON i."LCL_Licencia_Clave" = c."LCL_Licencia_Clave" AND i."INS_Estado" = 1 '
            .'LEFT JOIN "INV_ACTIVO" a ON a."ACT_Activo" = i."ACT_Activo" LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = i."PER_Persona" '
            .'WHERE c."LIC_Licencia" = :id ORDER BY c."LCL_Licencia_Clave"', ['id' => $licencia]);
    }

    /** @return mixed the license a key belongs to; false when the key does not exist */
    public function licenciaDeClave(int $clave): mixed
    {
        return $this->conexion->fetchOne('SELECT "LIC_Licencia" FROM "INV_LICENCIA_CLAVE" WHERE "LCL_Licencia_Clave" = :c', ['c' => $clave]);
    }

    /** @return list<int> up to 20 keys of the license that can be assigned now, oldest first */
    public function clavesAsignables(int $licencia): array
    {
        return array_map('intval', $this->conexion->fetchFirstColumn("SELECT \"LCL_Licencia_Clave\" FROM \"INV_LICENCIA_CLAVE\" WHERE \"LIC_Licencia\" = :l "
            ."AND \"LCL_Estado\" = 'DISPONIBLE' AND \"LCL_Requiere_Cambio\" = 0 ORDER BY \"LCL_Licencia_Clave\" FETCH FIRST 20 ROWS ONLY", ['l' => $licencia]));
    }

    /** Takes the key in one statement; false when someone else took it first or it must be changed. */
    public function reservarClave(int $licencia, int $clave): bool
    {
        return 1 === $this->conexion->executeStatement("UPDATE \"INV_LICENCIA_CLAVE\" SET \"LCL_Estado\" = 'ASIGNADA' WHERE \"LCL_Licencia_Clave\" = :c "
            ."AND \"LIC_Licencia\" = :l AND \"LCL_Estado\" = 'DISPONIBLE' AND \"LCL_Requiere_Cambio\" = 0", ['c' => $clave, 'l' => $licencia]);
    }

    public function liberarClave(int $clave): void
    {
        $this->conexion->executeStatement("UPDATE \"INV_LICENCIA_CLAVE\" SET \"LCL_Estado\" = 'DISPONIBLE' WHERE \"LCL_Licencia_Clave\" = :c "
            ."AND \"LCL_Estado\" = 'ASIGNADA'", ['c' => $clave]);
    }

    /** Takes one seat in one statement; false when none is left. */
    public function tomarCupo(int $licencia): bool
    {
        return 1 === $this->conexion->executeStatement('UPDATE "INV_LICENCIA" SET "LIC_Cantidad_Usada" = "LIC_Cantidad_Usada" + 1 '
            .'WHERE "LIC_Licencia" = :l AND "LIC_Cantidad_Usada" < "LIC_Cantidad_Total"', ['l' => $licencia]);
    }

    public function devolverCupo(int $licencia): void
    {
        $this->conexion->executeStatement('UPDATE "INV_LICENCIA" SET "LIC_Cantidad_Usada" = "LIC_Cantidad_Usada" - 1 WHERE "LIC_Licencia" = :l '
            .'AND "LIC_Cantidad_Usada" > 0', ['l' => $licencia]);
    }

    /** @return mixed the used seats of a license; false when the license does not exist */
    public function cuposUsados(int $licencia): mixed
    {
        return $this->conexion->fetchOne('SELECT "LIC_Cantidad_Usada" FROM "INV_LICENCIA" WHERE "LIC_Licencia" = :l', ['l' => $licencia]);
    }

    // ---- Installations ----

    public function instalacionesActivasDe(int $licencia): int
    {
        return (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_INSTALACION" WHERE "LIC_Licencia" = :id AND "INS_Estado" = 1', ['id' => $licencia]);
    }

    /**
     * The installations of an asset (`ACT_Activo`) or a person (`PER_Persona`) with their license, software and license type.
     *
     *
     * @return list<array<string, mixed>>
     */
    public function instalacionesDe(string $columna, string $destino, bool $soloActivas): array
    {
        return $this->conexion->fetchAllAssociative('SELECT i."INS_Instalacion", i."INS_Fecha_Instalacion", i."INS_Estado", i."INS_Fecha_Retiro", '
            .'i."INS_Motivo_Retiro", i."ACT_Activo", i."PER_Persona", i."LIC_Licencia", i."LCL_Licencia_Clave", l."LIC_Fecha_Vencimiento", '
            .'l."LIC_Cantidad_Total", l."LIC_Cantidad_Usada", s."SOF_Software", s."SOF_Nombre", s."SOF_Version", s."SOF_Fabricante", t."TLI_Tipo_Licencia", '
            .'t."TLI_Nombre" FROM "INV_INSTALACION" i JOIN "INV_LICENCIA" l ON l."LIC_Licencia" = i."LIC_Licencia" '
            .'LEFT JOIN "INV_SOFTWARE" s ON s."SOF_Software" = l."SOF_Software" LEFT JOIN "INV_TIPO_LICENCIA" t ON t."TLI_Tipo_Licencia" = l."TLI_Tipo_Licencia" '
            .'WHERE i."'.$columna.'" = :d'.($soloActivas ? ' AND i."INS_Estado" = 1' : '').' ORDER BY i."INS_Instalacion"', ['d' => $destino]);
    }

    /** @return array<string, mixed>|false the active installation of a license on that asset or, without asset, for that person; with INS_Consume_Cupo */
    public function instalacionActiva(int $licencia, ?string $activo, ?string $persona): array|false
    {
        return $this->conexion->fetchAssociative('SELECT '.self::INSTALACION.', "INS_Consume_Cupo" FROM "INV_INSTALACION" '
            .'WHERE "LIC_Licencia" = :l AND "INS_Estado" = 1 AND '.(null !== $activo ? '"ACT_Activo" = :d' : '"PER_Persona" = :d')
            .' FETCH FIRST 1 ROWS ONLY', ['l' => $licencia, 'd' => $activo ?? $persona]);
    }

    /** @return array<string, mixed>|false */
    public function instalacion(int $id): array|false
    {
        return $this->conexion->fetchAssociative('SELECT '.self::INSTALACION.' FROM "INV_INSTALACION" WHERE "INS_Instalacion" = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $columnas */
    public function crearInstalacion(array $columnas): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_INSTALACION', $columnas, 'INS_Instalacion');
    }

    /** Marks the installation as removed if it is still active; false when someone else removed it first. */
    public function retirarInstalacion(int $id, \DateTimeImmutable $ahora, ?string $motivo): bool
    {
        return 1 === $this->conexion->executeStatement('UPDATE "INV_INSTALACION" SET "INS_Estado" = 0, "INS_Fecha_Retiro" = :ahora, "INS_Motivo_Retiro" = :m '
            .'WHERE "INS_Instalacion" = :id AND "INS_Estado" = 1', ['ahora' => Reloj::aBd($ahora), 'm' => $motivo, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> every installation the license ever had, with asset code and person name, newest first */
    public function historialDe(int $licencia): array
    {
        return $this->conexion->fetchAllAssociative('SELECT i."INS_Instalacion", i."ACT_Activo", i."PER_Persona", i."LCL_Licencia_Clave", i."INS_Fecha_Instalacion", '
            .'i."INS_Estado", i."INS_Fecha_Retiro", i."INS_Motivo_Retiro", a."ACT_Codigo_Interno", p."PER_Primer_Nombre", p."PER_Primer_Apellido" '
            .'FROM "INV_INSTALACION" i LEFT JOIN "INV_ACTIVO" a ON a."ACT_Activo" = i."ACT_Activo" LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = i."PER_Persona" '
            .'WHERE i."LIC_Licencia" = :l ORDER BY i."INS_Fecha_Instalacion" DESC, i."INS_Instalacion" DESC', ['l' => $licencia]);
    }

    // ---- What an installation reads about its target ----

    public function existeActivo(string $activo): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_ACTIVO" WHERE "ACT_Activo" = :a', ['a' => $activo]);
    }

    /** @return mixed PER_Estado of the person; false when there is no such person */
    public function estadoDePersona(string $persona): mixed
    {
        return $this->conexion->fetchOne('SELECT "PER_Estado" FROM "INV_PERSONA" WHERE "PER_Persona" = :p', ['p' => $persona]);
    }

    /** The site of the asset or, without asset, of the person; null when unknown. */
    public function sedeDeDestino(?string $activo, ?string $persona): ?int
    {
        $sede = null !== $activo
            ? $this->conexion->fetchOne('SELECT "SED_Sede" FROM "INV_ACTIVO" WHERE "ACT_Activo" = :a', ['a' => $activo])
            : (null !== $persona ? $this->conexion->fetchOne('SELECT "SED_Sede" FROM "INV_PERSONA" WHERE "PER_Persona" = :p', ['p' => $persona]) : false);

        return false === $sede || null === $sede ? null : (int) $sede;
    }
}
