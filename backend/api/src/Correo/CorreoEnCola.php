<?php

declare(strict_types=1);

namespace App\Correo;

use App\Dominio\Reloj;

/**
 * Un correo de SYS_EMAIL_OUTBOX y las transiciones de su estado:
 * PENDIENTE → ENVIADO, o PENDIENTE (reprogramado) → … → FALLIDO.
 */
final class CorreoEnCola
{
    public const string PENDIENTE = 'PENDIENTE';
    public const string ENVIADO = 'ENVIADO';
    public const string FALLIDO = 'FALLIDO';

    /**
     * @param list<string>      $para
     * @param list<string>|null $afectados
     */
    public function __construct(
        public readonly string $id,
        public readonly string $plantilla,
        public readonly string $asunto,
        public readonly string $html,
        public array $para,
        public readonly ?array $afectados,
        public readonly bool $copiarAdmins,
        public bool $resolver,
        public readonly ?string $responderA,
        public string $estado,
        public int $intentos,
        public \DateTimeImmutable $proximoIntento,
        public ?string $ultimoError = null,
        public ?\DateTimeImmutable $enviadoEn = null,
    ) {
    }

    /** @param array<string, mixed> $f fila de SYS_EMAIL_OUTBOX */
    public static function desdeFila(array $f): self
    {
        $lista = static function (mixed $json): ?array {
            if (null === $json || '' === $json) {
                return null;
            }
            $valor = json_decode((string) $json, true);

            return \is_array($valor) ? array_values(array_map('strval', $valor)) : null;
        };

        return new self(
            id: (string) $f['EOB_Id'],
            plantilla: (string) $f['EOB_Plantilla'],
            asunto: (string) $f['EOB_Asunto'],
            html: (string) $f['EOB_Html'],
            para: $lista($f['EOB_Para']) ?? [],
            afectados: $lista($f['EOB_Afectados']),
            copiarAdmins: 1 === (int) $f['EOB_Cc_Admins'],
            resolver: 1 === (int) $f['EOB_Resolver'],
            responderA: null === $f['EOB_Reply_To'] ? null : (string) $f['EOB_Reply_To'],
            estado: (string) $f['EOB_Estado'],
            intentos: (int) $f['EOB_Intentos'],
            proximoIntento: Reloj::desdeBd((string) $f['EOB_Proximo_Intento']) ?? Reloj::ahora(),
            ultimoError: null === $f['EOB_Ultimo_Error'] ? null : (string) $f['EOB_Ultimo_Error'],
        );
    }

    /**
     * Destinatarios resueltos: se fijan para que los reintentos no vuelvan a
     * consultar reglas ni el directorio.
     *
     * @param list<string> $para
     */
    public function fijarDestinatarios(array $para): void
    {
        $this->para = $para;
        $this->resolver = false;
    }

    public function marcarEnviado(?string $nota = null): void
    {
        $this->estado = self::ENVIADO;
        $this->enviadoEn = Reloj::ahora();
        $this->ultimoError = $nota;
    }

    /** Credenciales rechazadas: no es culpa del correo, no consume intento; espera la pausa. */
    public function retener(string $error, int $minutos): void
    {
        $this->intentos = max(0, $this->intentos - 1);
        $this->ultimoError = $error;
        $this->proximoIntento = Reloj::ahora()->modify("+{$minutos} minutes");
    }

    /**
     * Fallo de entrega: se reprograma con espera creciente o, agotados los
     * intentos, queda FALLIDO (reintentable desde la API).
     *
     * @param list<int> $esperas segundos de espera por intento
     *
     * @return bool true si quedó FALLIDO
     */
    public function fallar(string $error, int $maxIntentos, array $esperas): bool
    {
        $this->ultimoError = $error;
        if ($this->intentos >= $maxIntentos) {
            $this->estado = self::FALLIDO;

            return true;
        }
        $espera = $esperas[min(max($this->intentos - 1, 0), \count($esperas) - 1)];
        $this->proximoIntento = Reloj::ahora()->modify("+{$espera} seconds");

        return false;
    }
}
