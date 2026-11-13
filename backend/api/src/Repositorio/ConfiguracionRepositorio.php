<?php

declare(strict_types=1);

namespace App\Repositorio;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

/** The global configuration of the organization (SYS_CONFIGURACION): a single row, id 1, created empty the first time it is needed. */
final readonly class ConfiguracionRepositorio
{
    private const array COLUMNAS = ['SYS_Configuracion', 'SYS_Nombre_Empresa', 'SYS_Logo_URL', 'SYS_Color_Primario',
        'SYS_Color_Secundario', 'SYS_Color_Fondo', 'SYS_Idioma_Defecto', 'SYS_Codigo_Formulario', 'SYS_Ciudad', 'SYS_Logo_Tamano'];

    public function __construct(private Connection $conexion)
    {
    }

    /**
     * Reading does not write: the row is only created when it is missing.
     *
     * @return array<string, mixed>
     */
    public function leer(): array
    {
        $fila = $this->fila();
        if (false === $fila) {
            $this->asegurar();
            $fila = $this->fila();
        }

        return [...(false === $fila ? [] : $fila), 'SYS_Configuracion' => 1];
    }

    /** @param array<string, mixed> $cambios column => value; the column names come from the validated form, never raw from the request */
    public function guardar(array $cambios): void
    {
        $this->asegurar();
        if ([] === $cambios) {
            return;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "\"{$c}\" = :{$c}", array_keys($cambios)));
        $this->conexion->executeStatement('UPDATE "SYS_CONFIGURACION" SET '.$sets.' WHERE "SYS_Configuracion" = 1', $cambios);
    }

    /** @param string $png the image bytes */
    public function guardarLogo(string $png, string $url): void
    {
        $this->asegurar();
        $this->conexion->executeStatement('UPDATE "SYS_CONFIGURACION" SET "SYS_Logo_Image" = :i, "SYS_Logo_URL" = :u WHERE "SYS_Configuracion" = 1',
            ['i' => $png, 'u' => $url], ['i' => ParameterType::LARGE_OBJECT]);
    }

    /** @return array<string, mixed>|false */
    private function fila(): array|false
    {
        return $this->conexion->fetchAssociative('SELECT "'.implode('", "', self::COLUMNAS).'" FROM "SYS_CONFIGURACION" WHERE "SYS_Configuracion" = 1');
    }

    private function asegurar(): void
    {
        try {
            $this->conexion->executeStatement('MERGE INTO "SYS_CONFIGURACION" t USING (SELECT 1 AS id FROM DUAL) s '
                .'ON (t."SYS_Configuracion" = s.id) WHEN NOT MATCHED THEN INSERT ("SYS_Configuracion") VALUES (1)');
        } catch (UniqueConstraintViolationException) {
            // Another request created it at the same time.
        }
    }
}
