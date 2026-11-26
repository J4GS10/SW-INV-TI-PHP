<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Operator code: the 6 digits a technician types on the scanner to authorize a change, so the change is
 * recorded under their name even on a shared device. Only roles that operate equipment may have one.
 */
final class CodigoOperador
{
    public const int LONGITUD = 6;
    public const int INTENTOS_MAXIMOS = 5;
    public const int MINUTOS_BLOQUEO = 15;
    /** Roles that may own a code: those allowed to assign, return or transfer equipment. */
    public const array ROLES = Roles::OPERATIVO;

    /** Codes anyone would try first. */
    private const array OBVIOS = ['123123', '121212', '112233', '111222', '123321', '159753', '147258', '258369', '102030', '010203'];

    /**
     * Why the code cannot be used, or null if it can.
     *
     * @return 'OPERATOR_CODE_FORMAT'|'OPERATOR_CODE_TOO_WEAK'|null
     */
    public static function problema(string $codigo): ?string
    {
        if (1 !== preg_match('/^\d{'.self::LONGITUD.'}$/', $codigo)) {
            return 'OPERATOR_CODE_FORMAT';
        }
        if (1 === preg_match('/^(\d)\1+$/', $codigo) || \in_array($codigo, self::OBVIOS, true) || self::esSecuencia($codigo)) {
            return 'OPERATOR_CODE_TOO_WEAK';
        }

        return null;
    }

    public static function rolPuedeTener(string $rol): bool
    {
        return \in_array($rol, self::ROLES, true);
    }

    /** 012345, 123456, 987654… (each digit one more, or one less, than the previous). */
    private static function esSecuencia(string $codigo): bool
    {
        foreach ([1, -1] as $paso) {
            $ok = true;
            for ($i = 1; $i < \strlen($codigo); ++$i) {
                if ((int) $codigo[$i] - (int) $codigo[$i - 1] !== $paso) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
