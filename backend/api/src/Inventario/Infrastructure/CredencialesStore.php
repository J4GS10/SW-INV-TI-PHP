<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure;

use App\Db\InsercionOracle;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** License credentials (seats) as stored, and what they read about their license: all that SQL lives here. */
final readonly class CredencialesStore
{
    public function __construct(private Connection $conexion)
    {
    }

    /** @return array<string, mixed>|false the license with its counting form code and its activation method columns */
    public function licencia(int $id): array|false
    {
        return $this->conexion->fetchAssociative('SELECT l."LIC_Licencia", l."LIC_Cantidad_Total", l."LIC_Cantidad_Usada", l."LIC_Clave_Activacion", '
            .'l."MAC_Metodo_Activacion", fc."FCO_Codigo", m.* FROM "INV_LICENCIA" l LEFT JOIN "INV_FORMA_CONTEO" fc ON fc."FCO_Forma" = l."FCO_Forma_Conteo" '
            .'LEFT JOIN "INV_METODO_ACTIVACION" m ON m."MAC_Metodo" = l."MAC_Metodo_Activacion" WHERE l."LIC_Licencia" = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|false the credential row, locked when asked */
    public function porId(int $id, bool $bloquear = false): array|false
    {
        return $this->conexion->fetchAssociative('SELECT * FROM "INV_LICENCIA_CLAVE" WHERE "LCL_Licencia_Clave" = :id'.($bloquear ? ' FOR UPDATE' : ''), ['id' => $id]);
    }

    /** @return array<string, mixed>|false SOF_Nombre and SOF_Version of the software the license belongs to */
    public function softwareDeLicencia(int $licencia): array|false
    {
        return $this->conexion->fetchAssociative('SELECT s."SOF_Nombre", s."SOF_Version" FROM "INV_SOFTWARE" s JOIN "INV_LICENCIA" l ON l."SOF_Software" = s."SOF_Software" '
            .'WHERE l."LIC_Licencia" = :l', ['l' => $licencia]);
    }

    /**
     * Whether any of those fingerprints is already taken by a credential other than `$excepto`.
     *
     * @param list<string> $huellas at most 1000
     */
    public function existeHuella(array $huellas, ?int $excepto = null): bool
    {
        if ([] === $huellas) {
            return false;
        }

        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_LICENCIA_CLAVE" WHERE "LCL_Clave_Hash" IN (:h) AND "LCL_Licencia_Clave" <> :id FETCH FIRST 1 ROWS ONLY',
            ['h' => $huellas, 'id' => $excepto ?? 0], ['h' => ArrayParameterType::STRING]);
    }

    /** How many credentials of the license are not retired. */
    public function vigentesDeLicencia(int $licencia): int
    {
        return (int) $this->conexion->fetchOne("SELECT COUNT(*) FROM \"INV_LICENCIA_CLAVE\" WHERE \"LIC_Licencia\" = :l AND \"LCL_Estado\" <> 'BAJA'", ['l' => $licencia]);
    }

    /** The license an attachment belongs to (null if none), or false if the attachment does not exist. */
    public function licenciaDeAdjunto(string $adjunto): mixed
    {
        return $this->conexion->fetchOne('SELECT "LIC_Licencia" FROM "INV_ADJUNTO" WHERE "ADJ_Adjunto" = :a', ['a' => $adjunto]);
    }

    /**
     * @param array<string, mixed> $columnas
     * @param ?string              $vence    YYYY-MM-DD
     */
    public function insertar(array $columnas, ?string $vence): int
    {
        $id = (int) InsercionOracle::insertar($this->conexion, 'INV_LICENCIA_CLAVE', $columnas, 'LCL_Licencia_Clave');
        if (null !== $vence) {
            $this->conexion->executeStatement('UPDATE "INV_LICENCIA_CLAVE" SET "LCL_Vence" = TO_DATE(:v, \'YYYY-MM-DD\') WHERE "LCL_Licencia_Clave" = :id', ['v' => $vence, 'id' => $id]);
        }

        return $id;
    }

    /**
     * Writes the given columns; with `$cambiaVence`, also the expiry date (null clears it).
     *
     * @param array<string, mixed> $cambios column => value; the column names come from the code, never from the request
     * @param ?string              $vence   YYYY-MM-DD
     */
    public function actualizar(int $id, array $cambios, bool $cambiaVence = false, ?string $vence = null): void
    {
        $sets = [];
        $parametros = ['id' => $id];
        foreach ($cambios as $columna => $valor) {
            $sets[] = "\"{$columna}\" = :{$columna}";
            $parametros[$columna] = $valor;
        }
        if ($cambiaVence) {
            $sets[] = "\"LCL_Vence\" = TO_DATE(:vence, 'YYYY-MM-DD')";
            $parametros['vence'] = $vence;
        }
        if ([] !== $sets) {
            $this->conexion->executeStatement('UPDATE "INV_LICENCIA_CLAVE" SET '.implode(', ', $sets).' WHERE "LCL_Licencia_Clave" = :id', $parametros);
        }
    }

    /** @return list<array<string, mixed>> the credentials of a license with their current installation, asset, person and file name */
    public function deLicenciaConDestino(int $licencia): array
    {
        return $this->conexion->fetchAllAssociative('SELECT c.*, i."INS_Fecha_Instalacion", i."ACT_Activo" AS "I_ACT", i."PER_Persona" AS "I_PER", '
            .'a."ACT_Codigo_Interno", p."PER_Primer_Nombre", p."PER_Primer_Apellido", p."PER_Email_Corporativo", ad."ADJ_Nombre_Original" '
            .'FROM "INV_LICENCIA_CLAVE" c LEFT JOIN "INV_INSTALACION" i ON i."LCL_Licencia_Clave" = c."LCL_Licencia_Clave" AND i."INS_Estado" = 1 '
            .'LEFT JOIN "INV_ACTIVO" a ON a."ACT_Activo" = i."ACT_Activo" LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = i."PER_Persona" '
            .'LEFT JOIN "INV_ADJUNTO" ad ON ad."ADJ_Adjunto" = c."LCL_Archivo" WHERE c."LIC_Licencia" = :l ORDER BY c."LCL_Licencia_Clave"', ['l' => $licencia]);
    }

    /**
     * Flags the credentials that hold a secret as needing a change; returns how many.
     *
     * @param list<int> $ids at most 1000
     */
    public function exigirCambio(array $ids): int
    {
        return (int) $this->conexion->executeStatement('UPDATE "INV_LICENCIA_CLAVE" SET "LCL_Requiere_Cambio" = 1 WHERE "LCL_Licencia_Clave" IN (:ids) AND "LCL_Secreto_Enc" IS NOT NULL',
            ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]);
    }
}
