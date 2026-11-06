<?php

declare(strict_types=1);

namespace App\Tareas;

use App\Config\Ajustes;
use App\Dominio\Reloj;
use Doctrine\DBAL\Connection;

/**
 * Limpia registros de seguridad caducados (crecimiento acotado de las tablas
 * del camino de autenticación): tokens revocados ya expirados, claves de
 * idempotencia de más de 24 h, enlaces de restablecimiento y códigos 2FA
 * vencidos o usados, y correos entregados más antiguos que la retención.
 * Cada 6 h en el worker y bajo demanda en POST /gov/security/purge.
 */
final readonly class PurgaSeguridad implements TareaExclusiva
{
    public const int CADA_SEGUNDOS = 6 * 3600;

    public function __construct(private Connection $conexion, private Ajustes $ajustes)
    {
    }

    public function nombre(): string
    {
        return 'purge_security';
    }

    public function cadaSegundos(): int
    {
        return self::CADA_SEGUNDOS;
    }

    /** @return array<string, int> filas eliminadas por tipo */
    public function ejecutar(): array
    {
        $ahora = Reloj::ahora();
        $p = ['ahora' => Reloj::aBd($ahora)];

        return $this->conexion->transactional(fn (): array => [
            'tokens_revocados_eliminados' => $this->conexion->executeStatement('DELETE FROM "SYS_TOKEN_REVOCADO" WHERE "TRV_Expira" < :ahora', $p),
            'idempotency_keys_eliminadas' => $this->conexion->executeStatement('DELETE FROM "SYS_IDEMPOTENCY_KEY" WHERE "IDK_Creada_En" < :corte',
                ['corte' => Reloj::aBd($ahora->modify('-24 hours'))]),
            'reset_tokens_eliminados' => $this->conexion->executeStatement('DELETE FROM "SYS_PASSWORD_RESET" WHERE "PRT_Expira" < :ahora OR "PRT_Usado" = 1', $p),
            'otp_2fa_eliminados' => $this->conexion->executeStatement('DELETE FROM "SYS_2FA_CODE" WHERE "TFC_Expira" < :ahora OR "TFC_Usado" = 1', $p),
            'correos_enviados_eliminados' => $this->conexion->executeStatement(
                "DELETE FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Estado\" = 'ENVIADO' AND \"EOB_Enviado_En\" < :corte",
                ['corte' => Reloj::aBd($ahora->modify("-{$this->ajustes->outboxRetencionDias} days"))]),
        ]);
    }
}
