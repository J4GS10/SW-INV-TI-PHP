<?php

declare(strict_types=1);

namespace App\Repositorio;

use App\Db\InsercionOracle;
use App\Dominio\Reloj;
use App\Inventario\Puertos\SolicitudesDatos;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Approval requests in Oracle: all their SQL lives here. */
final readonly class SolicitudesOracle implements SolicitudesDatos
{
    private const string COLUMNAS = 'a."APR_Aprobacion", a."APR_Tipo", a."APR_Estado", a."APR_Payload", a."APR_Resumen", a."APR_Motivo", a."ACT_Activo", '
        .'a."CON_Consumible", a."SED_Sede", a."APR_Solicitante", a."APR_Fecha_Solicitud", a."APR_Aprobador", a."APR_Fecha_Resolucion", a."APR_Comentario"';

    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    public function crear(array $datos, array $payload): int
    {
        // RETURNING no admite el CLOB: se inserta con un payload vacío y se escribe después.
        // (La solicitud siempre es visible para quien la crea: el VPD incluye las propias.)
        $id = (int) InsercionOracle::insertar($this->conexion, 'INV_APROBACION', [...$datos, 'APR_Payload' => '{}'], 'APR_Aprobacion');
        $this->conexion->executeStatement('UPDATE "INV_APROBACION" SET "APR_Payload" = :p WHERE "APR_Aprobacion" = :id',
            ['p' => json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), 'id' => $id], ['p' => ParameterType::LARGE_OBJECT]);

        return $id;
    }

    public function pendienteDe(string $tipo, ?int $consumible = null): ?int
    {
        $sql = "SELECT \"APR_Aprobacion\" FROM \"INV_APROBACION\" WHERE \"APR_Tipo\" = :t AND \"APR_Estado\" = 'PENDIENTE'";
        $parametros = ['t' => $tipo];
        if (null !== $consumible) {
            $sql .= ' AND "CON_Consumible" = :c';
            $parametros['c'] = $consumible;
        }
        $id = $this->conexion->fetchOne($sql.' FETCH FIRST 1 ROWS ONLY', $parametros);

        return false === $id ? null : (int) $id;
    }

    public function bajaPendienteDe(string $activo): ?int
    {
        $id = $this->conexion->fetchOne("SELECT \"APR_Aprobacion\" FROM \"INV_APROBACION\" WHERE \"ACT_Activo\" = :a AND \"APR_Tipo\" = 'BAJA' "
            ."AND \"APR_Estado\" = 'PENDIENTE' FETCH FIRST 1 ROWS ONLY", ['a' => $activo]);

        return false === $id ? null : (int) $id;
    }

    public function consumibleParaAjustar(int $consumible): ?array
    {
        [$alcance, $parametros] = $this->alcance->lectura('INV_CONSUMIBLE', 'c');
        $fila = $this->conexion->fetchAssociative('SELECT c."CON_Nombre", c."CON_Stock_Actual", c."CON_Unidad", c."SED_Sede" FROM "INV_CONSUMIBLE" c '
            .'WHERE c."CON_Consumible" = :id AND '.$alcance.' FOR UPDATE', [...$parametros, 'id' => $consumible]);
        if (false === $fila) {
            return null;
        }

        return ['CON_Nombre' => (string) $fila['CON_Nombre'], 'CON_Stock_Actual' => (int) $fila['CON_Stock_Actual'],
            'CON_Unidad' => null === $fila['CON_Unidad'] ? null : (string) $fila['CON_Unidad'], 'SED_Sede' => null === $fila['SED_Sede'] ? null : (int) $fila['SED_Sede']];
    }

    public function bloquearTipo(int $tipo): void
    {
        $this->conexion->fetchOne('SELECT 1 FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t FOR UPDATE', ['t' => $tipo]);
    }

    public function porId(int $id, bool $bloquear = false): ?array
    {
        $fila = $this->conexion->fetchAssociative('SELECT '.self::COLUMNAS.' FROM "INV_APROBACION" a WHERE a."APR_Aprobacion" = :id'.($bloquear ? ' FOR UPDATE' : ''), ['id' => $id]);

        return false === $fila ? null : $fila;
    }

    public function visibles(?string $estado, ?string $activo, int $limite): array
    {
        [$sql, $parametros] = $this->alcance();
        if (null !== $estado && '' !== $estado) {
            $sql .= ' AND a."APR_Estado" = :estado';
            $parametros['estado'] = $estado;
        }
        if (null !== $activo) {
            $sql .= ' AND a."ACT_Activo" = :activo';
            $parametros['activo'] = $activo;
        }

        return $this->conexion->fetchAllAssociative('SELECT '.self::COLUMNAS.' FROM "INV_APROBACION" a WHERE '.$sql
            .' ORDER BY a."APR_Fecha_Solicitud" DESC, a."APR_Aprobacion" DESC FETCH FIRST '.max(1, $limite).' ROWS ONLY', $parametros);
    }

    public function pendientesVisibles(): int
    {
        [$sql, $parametros] = $this->alcance();

        return (int) $this->conexion->fetchOne("SELECT COUNT(*) FROM \"INV_APROBACION\" a WHERE \"APR_Estado\" = 'PENDIENTE' AND ".$sql, $parametros);
    }

    public function resolver(int $id, string $estado, ?string $aprobador, \DateTimeImmutable $cuando, ?string $comentario): void
    {
        $this->conexion->executeStatement('UPDATE "INV_APROBACION" SET "APR_Estado" = :e, "APR_Aprobador" = :ap, "APR_Fecha_Resolucion" = :f, "APR_Comentario" = :c '
            .'WHERE "APR_Aprobacion" = :id', ['e' => $estado, 'ap' => $aprobador, 'f' => Reloj::aBd($cuando), 'c' => $comentario, 'id' => $id]);
    }

    /**
     * Las de las sedes del alcance y las propias (igual que el VPD de INV_APROBACION).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function alcance(): array
    {
        if (null === $this->alcance->sedesVisibles()) {
            return ['1 = 1', []];
        }
        [$filtro, $parametros] = $this->alcance->filtroSede('a."SED_Sede"', 'asede');

        return ["({$filtro} OR a.\"APR_Solicitante\" = :propio)", [...$parametros, 'propio' => $this->alcance->actual()?->usuarioId]];
    }
}
