<?php

declare(strict_types=1);

namespace App\Adjuntos\Infrastructure;

use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/** Attachment records (INV_ADJUNTO; the binaries are in the object store), within the sites the current user may see. */
final readonly class AdjuntosStore
{
    private const string COLUMNAS = 'j."ADJ_Adjunto", j."ADJ_Nombre_Original", j."ADJ_Nombre_Almacenado", j."ADJ_Tipo_MIME", j."ADJ_Tamano_Bytes", '
        .'j."ADJ_Storage_Backend", j."ADJ_Bucket", j."ADJ_Object_Key", j."ADJ_Checksum_SHA256", j."ADJ_Categoria", j."ADJ_Descripcion", '
        .'j."ADJ_Fecha_Subida", j."ACT_Activo", j."LIC_Licencia", j."GRC_Goods_Receipt", j."USU_Usuario"';

    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /** @return list<array<string, mixed>> the attachments of an owner (the column says which kind), newest first */
    public function deDueno(string $columnaDelDueno, mixed $dueno): array
    {
        [$alcance, $parametros] = $this->alcance->lectura('INV_ADJUNTO', 'j');

        return $this->conexion->fetchAllAssociative('SELECT '.self::COLUMNAS.' FROM "INV_ADJUNTO" j WHERE j."'
            .$columnaDelDueno.'" = :dueno AND '.$alcance.' ORDER BY j."ADJ_Fecha_Subida" DESC', [...$parametros, 'dueno' => $dueno]);
    }

    /** @return array<string, mixed>|null */
    public function porId(string $id): ?array
    {
        [$alcance, $parametros] = $this->alcance->lectura('INV_ADJUNTO', 'j');
        $fila = $this->conexion->fetchAssociative('SELECT '.self::COLUMNAS.' FROM "INV_ADJUNTO" j WHERE j."ADJ_Adjunto" = :id AND '.$alcance, [...$parametros, 'id' => $id]);

        return false === $fila ? null : $fila;
    }

    /** @param array<string, mixed> $columnas column name => value */
    public function insertar(array $columnas): void
    {
        $citadas = [];
        foreach ($columnas as $columna => $valor) {
            $citadas['"'.$columna.'"'] = $valor;
        }
        $this->conexion->insert('"INV_ADJUNTO"', $citadas);
    }

    public function existeLicencia(mixed $licencia): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_LICENCIA" WHERE "LIC_Licencia" = :id', ['id' => $licencia]);
    }

    /** @return list<mixed> the codes of the evidence types in use */
    public function categoriasValidas(): array
    {
        return $this->conexion->fetchFirstColumn('SELECT "TEV_Codigo" FROM "INV_TIPO_EVIDENCIA" WHERE "TEV_Activo" = 1 AND "TEV_Codigo" IS NOT NULL');
    }
}
