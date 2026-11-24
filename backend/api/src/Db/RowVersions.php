<?php

declare(strict_types=1);

namespace App\Db;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Optimistic concurrency for record edits. Every editable table has a ROW_VERSION that an Oracle
 * trigger raises on each UPDATE, whoever makes it (a form, an assignment, the directory sync).
 * The screen sends the version it loaded (If-Match, App\Http\RowVersionListener) and the edit is
 * refused if the row moved on in between:
 *
 * - expect(): compares at once, so a stale form gets its answer before any rule runs;
 * - lockPending(): App\Db\Transaccion calls it first thing in the outermost transaction of the
 *   request. It locks the row (FOR UPDATE) and compares again, so nobody can save between the
 *   check and this request's own UPDATE.
 *
 * A row that is missing or outside the user's sites (VPD) is not judged here: the use case
 * answers 404 or 403 as usual.
 */
final class RowVersions implements ResetInterface
{
    public const string COLUMN = 'ROW_VERSION';

    /**
     * Versioned tables and the columns whose change counts as an edit (null: any column). The others are
     * left out so that routine writes do not reject forms: logins and lockouts on a user, stock moving on a
     * consumable, licence seats being used, the directory sync stamping its time.
     *
     * @var array<string, list<string>|null>
     */
    public const array TABLES = [
        'INV_ACCION_MANTENIMIENTO' => null, 'INV_ACTIVO' => null, 'INV_AREA' => null, 'INV_CARGO' => null,
        'INV_CATEGORIA_CONSUMIBLE' => null,
        'INV_CONSUMIBLE' => ['CON_Nombre', 'CON_Descripcion', 'CON_Categoria', 'CON_Unidad', 'CCO_Categoria', 'UME_Unidad',
            'CON_Stock_Minimo', 'CON_Costo_Unitario', 'CON_Moneda', 'CON_Activo', 'SED_Sede'],
        'INV_DEPARTAMENTO' => null, 'INV_DESTINO_BAJA' => null, 'INV_DETALLE_MANT' => null, 'INV_EDIFICIO' => null,
        'INV_ESTADO' => null, 'INV_ESTADO_OPERATIVO' => null, 'INV_FORMA_CONTEO' => null, 'INV_KIT_ESTANDAR' => null,
        'INV_LICENCIA' => ['LIC_Clave_Activacion', 'LIC_Fecha_Vencimiento', 'LIC_Cantidad_Total', 'LIC_Costo', 'LIC_Moneda',
            'MCO_Modelo_Comercial', 'MAC_Metodo_Activacion', 'FCO_Forma_Conteo', 'LIC_Proveedor', 'LIC_Fecha_Inicio',
            'LIC_Renovacion_Automatica', 'LIC_Notas', 'SOF_Software', 'TLI_Tipo_Licencia'],
        'INV_LICENCIA_CLAVE' => null, 'INV_MARCA' => null, 'INV_METODO_ACTIVACION' => null, 'INV_MODELO' => null,
        'INV_MODELO_COMERCIAL' => null, 'INV_MOTIVO_BAJA' => null, 'INV_MOTIVO_MOVIMIENTO' => null, 'INV_MUNICIPIO' => null,
        'INV_NIVEL' => null, 'INV_PAIS' => null,
        'INV_PERSONA' => ['PER_Primer_Nombre', 'PER_Segundo_Nombre', 'PER_Primer_Apellido', 'PER_Segundo_Apellido',
            'PER_Email_Corporativo', 'PER_Telefono', 'PER_Estado', 'PER_Jefe', 'PER_AD_GUID', 'PER_AD_DN', 'DEP_Departamento',
            'CAR_Cargo', 'SED_Sede'],
        'INV_PROVEEDOR' => null, 'INV_SEDE' => null, 'INV_SOFTWARE' => null, 'INV_TIPO_ACTIVO' => null, 'INV_TIPO_AREA' => null,
        'INV_TIPO_CONEXION' => null, 'INV_TIPO_DOCUMENTO' => null, 'INV_TIPO_ESPECIFICACION' => null, 'INV_TIPO_EVIDENCIA' => null,
        'INV_TIPO_FALLA' => null, 'INV_TIPO_LICENCIA' => null, 'INV_TIPO_MANTENIMIENTO' => null,
        'INV_UNIDAD_MEDIDA' => null,
        'INV_USUARIO' => ['USU_Username', 'USU_Rol', 'USU_Estado', 'USU_SSO_Habilitado', 'USU_SSO_Provider', 'USU_Alcance_Global',
            'PER_Persona', 'USU_MFA_Exento_Hasta', 'USU_MFA_Exento_Motivo', 'USU_Bloqueo_Admin_Motivo', 'USU_Bloqueo_Admin_Hasta'],
    ];

    /** @var array{0: string, 1: string, 2: int|string, 3: int}|null table, key column, key, expected version */
    private ?array $pending = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @throws RowChangedByAnotherUser */
    public function expect(string $table, string $key, int|string $id, int $version): void
    {
        self::compare($table, $version, $this->read($table, $key, $id, false));
        $this->pending = [$table, $key, $id, $version];
    }

    /** @throws RowChangedByAnotherUser */
    public function lockPending(): void
    {
        if (null === $this->pending) {
            return;
        }
        [$table, $key, $id, $version] = $this->pending;
        // Only the first transaction is the edit: later ones (notifications, follow-ups) are not judged.
        $this->pending = null;
        self::compare($table, $version, $this->read($table, $key, $id, true));
    }

    public function reset(): void
    {
        $this->pending = null;
    }

    private function read(string $table, string $key, int|string $id, bool $lock): ?int
    {
        $version = $this->connection->fetchOne(
            'SELECT "'.self::COLUMN.'" FROM "'.$table.'" WHERE "'.$key.'" = :id'.($lock ? ' FOR UPDATE' : ''),
            ['id' => $id],
        );

        return false === $version || null === $version ? null : (int) $version;
    }

    private static function compare(string $table, int $expected, ?int $current): void
    {
        if (null !== $current && $current !== $expected) {
            throw new RowChangedByAnotherUser($table, $expected, $current);
        }
    }
}
