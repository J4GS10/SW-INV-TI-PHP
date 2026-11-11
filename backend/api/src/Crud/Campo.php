<?php

declare(strict_types=1);

namespace App\Crud;

/**
 * Columna de una entidad: su tipo gobierna la validación de la entrada (como
 * los esquemas del backend anterior) y la conversión de la salida JSON.
 *
 * Tipos: texto | entero | decimal | logico | fecha | fechahora | uuid | correo
 * - decimal: se devuelve como texto con su escala ("1500.00"), igual que antes.
 * - correo: correo corporativo (admite dominios internos .local); en minúsculas.
 */
final readonly class Campo
{
    public const string TEXTO = 'texto';
    public const string ENTERO = 'entero';
    public const string DECIMAL = 'decimal';
    public const string LOGICO = 'logico';
    public const string FECHA = 'fecha';
    public const string FECHAHORA = 'fechahora';
    public const string UUID = 'uuid';
    public const string CORREO = 'correo';

    /**
     * @param bool        $requerido en la creación
     * @param bool        $editable  admitido en la actualización (PATCH)
     * @param bool        $entrada   aceptado en la entrada (false = solo de salida)
     * @param list<string>|null $opciones valores permitidos (texto)
     */
    public function __construct(
        public string $nombre,
        public string $tipo = self::TEXTO,
        public bool $requerido = false,
        public ?int $min = null,
        public ?int $max = null,
        public bool $editable = true,
        public bool $entrada = true,
        public mixed $defecto = null,
        public ?array $opciones = null,
        public int $escala = 2,
        public ?float $minimo = null,
        public ?float $maximo = null,
        /** Columna en la base si difiere del nombre en la API (p. ej. CREATED_AT → created_at). */
        public ?string $columna = null,
        /** Expresión (Unicode, sin delimitadores) que el texto debe cumplir completa. */
        public ?string $patron = null,
        /** Fecha de un hecho ya ocurrido (compra, recepción): no se admite una futura. */
        public bool $noFutura = false,
    ) {
    }

    /** Nombres de persona: letras de cualquier idioma, espacios, punto, apóstrofo y guion. */
    public const string NOMBRE_PERSONA = "^[\\p{L}\\p{M}][\\p{L}\\p{M} .'’-]*$";
    /** Series, códigos y nombres de equipo: letras, cifras y los signos habituales de una etiqueta. */
    public const string IDENTIFICADOR = '^[\p{L}\p{N}][\p{L}\p{N} ._\/#+:()-]*$';

    public static function nombrePersona(string $nombre, bool $requerido = false, ?int $min = null, ?int $max = null): self
    {
        return new self($nombre, self::TEXTO, $requerido, $min, $max, patron: self::NOMBRE_PERSONA);
    }

    public static function identificador(string $nombre, bool $requerido = false, ?int $min = null, ?int $max = null): self
    {
        return new self($nombre, self::TEXTO, $requerido, $min, $max, patron: self::IDENTIFICADOR);
    }

    public function columnaBd(): string
    {
        return $this->columna ?? $this->nombre;
    }

    public static function texto(string $nombre, bool $requerido = false, ?int $min = null, ?int $max = null, bool $editable = true, mixed $defecto = null): self
    {
        return new self($nombre, self::TEXTO, $requerido, $min, $max, $editable, defecto: $defecto);
    }

    public static function entero(string $nombre, bool $requerido = false, bool $editable = true, ?float $minimo = null, ?float $maximo = null, mixed $defecto = null): self
    {
        return new self($nombre, self::ENTERO, $requerido, editable: $editable, defecto: $defecto, minimo: $minimo, maximo: $maximo);
    }

    public static function logico(string $nombre, mixed $defecto = null, bool $editable = true): self
    {
        return new self($nombre, self::LOGICO, editable: $editable, defecto: $defecto);
    }

    public static function salida(string $nombre, string $tipo = self::TEXTO, ?string $columna = null): self
    {
        return new self($nombre, $tipo, editable: false, entrada: false, columna: $columna);
    }
}
