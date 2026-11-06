<?php

declare(strict_types=1);

namespace App\Notificaciones;

/**
 * A quién se envía el correo de un evento:
 *   afectado  → las personas involucradas en la gestión;
 *   jefe      → el jefe inmediato de cada afectado (PER_Jefe, desde AD);
 *   admins    → NOTIFY_ADMIN_EMAILS + miembros del grupo de administradores de AD;
 *   grupos_ad → miembros (transitivos) de grupos de AD;
 *   extra     → correos fijos.
 */
final readonly class ReglaNotificacion
{
    /**
     * @param list<string> $gruposAd
     * @param list<string> $correosExtra
     */
    public function __construct(
        public EventoNotificacion $evento,
        public bool $activa,
        public bool $notificarAfectado,
        public bool $notificarJefe,
        public bool $copiarAdmins,
        public array $gruposAd = [],
        public array $correosExtra = [],
        public bool $personalizada = false,
    ) {
    }

    /** @param array<string, mixed> $fila fila de INV_REGLA_NOTIFICACION */
    public static function desdeFila(EventoNotificacion $evento, array $fila): self
    {
        return new self(
            $evento,
            activa: 1 === (int) $fila['RNO_Activa'],
            notificarAfectado: 1 === (int) $fila['RNO_Notificar_Afectado'],
            notificarJefe: 1 === (int) $fila['RNO_Notificar_Jefe'],
            copiarAdmins: 1 === (int) $fila['RNO_Copiar_Admins'],
            gruposAd: self::separar($fila['RNO_Grupos_AD']),
            correosExtra: array_map('strtolower', self::separar($fila['RNO_Correos_Extra'])),
            personalizada: true,
        );
    }

    /**
     * Lista guardada como texto separado por comas (sin vacíos ni repetidos).
     *
     * @param iterable<string> $valores
     */
    public static function unir(iterable $valores): ?string
    {
        $limpios = [];
        foreach ($valores as $v) {
            $v = trim((string) $v);
            if ('' !== $v && !\in_array($v, $limpios, true)) {
                $limpios[] = $v;
            }
        }

        return [] === $limpios ? null : implode(',', $limpios);
    }

    /** @return array<string, mixed> mismo orden de campos que el backend anterior */
    public function aApi(): array
    {
        return [
            'activa' => $this->activa,
            'notificar_afectado' => $this->notificarAfectado,
            'notificar_jefe' => $this->notificarJefe,
            'copiar_admins' => $this->copiarAdmins,
            'grupos_ad' => $this->gruposAd,
            'correos_extra' => $this->correosExtra,
            'evento' => $this->evento->value,
            'personalizada' => $this->personalizada,
        ];
    }

    /** @return list<string> */
    private static function separar(mixed $valor): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $valor)), static fn (string $v): bool => '' !== $v));
    }
}
