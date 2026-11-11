<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Adjuntos\AlmacenNoDisponible;
use App\Adjuntos\AlmacenS3;
use App\Config\Ajustes;
use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;
use AsyncAws\S3\S3Client;

/**
 * /adjuntos (caja negra) y el almacenamiento (caja blanca): validación del
 * contenido, permisos heredados del dueño, alcance por sede, descarga segura,
 * borrado tras el commit y el backend S3 contra un MinIO real.
 */
final class AdjuntosTest extends PruebaApi
{
    private const string PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private int $sede;
    private string $activo;

    protected function setUp(): void
    {
        parent::setUp();
        I::estados();
        foreach (['factura', 'foto', 'acta', 'otro'] as $codigo) {
            if (0 === D::contar('SELECT COUNT(*) FROM "INV_TIPO_EVIDENCIA" WHERE "TEV_Codigo" = :c', ['c' => $codigo])) {
                D::conexion()->executeStatement('INSERT INTO "INV_TIPO_EVIDENCIA" ("TEV_Nombre", "TEV_Codigo", "TEV_Activo") VALUES (:n, :c, 1)',
                    ['n' => ucfirst($codigo), 'c' => $codigo]);
            }
        }
        $this->sede = D::crearSede('Sede J '.D::sufijo());
        $tipo = I::tipo('COMPUTO');
        $this->token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
        $this->activo = $this->ok(201, 'POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'AJ-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-10-01',
            'MOD_Modelo' => I::modelo($tipo), 'TAC_Tipo_Activo' => $tipo, 'EOP_Estado_Operativo' => I::estado('En Bodega'), 'SED_Sede' => $this->sede])['ACT_Activo'];
    }

    /**
     * @param array<string, string> $campos
     *
     * @return array{0: int, 1: mixed}
     */
    private function subir(string $ruta, string $nombre, string $contenido, array $campos = [], ?string $token = null): array
    {
        $r = $this->archivo('POST', $ruta, token: $token, campos: $campos, archivos: ['file' => [$nombre, $contenido]]);

        return [$r->getStatusCode(), json_decode((string) $r->getContent(), true)];
    }

    public function testCicloCompletoDeUnAdjuntoDelActivo(): void
    {
        $antes = D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Entidad_Afectada\" = 'INV_ADJUNTO'");
        [$estado, $adj] = $this->subir("/api/v1/adjuntos/activos/{$this->activo}", '../../factura «octubre».PDF', self::PDF, ['categoria' => 'FACTURA', 'descripcion' => 'Compra']);
        self::assertSame(201, $estado, json_encode($adj));
        self::assertSame(['factura «octubre».PDF', 'application/pdf', 'factura', 'local', \strlen(self::PDF), hash('sha256', self::PDF), $this->activo],
            [$adj['ADJ_Nombre_Original'], $adj['ADJ_Tipo_MIME'], $adj['ADJ_Categoria'], $adj['ADJ_Storage_Backend'], $adj['ADJ_Tamano_Bytes'],
                $adj['ADJ_Checksum_SHA256'], $adj['ACT_Activo']]);
        self::assertStringStartsWith("activos/{$this->activo}/", $adj['ADJ_Object_Key']);
        self::assertMatchesRegularExpression('/^activos\/[0-9a-f-]{36}\/[0-9a-f]{32}\.pdf$/', $adj['ADJ_Object_Key'], 'Nombre almacenado: UUID, nunca el del cliente.');

        $lista = $this->ok(200, 'GET', "/api/v1/adjuntos/activos/{$this->activo}");
        self::assertSame([$adj['ADJ_Adjunto']], array_column($lista, 'ADJ_Adjunto'));

        $d = $this->archivo('GET', "/api/v1/adjuntos/{$adj['ADJ_Adjunto']}/download");
        self::assertSame([200, self::PDF, 'application/pdf'], [$d->getStatusCode(), $d->getContent(), $d->headers->get('Content-Type')]);
        self::assertSame("attachment; filename=\"factura octubre.PDF\"; filename*=UTF-8''factura%20%C2%ABoctubre%C2%BB.PDF", $d->headers->get('Content-Disposition'));
        self::assertSame(['nosniff', "sandbox; default-src 'none'"], [$d->headers->get('X-Content-Type-Options'), $d->headers->get('Content-Security-Policy')]);

        $archivo = 'var/adjuntos_pruebas/'.$adj['ADJ_Object_Key'];
        self::assertFileExists($archivo);
        self::assertSame(204, $this->archivo('DELETE', "/api/v1/adjuntos/{$adj['ADJ_Adjunto']}")->getStatusCode());
        // Borrado lógico: el adjunto se archiva y su binario se conserva (el respaldo y la auditoría lo pueden recuperar).
        self::assertFileExists($archivo, 'El binario se conserva: el borrado es lógico.');
        unlink($archivo);
        self::assertSame([404, 'ATTACHMENT_NOT_FOUND'], $this->detalle('GET', "/api/v1/adjuntos/{$adj['ADJ_Adjunto']}/download"));
        self::assertSame($antes + 2, D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Entidad_Afectada\" = 'INV_ADJUNTO'"), 'Alta y baja auditadas.');
    }

    public function testValidacionDelArchivoAntesDeEscribir(): void
    {
        $ruta = "/api/v1/adjuntos/activos/{$this->activo}";
        self::assertSame([400, 'FILE_CONTENT_MISMATCH'], [$this->subir($ruta, 'x.pdf', "MZ\x90\x00 ejecutable")[0], $this->subir($ruta, 'x.pdf', "MZ\x90\x00")[1]['detail']]);
        self::assertSame('FILE_CONTENT_MISMATCH', $this->subir($ruta, 'pagina.txt', '<html><script>alert(1)</script>')[1]['detail']);
        self::assertSame([400, 'FILE_TYPE_NOT_ALLOWED'], [$this->subir($ruta, 'virus.exe', 'MZ')[0], $this->subir($ruta, 'virus.exe', 'MZ')[1]['detail']]);
        self::assertSame('EMPTY_FILE', $this->subir($ruta, 'vacio.pdf', '')[1]['detail']);
        self::assertSame([413, 'FILE_TOO_LARGE_MAX_MB:10'], [$this->subir($ruta, 'grande.pdf', '%PDF-'.str_repeat('x', 10 * 1024 * 1024))[0],
            'FILE_TOO_LARGE_MAX_MB:10']);
        self::assertSame('INVALID_ATTACHMENT_CATEGORY', $this->subir($ruta, 'x.pdf', self::PDF, ['categoria' => 'inventada'])[1]['detail']);
        $r = $this->archivo('POST', $ruta, campos: ['categoria' => 'foto']);
        self::assertSame(422, $r->getStatusCode(), 'Sin el campo file.');
        self::assertSame(['body', 'file'], json_decode((string) $r->getContent(), true)['errors'][0]['loc']);
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "INV_ADJUNTO" WHERE "ACT_Activo" = :a', ['a' => str_replace('-', '', strtoupper($this->activo))]));
        self::assertSame([404, 'ASSET_NOT_FOUND'], [$this->subir('/api/v1/adjuntos/activos/00000000-0000-0000-0000-000000000000', 'x.pdf', self::PDF)[0], 'ASSET_NOT_FOUND']);
    }

    public function testPermisosHeredadosDelDuenoYAlcancePorSede(): void
    {
        [, $adj] = $this->subir("/api/v1/adjuntos/activos/{$this->activo}", 'foto.png', "\x89PNG\r\n\x1a\nresto", ['categoria' => 'foto']);
        $ajeno = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [D::crearSede('Ajena '.D::sufijo())]]));
        self::assertSame([404, 'ASSET_NOT_FOUND'], $this->detalle('GET', "/api/v1/adjuntos/activos/{$this->activo}", token: $ajeno));
        self::assertSame([404, 'ATTACHMENT_NOT_FOUND'], $this->detalle('GET', "/api/v1/adjuntos/{$adj['ADJ_Adjunto']}/download", token: $ajeno));
        $consulta = $this->tokenDe(D::crearUsuario(['rol' => 'CONSULTA', 'sedes' => [$this->sede]]));
        self::assertSame(200, $this->archivo('GET', "/api/v1/adjuntos/{$adj['ADJ_Adjunto']}/download", token: $consulta)->getStatusCode());
        self::assertSame(403, $this->subir("/api/v1/adjuntos/activos/{$this->activo}", 'x.pdf', self::PDF, token: $consulta)[0]);
        self::assertSame(403, $this->archivo('DELETE', "/api/v1/adjuntos/{$adj['ADJ_Adjunto']}", token: $consulta)->getStatusCode());
        $seguridad = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_SEGURIDAD', 'global' => true]));
        self::assertSame(403, $this->archivo('GET', "/api/v1/adjuntos/activos/{$this->activo}", token: $seguridad)->getStatusCode(), 'Sin rol de la aplicación.');

        // Licencias: solo administradores; para los demás el archivo «no existe».
        $licencia = (int) D::conexion()->fetchOne('SELECT MAX("LIC_Licencia") FROM "INV_LICENCIA"') ?: $this->licencia();
        [$e, $lic] = $this->subir("/api/v1/adjuntos/licencias/{$licencia}", 'activacion.txt', "CLAVE-123\n", ['categoria' => 'factura']);
        self::assertSame([201, 'otro'], [$e, $lic['ADJ_Categoria']], 'La licencia no admite categoría.');
        $tecnico = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede], 'global' => true]));
        self::assertSame([404, 'ATTACHMENT_NOT_FOUND'], $this->detalle('GET', "/api/v1/adjuntos/{$lic['ADJ_Adjunto']}/download", token: $tecnico));
        self::assertSame(403, $this->archivo('GET', "/api/v1/adjuntos/licencias/{$licencia}", token: $tecnico)->getStatusCode());
        self::assertSame([404, 'LICENSE_NOT_FOUND'], $this->detalle('GET', '/api/v1/adjuntos/licencias/999999999'));
    }

    public function testArchivoBorradoDelVolumenResponde410(): void
    {
        [, $adj] = $this->subir("/api/v1/adjuntos/activos/{$this->activo}", 'nota.csv', "a,b\n1,2\n");
        unlink('var/adjuntos_pruebas/'.$adj['ADJ_Object_Key']);
        self::assertSame([410, 'ATTACHMENT_FILE_MISSING'], $this->detalle('GET', "/api/v1/adjuntos/{$adj['ADJ_Adjunto']}/download"));
    }

    public function testAlmacenS3ContraMinioReal(): void
    {
        $endpoint = (string) ($_SERVER['S3_PRUEBAS_ENDPOINT'] ?? '');
        $ajustes = Ajustes::desdeEntorno(['SECRET_KEY' => str_repeat('s', 40), 'STORAGE_BACKEND' => 's3', 'S3_ENDPOINT_URL' => $endpoint,
            'S3_ACCESS_KEY' => 'pruebas', 'S3_SECRET_KEY' => 'pruebas-minio-2026', 'S3_BUCKET' => 'adjuntos-pruebas']);
        $cliente = new S3Client(['endpoint' => $endpoint, 'accessKeyId' => 'pruebas', 'accessKeySecret' => 'pruebas-minio-2026', 'pathStyleEndpoint' => 'true']);
        try {
            if (!$cliente->bucketExists(['Bucket' => 'adjuntos-pruebas'])->isSuccess()) {
                $cliente->createBucket(['Bucket' => 'adjuntos-pruebas'])->resolve();
            }
        } catch (\Throwable $e) {
            self::markTestSkipped('MinIO de pruebas no disponible: '.$e->getMessage());
        }
        $almacen = new AlmacenS3($ajustes);
        $objeto = $almacen->guardar('activos/x/'.D::sufijo().'.pdf', self::PDF, 'application/pdf');
        self::assertSame(['s3', 'adjuntos-pruebas', hash('sha256', self::PDF)], [$objeto->backend, $objeto->bucket, $objeto->sha256]);
        self::assertSame(self::PDF, $almacen->leer($objeto->clave, $objeto->bucket));
        $almacen->borrar($objeto->clave, $objeto->bucket);
        try {
            $almacen->leer($objeto->clave);
            self::fail('Un objeto borrado no se lee.');
        } catch (AlmacenNoDisponible $e) {
            self::assertSame(AlmacenNoDisponible::LECTURA, $e->getMessage(), 'En S3 un faltante es STORAGE_READ_FAILED (503), como el backend anterior.');
        }
        try {
            $almacen->leer('../../etc/passwd');
            self::fail('Una clave que sale de la raíz se rechaza.');
        } catch (AlmacenNoDisponible $e) {
            self::assertSame(AlmacenNoDisponible::LECTURA, $e->getMessage());
        }
    }

    private function licencia(): int
    {
        D::conexion()->executeStatement('INSERT INTO "INV_LICENCIA" ("SOF_Software", "TLI_Tipo_Licencia", "LIC_Cantidad_Total", "LIC_Cantidad_Usada") VALUES (:s, :t, 1, 0)',
            ['s' => I::software(), 't' => I::tipoLicencia()]);

        return (int) D::conexion()->fetchOne('SELECT MAX("LIC_Licencia") FROM "INV_LICENCIA"');
    }
}
