<?php

declare(strict_types=1);

namespace App\Correo;

use App\Config\Ajustes;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Http\TareasPosteriores;
use App\Integracion\FuenteIntegracion;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * API pública de notificaciones por correo (mismo comportamiento que el backend anterior):
 * - Se llama DESPUÉS de confirmar la transacción de negocio: nunca la revierte
 *   ni la retrasa, y nunca lanza por fallos de entrega.
 * - Correos con secretos de un solo uso (restablecimiento, código 2FA): no se
 *   guardan en la cola; se entregan directamente tras enviar la respuesta.
 * - El resto se encola en SYS_EMAIL_OUTBOX (cola persistente con reintentos),
 *   con el formato que procesa el worker de correo.
 * - Sin correo configurado, se registra y no se envía (modo silencioso).
 */
final readonly class Notificador
{
    /** Plantillas con secretos de un solo uso: nunca se persisten. */
    public const array EFIMERAS = ['password_reset', '2fa_code'];
    /** Eventos con reglas de destinatarios configurables (jefes, afectado, administradores). */
    public const array CONFIGURABLES = ['asignacion', 'devolucion', 'transferencia', 'baja', 'offboarding',
        'mantenimiento_abierto', 'mantenimiento_cerrado', 'stock_bajo', 'garantia_por_vencer', 'recepcion_diferencias', 'ingreso_registrado'];
    private const int INTENTOS_EFIMEROS = 3;

    public function __construct(
        private Plantillas $plantillas,
        private FuenteIntegracion $fuente,
        private EntregaCorreo $entrega,
        private TareasPosteriores $tareas,
        private Connection $conexion,
        private Ajustes $ajustes,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $contexto
     * @param list<string|null>    $para       destinatarios directos
     * @param list<string|null>|null $afectados personas involucradas (por defecto = $para)
     */
    public function enviar(
        string $plantilla,
        array $contexto,
        array $para = [],
        bool $copiarAdmins = true,
        ?string $responderA = null,
        ?string $operador = null,
        ?string $rolOperador = null,
        ?array $afectados = null,
    ): void {
        try {
            $this->enviarOFallar($plantilla, $contexto, $para, $copiarAdmins, $responderA, $operador, $rolOperador, $afectados);
        } catch (\Throwable $e) {
            $this->logger->error('email.notificacion_fallida', ['plantilla' => $plantilla, 'error' => $e::class]);
        }
    }

    /** Aviso al titular de que su contraseña cambió (sin copia a administradores). */
    public function contrasenaCambiada(string $nombre, string $username, ?string $correo, string $metodo, ?string $ip): void
    {
        if (null === $correo || '' === $correo) {
            return;
        }
        $this->enviar('password_changed', [
            'persona_nombre' => $nombre, 'username' => $username, 'metodo' => $metodo,
            'fecha' => self::ahoraIso(), 'ip' => $ip ?? '',
        ], [$correo], false);
    }

    /**
     * Aviso de seguridad solo al titular de la cuenta.
     *
     * @param array<string, mixed> $contexto
     */
    public function eventoSeguridad(string $plantilla, string $nombre, string $username, ?string $correo, array $contexto = []): void
    {
        if (null === $correo || '' === $correo) {
            return;
        }
        $this->enviar($plantilla, [
            'persona_nombre' => $nombre, 'username' => $username, 'fecha' => self::ahoraIso(), ...$contexto,
        ], [$correo], false);
    }

    /**
     * @param array<string, mixed>   $contexto
     * @param list<string|null>      $para
     * @param list<string|null>|null $afectados
     */
    private function enviarOFallar(
        string $plantilla,
        array $contexto,
        array $para,
        bool $copiarAdmins,
        ?string $responderA,
        ?string $operador,
        ?string $rolOperador,
        ?array $afectados,
    ): void {
        $para = self::limpiar($para);
        [$asunto, $html] = $this->plantillas->renderizar($plantilla, [
            ...$contexto,
            'operator_name' => $operador ?? 'Sistema',
            'operator_role' => $rolOperador ?? '',
            'reply_to' => $responderA ?? '',
        ]);

        if (!$this->fuente->obtener()->correo->lista()) {
            $this->logger->info('email.silenced', ['plantilla' => $plantilla, 'asunto' => $asunto]);

            return;
        }

        $directos = self::limpiar([...$para, ...($copiarAdmins ? $this->ajustes->correosAdmin : [])]);
        if (\in_array($plantilla, self::EFIMERAS, true)) {
            if ([] !== $directos) {
                $this->programarEntregaDirecta($plantilla, $directos, $asunto, $html, $responderA);
            }

            return;
        }

        $configurable = \in_array($plantilla, self::CONFIGURABLES, true);
        if (!$configurable && [] === $directos) {
            return;
        }
        try {
            $this->encolar($plantilla, $asunto, $html, $configurable ? $para : $directos,
                null === $afectados ? null : self::limpiar($afectados), $copiarAdmins, $configurable, $responderA);
        } catch (\Throwable $e) {
            $this->logger->error('email.enqueue_failed', ['plantilla' => $plantilla, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            if ([] !== $directos) {
                $this->programarEntregaDirecta($plantilla, $directos, $asunto, $html, $responderA);
            }
        }
    }

    /**
     * Inserta en la cola persistente (transacción propia: la notificación ya es
     * posterior al commit de negocio).
     *
     * @param list<string>      $para
     * @param list<string>|null $afectados
     */
    private function encolar(
        string $plantilla,
        string $asunto,
        string $html,
        array $para,
        ?array $afectados,
        bool $copiarAdmins,
        bool $resolver,
        ?string $responderA,
    ): void {
        $json = static fn (?array $v): ?string => null === $v ? null
            : json_encode($v, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $ahora = Reloj::aBd(Reloj::ahora());
        $this->conexion->executeStatement(
            'INSERT INTO "SYS_EMAIL_OUTBOX" ("EOB_Id", "EOB_Plantilla", "EOB_Asunto", "EOB_Html", "EOB_Para", '
            .'"EOB_Afectados", "EOB_Cc_Admins", "EOB_Resolver", "EOB_Reply_To", "EOB_Estado", "EOB_Intentos", '
            .'"EOB_Proximo_Intento", "EOB_Creado_En") VALUES (:id, :plantilla, :asunto, :html, :para, :afectados, '
            .":cc, :resolver, :reply, 'PENDIENTE', 0, :ahora, :ahora)",
            [
                'id' => Ident::nuevo(),
                'plantilla' => $plantilla,
                'asunto' => mb_substr($asunto, 0, 255),
                'html' => $html,
                'para' => $json($para),
                'afectados' => $json($afectados),
                'cc' => $copiarAdmins ? 1 : 0,
                'resolver' => $resolver ? 1 : 0,
                'reply' => null === $responderA || '' === $responderA ? null : mb_substr($responderA, 0, 150),
                'ahora' => $ahora,
            ],
            ['html' => ParameterType::LARGE_OBJECT, 'para' => ParameterType::LARGE_OBJECT, 'afectados' => ParameterType::LARGE_OBJECT],
        );
    }

    /** @param list<string> $para */
    private function programarEntregaDirecta(string $plantilla, array $para, string $asunto, string $html, ?string $responderA): void
    {
        $this->tareas->programar("correo:{$plantilla}", function () use ($para, $asunto, $html, $responderA): void {
            $config = $this->fuente->obtener()->correo;
            for ($intento = 1; $intento <= self::INTENTOS_EFIMEROS; ++$intento) {
                try {
                    $this->entrega->entregar($config, $para, $asunto, $html, $responderA);

                    return;
                } catch (ErrorEnvioCorreo $e) {
                    // Credenciales malas o pausa: reintentar solo acercaría un bloqueo de la cuenta.
                    if ($e->esDeAutenticacion() || 'NOT_CONFIGURED' === $e->codigo) {
                        $this->logger->error('email.auth_failed', ['codigo' => $e->codigo]);

                        return;
                    }
                    $this->logger->warning('email.send_attempt_failed', ['intento' => $intento, 'codigo' => $e->codigo]);
                    if ($intento < self::INTENTOS_EFIMEROS) {
                        usleep(500_000 * $intento);
                    }
                }
            }
            $this->logger->error('email.max_retries_reached', ['asunto' => $asunto]);
        });
    }

    /**
     * @param list<string|null> $correos
     *
     * @return list<string>
     */
    private static function limpiar(array $correos): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (?string $c): string => trim((string) $c), $correos),
            static fn (string $c): bool => '' !== $c,
        )));
    }

    private static function ahoraIso(): string
    {
        return Reloj::ahora()->format('Y-m-d\TH:i:s');
    }
}
