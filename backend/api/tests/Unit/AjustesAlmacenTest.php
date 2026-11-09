<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\Ajustes;
use PHPUnit\Framework\TestCase;

final class AjustesAlmacenTest extends TestCase
{
    private const array MINIO = ['SECRET_KEY' => 'clave-de-prueba-de-al-menos-32-caracteres', 'STORAGE_BACKEND' => 's3',
        'S3_ACCESS_KEY' => 'app', 'S3_SECRET_KEY' => 'secreto', 'S3_BUCKET' => 'adjuntos'];

    public function testSinLaDireccionDeMinioNoArrancaNiUsaUnServicioExterno(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('S3_ENDPOINT_URL');

        Ajustes::desdeEntorno(self::MINIO);
    }

    public function testConLaDireccionDeMinioArranca(): void
    {
        $ajustes = Ajustes::desdeEntorno([...self::MINIO, 'S3_ENDPOINT_URL' => 'http://minio:9000']);

        self::assertSame('http://minio:9000', $ajustes->s3Endpoint);
    }
}
