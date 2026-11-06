<?php

declare(strict_types=1);

namespace App\Inventario;

use Doctrine\DBAL\Connection;

/** Códigos correlativos (LPT00001, OC-2026-0001…) en SYS_SECUENCIA, seguros ante altas concurrentes. */
final readonly class Secuencias
{
    public function __construct(private Connection $conexion)
    {
    }

    /**
     * Siguiente código del contexto. La fila queda bloqueada (FOR UPDATE) hasta
     * el commit de la transacción en curso: dos altas no reciben el mismo número.
     */
    public function siguiente(string $contexto, string $prefijo, int $relleno = 5): string
    {
        $this->crearSiFalta($contexto, $relleno);
        $fila = $this->conexion->fetchAssociative(
            'SELECT "SEC_Ultimo_Numero", "SEC_Relleno" FROM "SYS_SECUENCIA" WHERE "SEC_Contexto" = :c FOR UPDATE', ['c' => $contexto]);
        $numero = (int) $fila['SEC_Ultimo_Numero'] + 1;
        $this->conexion->executeStatement('UPDATE "SYS_SECUENCIA" SET "SEC_Ultimo_Numero" = :n WHERE "SEC_Contexto" = :c',
            ['n' => $numero, 'c' => $contexto]);

        return $prefijo.str_pad((string) $numero, (int) $fila['SEC_Relleno'], '0', \STR_PAD_LEFT);
    }

    /**
     * Lleva la secuencia al menos hasta `$numero` (códigos cargados a mano o
     * importados): el próximo código automático no choca con ellos. Nunca la baja.
     */
    public function asegurarMinimo(string $contexto, int $numero, int $relleno = 5): void
    {
        $this->crearSiFalta($contexto, $relleno);
        $this->conexion->executeStatement('UPDATE "SYS_SECUENCIA" SET "SEC_Ultimo_Numero" = :n WHERE "SEC_Contexto" = :c AND "SEC_Ultimo_Numero" < :n',
            ['n' => $numero, 'c' => $contexto]);
    }

    /**
     * Dos altas simultáneas del primer código de un contexto pueden intentar crear
     * la fila a la vez: la segunda choca con el índice único y se ignora (en Oracle
     * solo se revierte esa sentencia, no la transacción).
     */
    private function crearSiFalta(string $contexto, int $relleno): void
    {
        try {
            $this->conexion->executeStatement(
                'MERGE INTO "SYS_SECUENCIA" t USING (SELECT :c AS contexto FROM DUAL) s ON (t."SEC_Contexto" = s.contexto) '
                .'WHEN NOT MATCHED THEN INSERT ("SEC_Contexto", "SEC_Ultimo_Numero", "SEC_Relleno") VALUES (:c, 0, :r)',
                ['c' => $contexto, 'r' => $relleno],
            );
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Otra petición la creó mientras tanto.
        }
    }
}
