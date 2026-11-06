<?php

declare(strict_types=1);

namespace App\Repositorio;

use App\Dominio\Ident;
use App\Dominio\Reloj;
use Doctrine\DBAL\Connection;

/**
 * Credenciales de un solo uso y su historial: tokens de restablecimiento
 * (SYS_PASSWORD_RESET), códigos 2FA por correo (SYS_2FA_CODE), códigos de
 * recuperación (SYS_2FA_RECOVERY) e historial de contraseñas
 * (SYS_PASSWORD_HISTORIAL). Solo se guardan hashes.
 *
 * Todo consumo es ATÓMICO (UPDATE condicional): ante dos peticiones
 * simultáneas con el mismo token o código, solo una lo usa.
 */
final readonly class CredencialesRepositorio
{
    public function __construct(private Connection $conexion)
    {
    }

    // ---- Restablecimiento de contraseña ---------------------------------

    public function solicitudResetReciente(string $usuarioHex, int $minutos): bool
    {
        return false !== $this->conexion->fetchOne(
            'SELECT 1 FROM "SYS_PASSWORD_RESET" WHERE "USU_Usuario" = :u AND "PRT_Creado_En" >= :desde FETCH FIRST 1 ROWS ONLY',
            ['u' => $usuarioHex, 'desde' => Reloj::aBd(Reloj::ahora()->modify("-{$minutos} minutes"))],
        );
    }

    /** Un solo token activo a la vez: invalida los pendientes. */
    public function invalidarResets(string $usuarioHex): void
    {
        $this->conexion->executeStatement(
            'UPDATE "SYS_PASSWORD_RESET" SET "PRT_Usado" = 1 WHERE "USU_Usuario" = :u AND "PRT_Usado" = 0',
            ['u' => $usuarioHex],
        );
    }

    public function crearReset(string $usuarioHex, string $hash, \DateTimeImmutable $expira): void
    {
        $this->conexion->executeStatement(
            'INSERT INTO "SYS_PASSWORD_RESET" ("PRT_Id", "PRT_Token_Hash", "PRT_Expira", "PRT_Usado", "PRT_Creado_En", "USU_Usuario") '
            .'VALUES (:id, :hash, :expira, 0, :ahora, :u)',
            ['id' => Ident::nuevo(), 'hash' => $hash, 'expira' => Reloj::aBd($expira), 'ahora' => Reloj::aBd(Reloj::ahora()), 'u' => $usuarioHex],
        );
    }

    /** Usuario dueño de un token vigente, sin consumirlo (para validar antes de aplicar). */
    public function resetVigente(string $hash): ?string
    {
        $usuario = $this->conexion->fetchOne(
            'SELECT "USU_Usuario" FROM "SYS_PASSWORD_RESET" WHERE "PRT_Token_Hash" = :hash AND "PRT_Usado" = 0 AND "PRT_Expira" > :ahora',
            ['hash' => $hash, 'ahora' => Reloj::aBd(Reloj::ahora())],
        );

        return false === $usuario ? null : (string) $usuario;
    }

    /**
     * Consume un token vigente (no usado y no expirado). Devuelve el usuario
     * dueño o null; un token solo se puede consumir una vez.
     */
    public function consumirReset(string $hash): ?string
    {
        $fila = $this->conexion->fetchAssociative(
            'SELECT "PRT_Id", "USU_Usuario" FROM "SYS_PASSWORD_RESET" '
            .'WHERE "PRT_Token_Hash" = :hash AND "PRT_Usado" = 0 AND "PRT_Expira" > :ahora',
            ['hash' => $hash, 'ahora' => Reloj::aBd(Reloj::ahora())],
        );
        if (false === $fila) {
            return null;
        }
        $consumido = $this->conexion->executeStatement(
            'UPDATE "SYS_PASSWORD_RESET" SET "PRT_Usado" = 1 WHERE "PRT_Id" = :id AND "PRT_Usado" = 0',
            ['id' => $fila['PRT_Id']],
        );

        return 1 === $consumido ? (string) $fila['USU_Usuario'] : null;
    }

    // ---- Códigos 2FA por correo -----------------------------------------

    public function invalidarOtps(string $usuarioHex): void
    {
        $this->conexion->executeStatement(
            'UPDATE "SYS_2FA_CODE" SET "TFC_Usado" = 1 WHERE "USU_Usuario" = :u AND "TFC_Usado" = 0',
            ['u' => $usuarioHex],
        );
    }

    public function crearOtp(string $usuarioHex, string $hash, \DateTimeImmutable $expira): void
    {
        $this->conexion->executeStatement(
            'INSERT INTO "SYS_2FA_CODE" ("TFC_Id", "TFC_Code_Hash", "TFC_Expira", "TFC_Usado", "TFC_Intentos", "TFC_Creado_En", "USU_Usuario") '
            .'VALUES (:id, :hash, :expira, 0, 0, :ahora, :u)',
            ['id' => Ident::nuevo(), 'hash' => $hash, 'expira' => Reloj::aBd($expira), 'ahora' => Reloj::aBd(Reloj::ahora()), 'u' => $usuarioHex],
        );
    }

    /** @return array{id: string, hash: string, intentos: int}|null el más reciente vigente */
    public function otpActivo(string $usuarioHex): ?array
    {
        $fila = $this->conexion->fetchAssociative(
            'SELECT "TFC_Id", "TFC_Code_Hash", "TFC_Intentos" FROM "SYS_2FA_CODE" '
            .'WHERE "USU_Usuario" = :u AND "TFC_Usado" = 0 AND "TFC_Expira" > :ahora '
            .'ORDER BY "TFC_Creado_En" DESC FETCH FIRST 1 ROWS ONLY',
            ['u' => $usuarioHex, 'ahora' => Reloj::aBd(Reloj::ahora())],
        );

        return false === $fila ? null
            : ['id' => (string) $fila['TFC_Id'], 'hash' => (string) $fila['TFC_Code_Hash'], 'intentos' => (int) $fila['TFC_Intentos']];
    }

    public function sumarIntentoOtp(string $id): void
    {
        $this->conexion->executeStatement(
            'UPDATE "SYS_2FA_CODE" SET "TFC_Intentos" = "TFC_Intentos" + 1 WHERE "TFC_Id" = :id',
            ['id' => $id],
        );
    }

    /** true si este proceso lo marcó como usado (consumo atómico). */
    public function usarOtp(string $id): bool
    {
        return 1 === $this->conexion->executeStatement(
            'UPDATE "SYS_2FA_CODE" SET "TFC_Usado" = 1 WHERE "TFC_Id" = :id AND "TFC_Usado" = 0',
            ['id' => $id],
        );
    }

    // ---- Códigos de recuperación ----------------------------------------

    /** @param list<string> $hashes */
    public function reemplazarRecuperacion(string $usuarioHex, array $hashes): void
    {
        $this->borrarRecuperacion($usuarioHex);
        $ahora = Reloj::aBd(Reloj::ahora());
        foreach ($hashes as $hash) {
            $this->conexion->executeStatement(
                'INSERT INTO "SYS_2FA_RECOVERY" ("TRC_Id", "TRC_Code_Hash", "TRC_Usado", "TRC_Creado_En", "USU_Usuario") '
                .'VALUES (:id, :hash, 0, :ahora, :u)',
                ['id' => Ident::nuevo(), 'hash' => $hash, 'ahora' => $ahora, 'u' => $usuarioHex],
            );
        }
    }

    public function borrarRecuperacion(string $usuarioHex): void
    {
        $this->conexion->executeStatement('DELETE FROM "SYS_2FA_RECOVERY" WHERE "USU_Usuario" = :u', ['u' => $usuarioHex]);
    }

    /** @param list<string> $hashesCandidatos */
    public function consumirRecuperacion(string $usuarioHex, array $hashesCandidatos): bool
    {
        foreach ($hashesCandidatos as $hash) {
            $usado = $this->conexion->executeStatement(
                'UPDATE "SYS_2FA_RECOVERY" SET "TRC_Usado" = 1 WHERE "USU_Usuario" = :u AND "TRC_Code_Hash" = :hash AND "TRC_Usado" = 0',
                ['u' => $usuarioHex, 'hash' => $hash],
            );
            if (1 === $usado) {
                return true;
            }
        }

        return false;
    }

    public function recuperacionDisponible(string $usuarioHex): int
    {
        return (int) $this->conexion->fetchOne(
            'SELECT COUNT(*) FROM "SYS_2FA_RECOVERY" WHERE "USU_Usuario" = :u AND "TRC_Usado" = 0',
            ['u' => $usuarioHex],
        );
    }

    // ---- Historial de contraseñas ---------------------------------------

    /** @return list<string> hashes de las últimas `$cantidad` contraseñas */
    public function historial(string $usuarioHex, int $cantidad): array
    {
        if ($cantidad <= 0) {
            return [];
        }

        return array_map('strval', $this->conexion->fetchFirstColumn(
            'SELECT "PWH_Hash" FROM "SYS_PASSWORD_HISTORIAL" WHERE "USU_Usuario" = :u '
            .'ORDER BY "PWH_Creado_En" DESC FETCH FIRST '.$cantidad.' ROWS ONLY',
            ['u' => $usuarioHex],
        ));
    }

    /** Guarda el hash vigente y recorta el historial a las `$cantidad` más recientes. */
    public function recordarContrasena(string $usuarioHex, string $hash, int $cantidad): void
    {
        if ($cantidad <= 0) {
            return;
        }
        $this->conexion->executeStatement(
            'INSERT INTO "SYS_PASSWORD_HISTORIAL" ("PWH_Id", "USU_Usuario", "PWH_Hash", "PWH_Creado_En") VALUES (:id, :u, :hash, :ahora)',
            ['id' => Ident::nuevo(), 'u' => $usuarioHex, 'hash' => $hash, 'ahora' => Reloj::aBd(Reloj::ahora())],
        );
        $this->conexion->executeStatement(
            'DELETE FROM "SYS_PASSWORD_HISTORIAL" WHERE "USU_Usuario" = :u AND "PWH_Id" NOT IN ('
            .'SELECT "PWH_Id" FROM "SYS_PASSWORD_HISTORIAL" WHERE "USU_Usuario" = :u '
            .'ORDER BY "PWH_Creado_En" DESC FETCH FIRST '.$cantidad.' ROWS ONLY)',
            ['u' => $usuarioHex],
        );
    }
}
