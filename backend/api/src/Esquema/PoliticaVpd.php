<?php

declare(strict_types=1);

namespace App\Esquema;

/**
 * Una política DBMS_RLS de la capa de seguridad: tabla, operación (función de
 * INV_VPD que la evalúa), tipos de sentencia y predicado (que se guarda en
 * INV_VPD_PREDICADO y la función lee en cada ejecución).
 */
final readonly class PoliticaVpd
{
    public const string PREFIJO = 'INV_RLS_';
    public const string LECTURA = 'LECTURA';
    public const string ESCRITURA = 'ESCRITURA';
    public const string INSERCION = 'INSERCION';
    private const array SENTENCIAS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    /** @param list<string> $sentencias */
    public function __construct(
        public string $tabla,
        public string $operacion,
        public array $sentencias,
        public string $predicado,
    ) {
        if (!\in_array($operacion, [self::LECTURA, self::ESCRITURA, self::INSERCION], true)) {
            throw new \InvalidArgumentException("Operación VPD desconocida: {$operacion}");
        }
        if ([] === $sentencias || [] !== array_diff($sentencias, self::SENTENCIAS)) {
            throw new \InvalidArgumentException('Tipos de sentencia VPD inválidos.');
        }
    }

    public function nombre(): string
    {
        return self::PREFIJO.$this->operacion;
    }

    public function funcion(): string
    {
        return "INV_VPD.{$this->operacion}";
    }

    public function tiposSentencia(): string
    {
        return implode(',', $this->sentencias);
    }

    /**
     * Banderas de USER_POLICIES (SEL, INS, UPD, DEL) que corresponden a la política.
     *
     * @return array{SEL: string, INS: string, UPD: string, DEL: string}
     */
    public function banderas(): array
    {
        $si = fn (string $s): string => \in_array($s, $this->sentencias, true) ? 'YES' : 'NO';

        return ['SEL' => $si('SELECT'), 'INS' => $si('INSERT'), 'UPD' => $si('UPDATE'), 'DEL' => $si('DELETE')];
    }
}
