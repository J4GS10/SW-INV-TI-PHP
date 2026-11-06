<?php

declare(strict_types=1);

namespace App\Esquema;

/**
 * Paquete, cuerpo de paquete o disparador que la capa de seguridad debe tener
 * en el esquema. `ddl` es la sentencia completa (CREATE OR REPLACE …); la
 * fuente que Oracle guarda en USER_SOURCE es la misma sin ese prefijo.
 */
final readonly class ObjetoPlsql
{
    public const string PAQUETE = 'PACKAGE';
    public const string CUERPO = 'PACKAGE BODY';
    public const string DISPARADOR = 'TRIGGER';

    public function __construct(
        public string $tipo,
        public string $nombre,
        public string $ddl,
    ) {
        if (!\in_array($tipo, [self::PAQUETE, self::CUERPO, self::DISPARADOR], true)) {
            throw new \InvalidArgumentException("Tipo de objeto PL/SQL no admitido: {$tipo}");
        }
        if (1 !== preg_match('/^[A-Z][A-Z0-9_]{0,127}$/', $nombre)) {
            throw new \InvalidArgumentException("Nombre de objeto inválido: {$nombre}");
        }
    }

    /** Fuente esperada en USER_SOURCE (sin «CREATE OR REPLACE »). */
    public function fuente(): string
    {
        return (string) preg_replace('/^\s*CREATE\s+OR\s+REPLACE\s+/i', '', $this->ddl);
    }

    /** ¿La fuente instalada equivale a la esperada? (sin comentarios ni diferencias de espacios) */
    public function equivaleA(?string $fuenteInstalada): bool
    {
        return null !== $fuenteInstalada && FuentePlsql::normalizar($fuenteInstalada) === FuentePlsql::normalizar($this->fuente());
    }

    public function etiqueta(): string
    {
        return "{$this->tipo} {$this->nombre}";
    }
}
