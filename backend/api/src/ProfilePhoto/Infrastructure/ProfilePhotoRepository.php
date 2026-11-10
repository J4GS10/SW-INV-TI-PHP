<?php

declare(strict_types=1);

namespace App\ProfilePhoto\Infrastructure;

use App\ProfilePhoto\Domain\ProfilePhoto;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class ProfilePhotoRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(string $userHex, ProfilePhoto $photo): void
    {
        $this->connection->executeStatement(
            'MERGE INTO "INV_USUARIO_FOTO" f USING (SELECT :id AS "USU_Usuario" FROM DUAL) s ON (f."USU_Usuario" = s."USU_Usuario") '
            .'WHEN MATCHED THEN UPDATE SET f."UFO_Imagen" = :img, f."UFO_Tipo_MIME" = :mime, f."UFO_Fecha_Modificacion" = SYSTIMESTAMP '
            .'WHEN NOT MATCHED THEN INSERT ("USU_Usuario", "UFO_Imagen", "UFO_Tipo_MIME") VALUES (:id, :img, :mime)',
            ['id' => $userHex, 'img' => $photo->bytes, 'mime' => ProfilePhoto::MIME],
            ['img' => ParameterType::LARGE_OBJECT],
        );
    }

    public function delete(string $userHex): bool
    {
        return $this->connection->executeStatement('DELETE FROM "INV_USUARIO_FOTO" WHERE "USU_Usuario" = :id', ['id' => $userHex]) > 0;
    }

    /** @return array{bytes: string, mime: string, updated_at: string}|null */
    public function find(string $userHex): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT "UFO_Imagen", "UFO_Tipo_MIME", "UFO_Fecha_Modificacion" FROM "INV_USUARIO_FOTO" WHERE "USU_Usuario" = :id',
            ['id' => $userHex],
        );
        if (false === $row) {
            return null;
        }
        $bytes = $row['UFO_Imagen'];

        return [
            'bytes' => \is_resource($bytes) ? (string) stream_get_contents($bytes) : (string) $bytes,
            'mime' => (string) $row['UFO_Tipo_MIME'],
            'updated_at' => (string) $row['UFO_Fecha_Modificacion'],
        ];
    }

    public function updatedAt(string $userHex): ?string
    {
        $value = $this->connection->fetchOne('SELECT "UFO_Fecha_Modificacion" FROM "INV_USUARIO_FOTO" WHERE "USU_Usuario" = :id', ['id' => $userHex]);

        return false === $value || null === $value ? null : (string) $value;
    }
}
