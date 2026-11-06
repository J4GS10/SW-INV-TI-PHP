<?php

declare(strict_types=1);

namespace App\Db;

use Doctrine\DBAL\Exception\DriverException;

/** Clasificación de errores ORA-NNNNN (mismos conjuntos que el backend anterior). */
final class ErroresOracle
{
    /** Valor rechazado por el motor (texto largo, número fuera de rango, fecha inválida). */
    private const array DATOS = [12899, 1438, 1426, 1722, 1830, 1839, 1840, 1841, 1843, 1847, 1858, 1861];
    /** Política VPD (ORA-28115) o disparadores de seguridad (RAISE_APPLICATION_ERROR -2000x). */
    private const array ALCANCE = [28115, 20001, 20002, 20003, 20004, 20005];
    /** Restricciones de integridad: única, FK hija/padre, NOT NULL, CHECK. */
    private const array INTEGRIDAD = [1, 2291, 2292, 1400, 1407, 2290];

    public static function codigo(\Throwable $e): ?int
    {
        for ($actual = $e; null !== $actual; $actual = $actual->getPrevious()) {
            if ($actual instanceof DriverException && $actual->getCode() > 0) {
                return $actual->getCode();
            }
            if (1 === preg_match('/ORA-(\d{5})/', $actual->getMessage(), $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    public static function esDeDatos(?int $codigo): bool
    {
        return \in_array($codigo, self::DATOS, true);
    }

    public static function esFueraDeAlcance(?int $codigo): bool
    {
        return \in_array($codigo, self::ALCANCE, true);
    }

    public static function esDeIntegridad(?int $codigo): bool
    {
        return \in_array($codigo, self::INTEGRIDAD, true);
    }
}
