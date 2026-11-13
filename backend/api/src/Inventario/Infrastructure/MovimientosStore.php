<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure;

use App\Db\InsercionOracle;
use App\Dominio\Reloj;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Custody movements, their types and reasons, and what they read about people and areas, as stored: all that SQL lives here. */
final readonly class MovimientosStore
{
    private const string COLUMNAS = 'm."MOV_Movimiento", m."MOV_Fecha_Asignacion", m."MOV_Fecha_Devolucion", m."MOV_Observacion", m."MOV_Usuario_Registra", '
        .'m."MOV_Motivo", m."MMO_Motivo", m."MOV_Motivo_Detalle", m."MOV_Fecha_Prevista_Devolucion", m."ACT_Activo", m."PER_Persona", m."ARE_Area", '
        .'m."TMO_Tipo_Movimiento", a."ACT_Codigo_Interno", a."ACT_Hostname", p."PER_Primer_Nombre", p."PER_Primer_Apellido", p."PER_Email_Corporativo", '
        .'t."TMO_Nombre", ar."ARE_Nombre"';
    private const string JOINS = 'LEFT JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = m."PER_Persona" '
        .'LEFT JOIN "INV_TIPO_MOVIMIENTO" t ON t."TMO_Tipo_Movimiento" = m."TMO_Tipo_Movimiento" LEFT JOIN "INV_AREA" ar ON ar."ARE_Area" = m."ARE_Area"';
    private const string VIGENTES_DE_PERSONA = 'm."PER_Persona" = :p AND m."MOV_Fecha_Devolucion" IS NULL';

    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    // ---- Movement types and reasons ----

    /** @return list<array<string, mixed>> TMO_Tipo_Movimiento and TMO_Nombre, by id */
    public function tipos(): array
    {
        return $this->conexion->fetchAllAssociative('SELECT "TMO_Tipo_Movimiento", "TMO_Nombre" FROM "INV_TIPO_MOVIMIENTO" ORDER BY "TMO_Tipo_Movimiento"');
    }

    /** @return mixed the name of a movement type; false when it does not exist */
    public function nombreDeTipo(int $id): mixed
    {
        return $this->conexion->fetchOne('SELECT "TMO_Nombre" FROM "INV_TIPO_MOVIMIENTO" WHERE "TMO_Tipo_Movimiento" = :id', ['id' => $id]);
    }

    /** The movement type with that name, whatever its case. */
    public function idDeTipo(string $nombre): ?int
    {
        $id = $this->conexion->fetchOne('SELECT "TMO_Tipo_Movimiento" FROM "INV_TIPO_MOVIMIENTO" WHERE LOWER("TMO_Nombre") = LOWER(:n) FETCH FIRST 1 ROWS ONLY', ['n' => $nombre]);

        return false === $id ? null : (int) $id;
    }

    /** The oldest movement type whose name speaks of an assignment; null when the catalogue has none. */
    public function primerTipoDeAsignacion(): ?int
    {
        return ((int) $this->conexion->fetchOne("SELECT MIN(\"TMO_Tipo_Movimiento\") FROM \"INV_TIPO_MOVIMIENTO\" WHERE LOWER(\"TMO_Nombre\") LIKE '%asignaci%'")) ?: null;
    }

    public function crearTipo(string $nombre): int
    {
        return (int) InsercionOracle::insertar($this->conexion, 'INV_TIPO_MOVIMIENTO', ['TMO_Nombre' => $nombre], 'TMO_Tipo_Movimiento');
    }

    public function renombrarTipo(int $id, mixed $nombre): void
    {
        $this->conexion->executeStatement('UPDATE "INV_TIPO_MOVIMIENTO" SET "TMO_Nombre" = :n WHERE "TMO_Tipo_Movimiento" = :id', ['n' => $nombre, 'id' => $id]);
    }

    /** Whether any movement uses that type (run it elevated to check every site). */
    public function tipoEnUso(int $id): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_MOVIMIENTO" WHERE "TMO_Tipo_Movimiento" = :id FETCH FIRST 1 ROWS ONLY', ['id' => $id]);
    }

    /** @return array<string, mixed>|false MMO_Nombre, MMO_Activo, MMO_Aplica_A and MMO_Pide_Detalle of a movement reason */
    public function motivo(mixed $id): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "MMO_Nombre", "MMO_Activo", "MMO_Aplica_A", "MMO_Pide_Detalle" FROM "INV_MOTIVO_MOVIMIENTO" WHERE "MMO_Motivo" = :id', ['id' => $id]);
    }

    // ---- Reading movements ----

    /** @return list<array<string, mixed>> a page of the visible movements, newest first */
    public function pagina(int $saltar, int $limite): array
    {
        return $this->consultar('1 = 1', ['saltar' => $saltar, 'limite' => $limite], ' OFFSET :saltar ROWS FETCH NEXT :limite ROWS ONLY');
    }

    /** @return list<array<string, mixed>> the visible movements of an asset, newest first */
    public function deActivo(string $activo): array
    {
        return $this->consultar('m."ACT_Activo" = :a', ['a' => $activo]);
    }

    /** @return list<array<string, mixed>> the visible open movements of a person, newest first */
    public function vigentesDePersona(string $persona): array
    {
        return $this->consultar(self::VIGENTES_DE_PERSONA, ['p' => $persona]);
    }

    /**
     * @param array<string, mixed> $parametros
     *
     * @return list<array<string, mixed>>
     */
    private function consultar(string $donde, array $parametros, string $pagina = ''): array
    {
        [$alcance, $pa] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');

        return $this->conexion->fetchAllAssociative('SELECT '.self::COLUMNAS.' FROM "INV_MOVIMIENTO" m '.self::JOINS.' WHERE '.$donde.' AND '.$alcance
            .' ORDER BY m."MOV_Fecha_Asignacion" DESC, m."MOV_Movimiento"'.$pagina, [...$parametros, ...$pa]);
    }

    /** @return array<string, mixed>|false the movement with its asset, person, type and area, whatever its site */
    public function porId(string $movimiento): array|false
    {
        return $this->conexion->fetchAssociative('SELECT '.self::COLUMNAS.' FROM "INV_MOVIMIENTO" m '.self::JOINS.' WHERE m."MOV_Movimiento" = :m', ['m' => $movimiento]);
    }

    /** @return array<string, mixed>|false the open movement of an asset (MOV_Movimiento, PER_Persona, ARE_Area, MOV_Fecha_Asignacion, MOV_Usuario_Registra), locked when asked */
    public function vigenteDe(string $activo, bool $bloquear): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "MOV_Movimiento", "PER_Persona", "ARE_Area", "MOV_Fecha_Asignacion", "MOV_Usuario_Registra" FROM "INV_MOVIMIENTO" '
            .'WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL'.($bloquear ? ' FOR UPDATE' : ''), ['a' => $activo]);
    }

    /** How many assets the person holds, counting only the ones the current user may see. */
    public function custodiasVisiblesDe(string $persona): int
    {
        [$alcance, $pa] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');

        return (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_MOVIMIENTO" m WHERE '.self::VIGENTES_DE_PERSONA.' AND '.$alcance, [...$pa, 'p' => $persona]);
    }

    /** How many assets the person holds (run it elevated to count every site). */
    public function custodiasDe(string $persona): int
    {
        return (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_MOVIMIENTO" m WHERE '.self::VIGENTES_DE_PERSONA, ['p' => $persona]);
    }

    // ---- Writing movements ----

    /** @param array{id: string, obs: mixed, usuario: string, motivo: mixed, mmo: mixed, detalle: mixed, prevista: mixed, activo: mixed, persona: mixed, area: mixed, tipo: mixed} $movimiento */
    public function insertar(array $movimiento, \DateTimeImmutable $ahora): void
    {
        $this->conexion->executeStatement('INSERT INTO "INV_MOVIMIENTO" ("MOV_Movimiento", "MOV_Fecha_Asignacion", "MOV_Observacion", "MOV_Usuario_Registra", '
            .'"MOV_Motivo", "MMO_Motivo", "MOV_Motivo_Detalle", "MOV_Fecha_Prevista_Devolucion", "ACT_Activo", "PER_Persona", "ARE_Area", "TMO_Tipo_Movimiento") '
            ."VALUES (:id, :fecha, :obs, :usuario, :motivo, :mmo, :detalle, TO_DATE(:prevista, 'YYYY-MM-DD'), :activo, :persona, :area, :tipo)",
            [...$movimiento, 'fecha' => Reloj::aBd($ahora)], ['obs' => ParameterType::LARGE_OBJECT]);
    }

    /** Closes the movement if it is still open; false when someone else closed it first. */
    public function cerrar(string $movimiento, \DateTimeImmutable $ahora): bool
    {
        return 1 === $this->conexion->executeStatement('UPDATE "INV_MOVIMIENTO" SET "MOV_Fecha_Devolucion" = :ahora WHERE "MOV_Movimiento" = :m AND "MOV_Fecha_Devolucion" IS NULL',
            ['ahora' => Reloj::aBd($ahora), 'm' => $movimiento]);
    }

    /** Writes the reason of the return on the movement: its text and, when it comes from the catalogue, its id and detail. */
    public function anotarMotivo(mixed $movimiento, mixed $texto, ?int $motivo, ?string $detalle): void
    {
        if (null !== $texto && '' !== $texto) {
            $this->conexion->executeStatement('UPDATE "INV_MOVIMIENTO" SET "MOV_Motivo" = :m WHERE "MOV_Movimiento" = :id', ['m' => $texto, 'id' => $movimiento]);
        }
        if (null !== $motivo) {
            $this->conexion->executeStatement('UPDATE "INV_MOVIMIENTO" SET "MMO_Motivo" = :m, "MOV_Motivo_Detalle" = :d WHERE "MOV_Movimiento" = :id',
                ['m' => $motivo, 'd' => $detalle, 'id' => $movimiento]);
        }
    }

    /**
     * Locks, in the one order the whole system uses (assets by id, then movements), everything the person holds.
     *
     * @return list<array<string, mixed>> MOV_Movimiento, ACT_Activo and ARE_Area of the open movements, oldest first
     */
    public function bloquearCustodiasDe(string $persona): array
    {
        $this->conexion->fetchAllNumeric('SELECT "ACT_Activo" FROM "INV_ACTIVO" WHERE "ACT_Activo" IN (SELECT "ACT_Activo" FROM "INV_MOVIMIENTO" WHERE "PER_Persona" = :p '
            .'AND "MOV_Fecha_Devolucion" IS NULL) ORDER BY "ACT_Activo" FOR UPDATE', ['p' => $persona]);

        return $this->conexion->fetchAllAssociative('SELECT "MOV_Movimiento", "ACT_Activo", "ARE_Area" FROM "INV_MOVIMIENTO" WHERE "PER_Persona" = :p '
            .'AND "MOV_Fecha_Devolucion" IS NULL ORDER BY "MOV_Fecha_Asignacion" FOR UPDATE', ['p' => $persona]);
    }

    /** Closes every open movement of the person; returns how many. */
    public function cerrarTodosDe(string $persona, \DateTimeImmutable $ahora): int
    {
        return (int) $this->conexion->executeStatement('UPDATE "INV_MOVIMIENTO" SET "MOV_Fecha_Devolucion" = :ahora WHERE "PER_Persona" = :p AND "MOV_Fecha_Devolucion" IS NULL',
            ['ahora' => Reloj::aBd($ahora), 'p' => $persona]);
    }

    // ---- What a movement reads and writes about assets, people and areas ----

    public function tieneBajaPendiente(string $activo): bool
    {
        return false !== $this->conexion->fetchOne("SELECT 1 FROM \"INV_APROBACION\" WHERE \"ACT_Activo\" = :a AND \"APR_Tipo\" = 'BAJA' AND \"APR_Estado\" = 'PENDIENTE' "
            .'FETCH FIRST 1 ROWS ONLY', ['a' => $activo]);
    }

    /** @return array<string, mixed>|false code, serial number, hostname, type, model and brand names of an asset */
    public function fichaDeActivo(string $activo): array|false
    {
        return $this->conexion->fetchAssociative('SELECT a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", a."ACT_Hostname", t."TAC_Nombre", m."MOD_Nombre", ma."MAR_Nombre" '
            .'FROM "INV_ACTIVO" a LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" LEFT JOIN "INV_MODELO" m ON m."MOD_Modelo" = a."MOD_Modelo" '
            .'LEFT JOIN "INV_MARCA" ma ON ma."MAR_Marca" = m."MAR_Marca" WHERE a."ACT_Activo" = :a', ['a' => $activo]);
    }

    /** @return array<string, mixed>|false where an asset is installed and who answers for it */
    public function instalacionEnArea(string $activo): array|false
    {
        return $this->conexion->fetchAssociative('SELECT a."ACT_Activo", a."ACT_Codigo_Interno", a."ACT_Instalado", a."ARE_Area_Ubicacion", ar."ARE_Nombre", '
            .'a."ACT_Responsable", p."PER_Primer_Nombre", p."PER_Primer_Apellido" FROM "INV_ACTIVO" a LEFT JOIN "INV_AREA" ar ON ar."ARE_Area" = a."ARE_Area_Ubicacion" '
            .'LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = a."ACT_Responsable" WHERE a."ACT_Activo" = :a', ['a' => $activo]);
    }

    /** The components installed in an asset follow it to its area and site. */
    public function ubicarComponentesDe(mixed $equipo, int $area, ?int $sede): void
    {
        $this->conexion->executeStatement('UPDATE "INV_ACTIVO" SET "ARE_Area_Ubicacion" = :a, "SED_Sede" = :s WHERE "ACT_Activo_Padre" = :id', ['a' => $area, 's' => $sede, 'id' => $equipo]);
    }

    /** @return mixed the name of an area; false when it does not exist */
    public function nombreDeArea(int $area): mixed
    {
        return $this->conexion->fetchOne('SELECT "ARE_Nombre" FROM "INV_AREA" WHERE "ARE_Area" = :a', ['a' => $area]);
    }

    /** @return array<string, mixed>|false the whole person row, if the current user may see it */
    public function personaVisible(string $persona): array|false
    {
        [$alcance, $p] = $this->alcance->lectura('INV_PERSONA', 'p');

        return $this->conexion->fetchAssociative('SELECT p.* FROM "INV_PERSONA" p WHERE p."PER_Persona" = :id AND '.$alcance, [...$p, 'id' => $persona]);
    }

    /** @return array<string, mixed>|false PER_Primer_Nombre, PER_Primer_Apellido and PER_Email_Corporativo */
    public function contactoDePersona(mixed $persona): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "PER_Primer_Nombre", "PER_Primer_Apellido", "PER_Email_Corporativo" FROM "INV_PERSONA" WHERE "PER_Persona" = :p', ['p' => $persona]);
    }

    /** A person without site takes the site of the first asset they receive. */
    public function fijarSedeDePersonaSiFalta(mixed $persona, int $sede): void
    {
        $this->conexion->executeStatement('UPDATE "INV_PERSONA" SET "SED_Sede" = :s WHERE "PER_Persona" = :p AND "SED_Sede" IS NULL', ['s' => $sede, 'p' => $persona]);
    }

    public function desactivarPersona(string $persona): void
    {
        $this->conexion->executeStatement('UPDATE "INV_PERSONA" SET "PER_Estado" = 0 WHERE "PER_Persona" = :p', ['p' => $persona]);
    }

    /** @return array<string, mixed>|false USU_Usuario, USU_Rol and USU_Estado of the account of a person */
    public function cuentaDePersona(string $persona): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "USU_Usuario", "USU_Rol", "USU_Estado" FROM "INV_USUARIO" WHERE "PER_Persona" = :p', ['p' => $persona]);
    }
}
