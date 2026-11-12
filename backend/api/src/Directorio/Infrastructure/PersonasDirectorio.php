<?php

declare(strict_types=1);

namespace App\Directorio\Infrastructure;

use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/** People and their accounts as the directory synchronization reads and writes them: all that SQL lives here. */
final readonly class PersonasDirectorio
{
    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /** @return mixed the JSON summary of the last synchronization recorded in the audit log; false or null when there is none */
    public function ultimaSincronizacion(string $accion): mixed
    {
        return $this->conexion->fetchOne(
            'SELECT "AUD_Snapshot_JSON" FROM "INV_AUDITORIA_SISTEMA" WHERE "AUD_Accion" = :a ORDER BY "AUD_Fecha_Hora" DESC FETCH FIRST 1 ROWS ONLY',
            ['a' => $accion],
        );
    }

    /** @return array<string, array<string, mixed>> person id => its row, for the people the current user may see */
    public function personasVisibles(): array
    {
        [$filtro, $parametros] = $this->alcance->lectura('INV_PERSONA', 'p');
        $personas = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT p.* FROM "INV_PERSONA" p WHERE '.$filtro, $parametros) as $fila) {
            $personas[(string) $fila['PER_Persona']] = $fila;
        }

        return $personas;
    }

    /** Departments and positions by name, created on demand, for one synchronization run. */
    public function catalogos(): CatalogosPersona
    {
        return new CatalogosPersona($this->conexion);
    }

    public function reactivar(string $persona): void
    {
        $this->conexion->executeStatement('UPDATE "INV_PERSONA" SET "PER_Estado" = 1 WHERE "PER_Persona" = :id', ['id' => $persona]);
    }

    public function fijarJefe(string $persona, ?string $jefe): void
    {
        $this->conexion->executeStatement('UPDATE "INV_PERSONA" SET "PER_Jefe" = :jefe WHERE "PER_Persona" = :id', ['jefe' => $jefe, 'id' => $persona]);
    }

    public function superAdministradoresActivos(): int
    {
        return (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_USUARIO" WHERE "USU_Rol" = :r AND "USU_Estado" = 1', ['r' => Roles::SUPER_ADMIN]);
    }

    /** Custodies the person still holds, in every site: whoever synchronizes may not see the business data. */
    public function custodiasAbiertas(string $persona): int
    {
        return (int) $this->alcance->elevado(fn (): mixed => $this->conexion->fetchOne(
            'SELECT COUNT(*) FROM "INV_MOVIMIENTO" WHERE "PER_Persona" = :p AND "MOV_Fecha_Devolucion" IS NULL', ['p' => $persona]));
    }

    /** @return list<array<string, mixed>> USU_Usuario and USU_Rol of the active accounts of a person */
    public function cuentasActivas(string $persona): array
    {
        return $this->conexion->fetchAllAssociative('SELECT "USU_Usuario", "USU_Rol" FROM "INV_USUARIO" WHERE "PER_Persona" = :p AND "USU_Estado" = 1', ['p' => $persona]);
    }

    /** Deactivates the person and, without the scope filter (protected identity column), every account of theirs. */
    public function desactivar(string $persona, \DateTimeImmutable $ahora): void
    {
        $this->conexion->executeStatement('UPDATE "INV_PERSONA" SET "PER_Estado" = 0, "UPDATED_AT" = :ahora WHERE "PER_Persona" = :id',
            ['ahora' => Reloj::aBd($ahora), 'id' => $persona]);
        $this->alcance->elevado(fn (): int => (int) $this->conexion->executeStatement(
            'UPDATE "INV_USUARIO" SET "USU_Estado" = 0 WHERE "PER_Persona" = :p', ['p' => $persona]));
    }

    /** @param array<string, mixed> $fila column => value */
    public function insertar(array $fila): void
    {
        $columnas = array_keys($fila);
        $this->conexion->executeStatement('INSERT INTO "INV_PERSONA" ("'.implode('", "', $columnas).'") VALUES (:'.implode(', :', $columnas).')', $fila);
    }

    /** @param array<string, mixed> $valores column => value; also stamps the synchronization time */
    public function actualizar(string $persona, array $valores, \DateTimeImmutable $ahora): void
    {
        $sets = implode(', ', array_map(static fn (string $c): string => "\"{$c}\" = :{$c}", array_keys($valores)));
        $this->conexion->executeStatement(
            "UPDATE \"INV_PERSONA\" SET {$sets}, \"PER_AD_Sincronizado_En\" = :sincronizado, \"UPDATED_AT\" = :sincronizado WHERE \"PER_Persona\" = :id",
            [...$valores, 'sincronizado' => Reloj::aBd($ahora), 'id' => $persona],
        );
    }

    // A synchronization can be a rehearsal: everything is applied and then discarded, so it manages its own transaction.

    public function abrirTransaccion(): void
    {
        $this->conexion->beginTransaction();
    }

    public function confirmar(): void
    {
        $this->conexion->commit();
    }

    /** Discards what was applied, if the transaction is still open. */
    public function descartar(): void
    {
        if ($this->conexion->isTransactionActive()) {
            $this->conexion->rollBack();
        }
    }
}
