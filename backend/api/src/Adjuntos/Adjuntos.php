<?php

declare(strict_types=1);

namespace App\Adjuntos;

use App\Config\Ajustes;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Http\ErrorApi;
use App\Inventario\Activos;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Adjuntos de activos, ingresos y licencias (factura, foto, acta firmada…).
 *
 * - Extensión en lista blanca, tamaño y firma del contenido se validan ANTES de escribir.
 * - El nombre almacenado sale de un UUID, nunca del nombre del cliente.
 * - El binario se escribe antes que la fila: si la fila o la auditoría fallan, el
 *   binario se borra (compensación). Al eliminar, el binario se borra después del commit.
 */
final readonly class Adjuntos
{
    private const string COLUMNAS = 'j."ADJ_Adjunto", j."ADJ_Nombre_Original", j."ADJ_Nombre_Almacenado", j."ADJ_Tipo_MIME", j."ADJ_Tamano_Bytes", '
        .'j."ADJ_Storage_Backend", j."ADJ_Bucket", j."ADJ_Object_Key", j."ADJ_Checksum_SHA256", j."ADJ_Categoria", j."ADJ_Descripcion", '
        .'j."ADJ_Fecha_Subida", j."ACT_Activo", j."LIC_Licencia", j."GRC_Goods_Receipt", j."USU_Usuario"';

    public function __construct(
        private Connection $conexion,
        private Alcance $alcance,
        private Almacenes $almacenes,
        private Activos $activos,
        private \App\GoodsReceipt\Infrastructure\GoodsReceiptRepository $goodsReceipts,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
        private Ajustes $ajustes,
        private LoggerInterface $logger,
    ) {
    }

    /** @return list<array<string, mixed>> AdjuntoResponse, del más reciente al más antiguo */
    public function listar(Dueno $dueno): array
    {
        $this->exigirDueno($dueno);
        [$alcance, $p] = $this->alcance->lectura('INV_ADJUNTO', 'j');

        return array_map(self::aApi(...), $this->conexion->fetchAllAssociative('SELECT '.self::COLUMNAS.' FROM "INV_ADJUNTO" j WHERE j."'
            .$dueno->tipo->columna().'" = :dueno AND '.$alcance.' ORDER BY j."ADJ_Fecha_Subida" DESC', [...$p, 'dueno' => $dueno->id]));
    }

    /** @return array<string, mixed> AdjuntoResponse */
    public function subir(Dueno $dueno, Subida $subida, ?string $categoria, ?string $descripcion, string $usuario, ?string $ip): array
    {
        $this->exigirDueno($dueno);
        $categoria = $this->categoriaValida($categoria ?? $dueno->tipo->categoriaPorDefecto());
        $original = TiposArchivo::sanearNombre($subida->nombre);
        $extension = TiposArchivo::extension($original);
        if (!\in_array($extension, $this->ajustes->extensionesPermitidas, true)) {
            throw new ErrorApi(400, 'FILE_TYPE_NOT_ALLOWED');
        }
        $contenido = $subida->contenido;
        if (\strlen($contenido) > $this->ajustes->adjuntoMaxMb * 1024 * 1024) {
            throw new ErrorApi(413, 'FILE_TOO_LARGE_MAX_MB:'.$this->ajustes->adjuntoMaxMb);
        }
        if ('' === $contenido) {
            throw new ErrorApi(400, 'EMPTY_FILE');
        }
        // Un .exe renombrado a .pdf o un HTML guardado como .jpg se rechazan; el MIME lo decide el servidor.
        if (!TiposArchivo::contenidoCoincide($extension, $contenido)) {
            throw new ErrorApi(400, 'FILE_CONTENT_MISMATCH');
        }
        $mime = TiposArchivo::mime($extension);
        $almacenado = bin2hex(random_bytes(16)).$extension;
        $almacen = $this->almacenes->actual();
        try {
            $objeto = $almacen->guardar("{$dueno->tipo->value}/{$dueno->idEnRuta()}/{$almacenado}", $contenido, $mime);
        } catch (AlmacenNoDisponible $e) {
            throw new ErrorApi(503, $e->getMessage());
        }

        try {
            $id = $this->transaccion->ejecutar(function () use ($dueno, $objeto, $original, $almacenado, $mime, $categoria, $descripcion, $usuario, $ip): string {
                $id = Ident::nuevo();
                $this->conexion->insert('"INV_ADJUNTO"', [
                    '"ADJ_Adjunto"' => $id, '"ADJ_Nombre_Original"' => $original, '"ADJ_Nombre_Almacenado"' => $almacenado,
                    '"ADJ_Storage_Backend"' => $objeto->backend, '"ADJ_Bucket"' => $objeto->bucket, '"ADJ_Object_Key"' => $objeto->clave,
                    '"ADJ_Checksum_SHA256"' => $objeto->sha256, '"ADJ_Tipo_MIME"' => $mime, '"ADJ_Tamano_Bytes"' => $objeto->tamano,
                    '"ADJ_Categoria"' => $categoria, '"ADJ_Descripcion"' => $descripcion, '"ADJ_Fecha_Subida"' => Reloj::aBd(Reloj::ahora()),
                    '"USU_Usuario"' => $usuario, '"'.$dueno->tipo->columna().'"' => $dueno->id,
                ]);
                $this->gobierno->registrarAuditoria('CREATE', 'INV_ADJUNTO', ['dueno' => $dueno->tipo->value.':'.$dueno->idEnRuta(),
                    'nombre' => mb_substr($original, 0, 255), 'categoria' => $categoria, 'tamano' => $objeto->tamano], $usuario, $ip);

                return $id;
            });
        } catch (\Throwable $e) {
            // El binario quedó escrito antes que la fila: se compensa para no dejar huérfanos.
            $this->borrarBinario($almacen, $objeto->clave, $objeto->bucket, 'storage.compensation_failed');
            throw $e;
        }

        return self::aApi($this->filaO404($id));
    }

    /**
     * Fila y contenido para descargar. El permiso es el del dueño; si el rol no lo
     * tiene, 404 (sin indicios de que el archivo existe).
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function descargar(string $id, string $rol): array
    {
        $fila = $this->filaO404($id);
        if (!\in_array($rol, TipoDueno::deFila($fila)->lectores(), true)) {
            throw new ErrorApi(404, 'ATTACHMENT_NOT_FOUND');
        }
        $backend = $fila['ADJ_Storage_Backend'] ?? AlmacenLocal::NOMBRE;
        try {
            $contenido = $this->almacenes->de($backend)->leer(self::clave($fila), $fila['ADJ_Bucket']);
        } catch (AlmacenNoDisponible $e) {
            throw AlmacenNoDisponible::ARCHIVO_FALTANTE === $e->getMessage() && AlmacenLocal::NOMBRE === $backend
                ? new ErrorApi(410, $e->getMessage())
                : new ErrorApi(503, $e->getMessage());
        }

        return [$fila, $contenido];
    }

    public function eliminar(string $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $fila = $this->filaO404($id);
            $this->conexion->executeStatement('DELETE FROM "INV_ADJUNTO" WHERE "ADJ_Adjunto" = :id', ['id' => $id]);
            $this->gobierno->registrarAuditoria('DELETE', 'INV_ADJUNTO', ['id' => Ident::aTexto($id), 'nombre' => $fila['ADJ_Nombre_Original']], $usuario, $ip);
            $almacen = $this->almacenes->de($fila['ADJ_Storage_Backend']);
            $clave = self::clave($fila);
            $bucket = $fila['ADJ_Bucket'];
            // Si el commit falla, el binario sigue ahí y la fila también.
            $this->transaccion->alConfirmar(fn () => $this->borrarBinario($almacen, $clave, $bucket, 'storage.delete_orphaned'));
        });
    }

    /** @return array<string, mixed> */
    private function filaO404(string $id): array
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ADJUNTO', 'j');
        $fila = $this->conexion->fetchAssociative('SELECT '.self::COLUMNAS.' FROM "INV_ADJUNTO" j WHERE j."ADJ_Adjunto" = :id AND '.$alcance, [...$p, 'id' => $id]);

        return false === $fila ? throw new ErrorApi(404, 'ATTACHMENT_NOT_FOUND') : $fila;
    }

    private function exigirDueno(Dueno $dueno): void
    {
        match ($dueno->tipo) {
            TipoDueno::Activo => $this->activos->filaO404((string) $dueno->id),
            TipoDueno::Ingreso => null !== $this->goodsReceipts->find((int) $dueno->id) || throw new ErrorApi(404, 'GOODS_RECEIPT_NOT_FOUND'),
            TipoDueno::Licencia => false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_LICENCIA" WHERE "LIC_Licencia" = :id', ['id' => $dueno->id])
                || throw new ErrorApi(404, 'LICENSE_NOT_FOUND'),
        };
    }

    /** Código del catálogo de tipos de evidencia activos (los de sistema van en minúscula). */
    private function categoriaValida(string $categoria): string
    {
        $validos = $this->conexion->fetchFirstColumn('SELECT "TEV_Codigo" FROM "INV_TIPO_EVIDENCIA" WHERE "TEV_Activo" = 1 AND "TEV_Codigo" IS NOT NULL');
        $categoria = trim($categoria) ?: 'otro';
        if (!\in_array($categoria, $validos, true) && \in_array(strtolower($categoria), $validos, true)) {
            $categoria = strtolower($categoria);
        }

        return \in_array($categoria, $validos, true) ? $categoria : throw new ErrorApi(400, 'INVALID_ATTACHMENT_CATEGORY');
    }

    private function borrarBinario(Almacen $almacen, string $clave, ?string $bucket, string $evento): void
    {
        try {
            $almacen->borrar($clave, $bucket);
        } catch (AlmacenNoDisponible $e) {
            $this->logger->error($evento, ['object_key' => $clave, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Clave del objeto; los adjuntos antiguos no la guardaban y se deduce del dueño.
     *
     * @param array<string, mixed> $f
     */
    private static function clave(array $f): string
    {
        if (null !== $f['ADJ_Object_Key'] && '' !== $f['ADJ_Object_Key']) {
            return (string) $f['ADJ_Object_Key'];
        }
        $tipo = TipoDueno::deFila($f);
        $dueno = TipoDueno::Activo === $tipo ? Ident::aTexto((string) $f['ACT_Activo']) : (string) (int) $f[$tipo->columna()];

        return "{$tipo->value}/{$dueno}/{$f['ADJ_Nombre_Almacenado']}";
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed> AdjuntoResponse
     */
    private static function aApi(array $f): array
    {
        $entero = static fn (mixed $v): ?int => null === $v ? null : (int) $v;
        $uuid = static fn (mixed $v): ?string => null === $v ? null : Ident::aTexto((string) $v);

        return [
            'ADJ_Adjunto' => Ident::aTexto((string) $f['ADJ_Adjunto']),
            'ADJ_Nombre_Original' => $f['ADJ_Nombre_Original'],
            'ADJ_Tipo_MIME' => $f['ADJ_Tipo_MIME'],
            'ADJ_Tamano_Bytes' => (int) $f['ADJ_Tamano_Bytes'],
            'ADJ_Storage_Backend' => $f['ADJ_Storage_Backend'],
            'ADJ_Bucket' => $f['ADJ_Bucket'],
            'ADJ_Object_Key' => $f['ADJ_Object_Key'],
            'ADJ_Checksum_SHA256' => $f['ADJ_Checksum_SHA256'],
            'ADJ_Categoria' => $f['ADJ_Categoria'],
            'ADJ_Descripcion' => $f['ADJ_Descripcion'],
            'ADJ_Fecha_Subida' => Reloj::iso(Reloj::desdeBd((string) $f['ADJ_Fecha_Subida'])),
            'ACT_Activo' => $uuid($f['ACT_Activo']),
            'LIC_Licencia' => $entero($f['LIC_Licencia']),
            'USU_Usuario' => $uuid($f['USU_Usuario']),
        ];
    }
}
