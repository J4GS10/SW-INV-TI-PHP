<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Fechas del esquema: TIMESTAMP(6) en UTC sin zona. La sesión de Oracle usa
 * NLS_TIMESTAMP_FORMAT 'YYYY-MM-DD HH24:MI:SS.FF6' (App\Db\SesionOracle), así
 * que se leen y se vinculan como texto con microsegundos.
 */
final class Reloj
{
    public const string FORMATO_BD = 'Y-m-d H:i:s.u';

    public static function ahora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function aBd(?\DateTimeImmutable $fecha): ?string
    {
        return $fecha?->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMATO_BD);
    }

    public static function desdeBd(?string $valor): ?\DateTimeImmutable
    {
        if (null === $valor || '' === $valor) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        // Oracle devuelve hasta 6 decimales; sin fracción en columnas DATE.
        $fecha = \DateTimeImmutable::createFromFormat(self::FORMATO_BD, $valor, $utc)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $valor, $utc);
        if (false === $fecha) {
            throw new \UnexpectedValueException("Fecha de Oracle no reconocida: {$valor}");
        }

        return $fecha;
    }

    /** ISO 8601 sin zona, como `datetime.isoformat()` del backend anterior. */
    public static function iso(?\DateTimeImmutable $fecha): ?string
    {
        if (null === $fecha) {
            return null;
        }
        $f = $fecha->setTimezone(new \DateTimeZone('UTC'));

        return '000000' === $f->format('u') ? $f->format('Y-m-d\TH:i:s') : $f->format('Y-m-d\TH:i:s.u');
    }
}
