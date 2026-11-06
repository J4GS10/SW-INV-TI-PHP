<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Dominio\Reloj;
use App\Inventario\EstadosActivo;
use App\Inventario\Kardex;
use App\Seguridad\Alcance;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Read-only views for purchasing and accounting: stock per product, entries and who holds each asset. */
final readonly class PurchasingReports
{
    private const int MAX_ROWS = 500;

    public function __construct(private Connection $connection, private Alcance $scope, private Kardex $kardex)
    {
    }

    /** @return array{assets: list<array<string, mixed>>, consumables: list<array<string, mixed>>} */
    public function stock(?int $site): array
    {
        return ['assets' => $this->assetStock($site), 'consumables' => $this->consumableStock($site)];
    }

    /** @return list<array<string, mixed>> */
    public function entries(?string $from, ?string $to, ?int $site): array
    {
        [$where, $params] = $this->kardex->filtros(['categoria' => 'ENTRADAS', 'desde' => $from, 'hasta' => $to, 'sede' => $site]);

        return array_map(static fn (array $row): array => [
            'date' => self::timestamp($row['KDX_Fecha']),
            'type' => $row['KDX_Tipo'],
            'item' => $row['KDX_Referencia'],
            'quantity' => null === $row['KDX_Cantidad'] ? null : (int) $row['KDX_Cantidad'],
            'unit_cost' => null === $row['KDX_Costo'] ? null : (float) $row['KDX_Costo'],
            'currency' => $row['KDX_Moneda'],
            'document' => $row['KDX_Documento_Ref'],
        ], $this->connection->fetchAllAssociative(
            'SELECT k."KDX_Fecha", k."KDX_Tipo", k."KDX_Referencia", k."KDX_Cantidad", k."KDX_Costo", k."KDX_Moneda", k."KDX_Documento_Ref" '
            .'FROM "INV_KARDEX" k WHERE '.$where.' ORDER BY k."KDX_Fecha" DESC FETCH FIRST '.self::MAX_ROWS.' ROWS ONLY',
            $params,
        ));
    }

    /** @return list<array<string, mixed>> */
    public function assignments(?string $from, ?string $to, ?int $site): array
    {
        [$where, $params] = $this->scope->lectura('INV_MOVIMIENTO', 'm');
        $filters = '';
        if (null !== $from) {
            $filters .= ' AND m."MOV_Fecha_Asignacion" >= :from_date';
            $params['from_date'] = "{$from} 00:00:00.000000";
        }
        if (null !== $to) {
            $filters .= ' AND m."MOV_Fecha_Asignacion" <= :to_date';
            $params['to_date'] = "{$to} 23:59:59.999999";
        }
        if (null !== $site) {
            $filters .= ' AND a."SED_Sede" = :site';
            $params['site'] = $this->scope->exigirSede($site);
        }

        return array_map(static fn (array $row): array => [
            'assigned_at' => self::timestamp($row['MOV_Fecha_Asignacion']),
            'returned_at' => self::timestamp($row['MOV_Fecha_Devolucion']),
            'asset_code' => $row['ACT_Codigo_Interno'],
            'product' => trim($row['MAR_Nombre'].' '.$row['MOD_Nombre']),
            'person' => trim($row['PER_Primer_Nombre'].' '.$row['PER_Primer_Apellido']),
            'area' => $row['ARE_Nombre'],
        ], $this->connection->fetchAllAssociative(
            'SELECT m."MOV_Fecha_Asignacion", m."MOV_Fecha_Devolucion", a."ACT_Codigo_Interno", ma."MAR_Nombre", mo."MOD_Nombre", '
            .'p."PER_Primer_Nombre", p."PER_Primer_Apellido", ar."ARE_Nombre" FROM "INV_MOVIMIENTO" m '
            .'JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" '
            .'LEFT JOIN "INV_MODELO" mo ON mo."MOD_Modelo" = a."MOD_Modelo" LEFT JOIN "INV_MARCA" ma ON ma."MAR_Marca" = mo."MAR_Marca" '
            .'LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = m."PER_Persona" LEFT JOIN "INV_AREA" ar ON ar."ARE_Area" = m."ARE_Area" '
            .'WHERE '.$where.$filters.' ORDER BY m."MOV_Fecha_Asignacion" DESC FETCH FIRST '.self::MAX_ROWS.' ROWS ONLY',
            $params,
        ));
    }

    /** @return list<array<string, mixed>> */
    private function assetStock(?int $site): array
    {
        [$where, $params] = $this->scope->lectura('INV_ACTIVO', 'a');
        if (null !== $site) {
            $where .= ' AND a."SED_Sede" = :site';
            $params['site'] = $this->scope->exigirSede($site);
        }
        $params['warehouse'] = EstadosActivo::DE_ALMACEN;

        return array_map(static fn (array $row): array => [
            'model_id' => null === $row['MODEL_ID'] ? null : (int) $row['MODEL_ID'],
            'type' => $row['TYPE_NAME'],
            'brand' => $row['BRAND_NAME'],
            'model' => $row['MODEL_NAME'],
            'total' => (int) $row['TOTAL'],
            'assigned' => (int) $row['ASSIGNED'],
            'available' => (int) $row['AVAILABLE'],
            'last_entry_at' => $row['LAST_ENTRY_AT'],
        ], $this->connection->fetchAllAssociative(
            'SELECT mo."MOD_Modelo" AS "MODEL_ID", t."TAC_Nombre" AS "TYPE_NAME", ma."MAR_Nombre" AS "BRAND_NAME", mo."MOD_Nombre" AS "MODEL_NAME", '
            .'COUNT(*) AS "TOTAL", SUM(CASE WHEN e."EOP_Nombre" = \'Asignado\' THEN 1 ELSE 0 END) AS "ASSIGNED", '
            .'SUM(CASE WHEN e."EOP_Nombre" IN (:warehouse) THEN 1 ELSE 0 END) AS "AVAILABLE", '
            .'TO_CHAR(MAX(a."ACT_Fecha_Compra"), \'YYYY-MM-DD\') AS "LAST_ENTRY_AT" FROM "INV_ACTIVO" a '
            .'JOIN "INV_ESTADO_OPERATIVO" e ON e."EOP_Estado_Operativo" = a."EOP_Estado_Operativo" '
            .'LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" '
            .'LEFT JOIN "INV_MODELO" mo ON mo."MOD_Modelo" = a."MOD_Modelo" LEFT JOIN "INV_MARCA" ma ON ma."MAR_Marca" = mo."MAR_Marca" '
            .'WHERE e."EOP_Nombre" <> \'Baja\' AND '.$where
            .' GROUP BY mo."MOD_Modelo", t."TAC_Nombre", ma."MAR_Nombre", mo."MOD_Nombre" ORDER BY t."TAC_Nombre", ma."MAR_Nombre", mo."MOD_Nombre"',
            $params,
            ['warehouse' => ArrayParameterType::STRING],
        ));
    }

    /** @return list<array<string, mixed>> */
    private function consumableStock(?int $site): array
    {
        [$where, $params] = $this->scope->lectura('INV_CONSUMIBLE', 'c');
        if (null !== $site) {
            $where .= ' AND c."SED_Sede" = :site';
            $params['site'] = $this->scope->exigirSede($site);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['CON_Consumible'],
            'name' => $row['CON_Nombre'],
            'stock' => (int) $row['CON_Stock_Actual'],
            'minimum' => (int) $row['CON_Stock_Minimo'],
            'unit_cost' => null === $row['CON_Costo_Unitario'] ? null : (float) $row['CON_Costo_Unitario'],
            'currency' => $row['CON_Moneda'],
            'last_entry_at' => self::timestamp($row['LAST_ENTRY_AT']),
        ], $this->connection->fetchAllAssociative(
            'SELECT c."CON_Consumible", c."CON_Nombre", c."CON_Stock_Actual", c."CON_Stock_Minimo", c."CON_Costo_Unitario", c."CON_Moneda", '
            .'(SELECT MAX(k."KDX_Fecha") FROM "INV_KARDEX" k WHERE k."CON_Consumible" = c."CON_Consumible" AND k."KDX_Tipo" = \'CONSUMIBLE_ENTRADA\') AS "LAST_ENTRY_AT" '
            .'FROM "INV_CONSUMIBLE" c WHERE c."CON_Activo" = 1 AND '.$where.' ORDER BY c."CON_Nombre"',
            $params,
        ));
    }

    private static function timestamp(mixed $value): ?string
    {
        $date = null === $value ? null : Reloj::desdeBd((string) $value);

        return null === $date ? null : Reloj::iso($date);
    }
}
