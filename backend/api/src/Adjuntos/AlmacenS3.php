<?php

declare(strict_types=1);

namespace App\Adjuntos;

use App\Config\Ajustes;
use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Adjuntos en MinIO, que corre en el propio servidor y se habla con el protocolo S3
 * (direccionamiento por ruta, como MinIO lo requiere). El cliente se crea al
 * primer uso; un adjunto guardado en otro bucket se lee de ese bucket.
 */
final class AlmacenS3 extends AlmacenFlysystem
{
    public const string NOMBRE = 's3';

    private ?S3Client $cliente = null;
    /** @var array<string, FilesystemOperator> */
    private array $sistemas = [];

    public function __construct(private readonly Ajustes $ajustes)
    {
    }

    public function nombre(): string
    {
        return self::NOMBRE;
    }

    protected function sistema(?string $bucket): FilesystemOperator
    {
        $bucket ??= $this->ajustes->s3Bucket;

        return $this->sistemas[$bucket] ??= new Filesystem(new AsyncAwsS3Adapter($this->cliente(), $bucket));
    }

    protected function bucketRegistrado(): string
    {
        return $this->ajustes->s3Bucket;
    }

    /** HEAD del bucket configurado (como `head_bucket` del backend anterior). */
    public function disponible(): bool
    {
        try {
            // HEAD del bucket: 200 = existe y las credenciales sirven.
            return $this->cliente()->bucketExists(['Bucket' => $this->ajustes->s3Bucket])->isSuccess();
        } catch (\Throwable) {
            return false;
        }
    }

    private function cliente(): S3Client
    {
        return $this->cliente ??= new S3Client([
            // Siempre MinIO: Ajustes exige S3_ENDPOINT_URL; nunca un servicio externo por defecto.
            'endpoint' => (string) $this->ajustes->s3Endpoint,
            'region' => $this->ajustes->s3Region,
            'accessKeyId' => (string) $this->ajustes->s3Usuario,
            'accessKeySecret' => (string) $this->ajustes->s3Clave,
            'pathStyleEndpoint' => 'true',
            'sendChunkedBody' => 'false',
        ], null, HttpClient::create(['timeout' => $this->ajustes->almacenamientoTimeout, 'max_duration' => 4 * $this->ajustes->almacenamientoTimeout]));
    }
}
