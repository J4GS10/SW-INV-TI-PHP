<?php

declare(strict_types=1);

namespace App\Repositorio;

use App\Dominio\Reloj;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * Consulta de la bitácora forense (INV_AUDITORIA_SISTEMA) filtrada por el alcance
 * del lector (capa 1; el VPD es la capa 2). La usan la pantalla de auditoría, su
 * resumen y la exportación CSV.
 */
final readonly class BitacoraRepositorio
{
    private const string COLUMNAS = 'a."AUD_Auditoria", a."AUD_Fecha_Hora", a."AUD_Accion", a."AUD_Entidad_Afectada", a."AUD_Snapshot_JSON", '
        .'a."AUD_IP_Origen", a."AUD_User_Agent", a."USU_Usuario", a."AUD_Sede", u."USU_Username"';
    public const string POR_ACCION = 'AUD_Accion';
    public const string POR_ENTIDAD = 'AUD_Entidad_Afectada';
    private const array AGRUPABLES = [self::POR_ACCION, self::POR_ENTIDAD];

    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /**
     * Condición SQL (alias `a`) y parámetros. Los criterios nulos no filtran.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function filtro(?\DateTimeImmutable $desde = null, ?\DateTimeImmutable $hasta = null, ?string $accion = null,
        ?string $entidad = null, ?string $usuarioHex = null): array
    {
        [$sql, $p] = $this->alcance->lectura('INV_AUDITORIA_SISTEMA', 'a');
        foreach ([
            'desde' => [null === $desde ? null : Reloj::aBd($desde), 'a."AUD_Fecha_Hora" >= :desde'],
            'hasta' => [null === $hasta ? null : Reloj::aBd($hasta), 'a."AUD_Fecha_Hora" <= :hasta'],
            'accion' => [$accion, 'a."AUD_Accion" = :accion'],
            'entidad' => [$entidad, 'a."AUD_Entidad_Afectada" = :entidad'],
            'usuario' => [$usuarioHex, 'a."USU_Usuario" = :usuario'],
        ] as $clave => [$valor, $condicion]) {
            if (null !== $valor && '' !== $valor) {
                $sql .= ' AND '.$condicion;
                $p[$clave] = $valor;
            }
        }

        return [$sql, $p];
    }

    /** @param array{0: string, 1: array<string, mixed>} $filtro */
    public function contar(array $filtro): int
    {
        return (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" a WHERE '.$filtro[0], $filtro[1]);
    }

    /**
     * Eventos del más reciente al más antiguo, con el nombre de usuario.
     *
     * @param array{0: string, 1: array<string, mixed>} $filtro
     *
     * @return list<array<string, mixed>>
     */
    public function eventos(array $filtro, int $saltar, int $limite): array
    {
        return $this->conexion->fetchAllAssociative('SELECT '.self::COLUMNAS.' FROM "INV_AUDITORIA_SISTEMA" a '
            .'LEFT JOIN "INV_USUARIO" u ON u."USU_Usuario" = a."USU_Usuario" WHERE '.$filtro[0]
            .' ORDER BY a."AUD_Fecha_Hora" DESC OFFSET :saltar ROWS FETCH NEXT :limite ROWS ONLY', [...$filtro[1], 'saltar' => $saltar, 'limite' => $limite]);
    }

    /**
     * Conteo por columna (acción o entidad) para los tableros forenses.
     *
     * @param array{0: string, 1: array<string, mixed>} $filtro
     *
     * @return array<string, int>
     */
    public function contarPor(string $columna, array $filtro): array
    {
        if (!\in_array($columna, self::AGRUPABLES, true)) {
            throw new \InvalidArgumentException("Columna no agrupable: {$columna}");
        }

        return array_map('intval', $this->conexion->fetchAllKeyValue(
            "SELECT a.\"{$columna}\", COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" a WHERE {$filtro[0]} GROUP BY a.\"{$columna}\"", $filtro[1]));
    }
}
