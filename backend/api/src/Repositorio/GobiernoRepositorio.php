<?php

declare(strict_types=1);

namespace App\Repositorio;

use App\Dominio\Ident;
use App\Dominio\Reloj;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

/** Bitácora de auditoría y revocación de tokens. */
final readonly class GobiernoRepositorio
{
    public function __construct(private Connection $conexion)
    {
    }

    /**
     * Registra un evento en INV_AUDITORIA_SISTEMA. La fecha se genera aquí:
     * el INSERT no usa RETURNING, que la política VPD de lectura rechazaría
     * para un evento que el usuario registra pero no puede leer.
     *
     * @param array<string, mixed>|null $snapshot
     */
    public function registrarAuditoria(
        string $accion,
        string $entidad,
        ?array $snapshot = null,
        ?string $usuarioId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?int $sedeId = null,
    ): string {
        $id = Ident::nuevo();
        $this->conexion->executeStatement(
            'INSERT INTO "INV_AUDITORIA_SISTEMA" ("AUD_Auditoria", "AUD_Fecha_Hora", "AUD_Accion", '
            .'"AUD_Entidad_Afectada", "AUD_Snapshot_JSON", "AUD_IP_Origen", "AUD_User_Agent", "AUD_Sede", "USU_Usuario") '
            .'VALUES (:id, :fecha, :accion, :entidad, :snapshot, :ip, :ua, :sede, :usuario)',
            [
                'id' => $id,
                'fecha' => Reloj::aBd(Reloj::ahora()),
                'accion' => $accion,
                'entidad' => $entidad,
                'snapshot' => null === $snapshot ? null : json_encode(
                    $snapshot,
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
                ),
                'ip' => $ip,
                'ua' => $userAgent,
                'sede' => $sedeId,
                'usuario' => $usuarioId,
            ],
            ['snapshot' => ParameterType::LARGE_OBJECT, 'sede' => ParameterType::INTEGER],
        );

        return $id;
    }

    /**
     * Revoca un jti de forma ATÓMICA: false si ya estaba revocado (otra petición
     * lo revocó antes). Es la base de la rotación segura del refresh y del uso
     * único de los desafíos 2FA. En Oracle la violación de clave revierte solo
     * la sentencia, no la transacción en curso.
     */
    public function revocarJtiUnaVez(string $jti, string $tipo, \DateTimeImmutable $expira, ?string $usuarioHex): bool
    {
        try {
            $this->conexion->executeStatement(
                'INSERT INTO "SYS_TOKEN_REVOCADO" ("TRV_Jti", "TRV_Tipo", "TRV_Fecha_Revocacion", "TRV_Expira", "USU_Usuario") '
                .'VALUES (:jti, :tipo, :ahora, :expira, :u)',
                ['jti' => $jti, 'tipo' => $tipo, 'ahora' => Reloj::aBd(Reloj::ahora()), 'expira' => Reloj::aBd($expira), 'u' => $usuarioHex],
            );

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** Momento en que se revocó el jti, o null si no está revocado. */
    public function revocadoEn(string $jti): ?\DateTimeImmutable
    {
        $fecha = $this->conexion->fetchOne(
            'SELECT "TRV_Fecha_Revocacion" FROM "SYS_TOKEN_REVOCADO" WHERE "TRV_Jti" = :jti',
            ['jti' => $jti],
        );

        return false === $fecha ? null : Reloj::desdeBd((string) $fecha);
    }

    /**
     * Revoca TODOS los tokens emitidos al usuario hasta este instante (cambio de
     * contraseña, cierre global, reuso de refresh, offboarding) y cierra sus
     * sesiones. El comodín USR-ALL-{id} guarda la marca y se ACTUALIZA en cada
     * llamada: si solo se insertara la primera vez, una segunda revocación no
     * invalidaría lo emitido entre ambas.
     */
    public function revocarTodo(string $usuarioHex, \DateTimeImmutable $expira): void
    {
        $ahora = Reloj::aBd(Reloj::ahora());
        $this->conexion->executeStatement(
            'MERGE INTO "SYS_TOKEN_REVOCADO" t USING (SELECT :jti AS jti FROM DUAL) s ON (t."TRV_Jti" = s.jti) '
            .'WHEN MATCHED THEN UPDATE SET t."TRV_Fecha_Revocacion" = :ahora, t."TRV_Expira" = :expira '
            ."WHEN NOT MATCHED THEN INSERT (\"TRV_Jti\", \"TRV_Tipo\", \"TRV_Fecha_Revocacion\", \"TRV_Expira\", \"USU_Usuario\") "
            ."VALUES (:jti, 'user_all', :ahora, :expira, :u)",
            ['jti' => 'USR-ALL-'.Ident::aTexto($usuarioHex), 'ahora' => $ahora, 'expira' => Reloj::aBd($expira), 'u' => $usuarioHex],
        );
        $this->conexion->executeStatement(
            'UPDATE "SYS_SESION" SET "SES_Cerrada_En" = :ahora, "SES_Motivo_Cierre" = \'revocacion_global\' '
            .'WHERE "USU_Usuario" = :u AND "SES_Cerrada_En" IS NULL',
            ['ahora' => $ahora, 'u' => $usuarioHex],
        );
    }

    public function jtiRevocado(string $jti): bool
    {
        return false !== $this->conexion->fetchOne(
            'SELECT 1 FROM "SYS_TOKEN_REVOCADO" WHERE "TRV_Jti" = :jti',
            ['jti' => $jti],
        );
    }

    /** ¿Se revocaron todos los tokens del usuario después de emitido este? */
    public function revocadoGlobalmente(string $usuarioHex, \DateTimeImmutable $emitido): bool
    {
        $fecha = $this->conexion->fetchOne(
            'SELECT "TRV_Fecha_Revocacion" FROM "SYS_TOKEN_REVOCADO" WHERE "TRV_Jti" = :jti',
            ['jti' => 'USR-ALL-'.Ident::aTexto($usuarioHex)],
        );
        if (false === $fecha || null === $fecha) {
            return false;
        }

        return Reloj::desdeBd((string) $fecha) >= $emitido;
    }
}
