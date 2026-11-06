<?php

declare(strict_types=1);

namespace App\Http;

use App\Crud\Campo;
use App\Dominio\Ident;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lectura y validación del cuerpo JSON de una petición. Acumula todos los
 * errores y responde 422 VALIDATION_ERROR con el mismo formato de errores que
 * el backend anterior (type, loc, msg, input y ctx).
 *
 *   $e = Entrada::json($request);
 *   $codigo = $e->texto('code', min: 4, max: 10);
 *   $e->validar();
 */
final class Entrada
{
    /** @var list<array<string, mixed>> */
    private array $errores = [];

    /**
     * @param array<string, mixed> $datos
     * @param string               $ubicacion 'body' | 'query' | 'path' (primer elemento de `loc` en los errores)
     * @param list<string>         $ruta      sub-objeto dentro de la ubicación (p. ej. ["config"])
     */
    private function __construct(private readonly array $datos, private readonly string $ubicacion = 'body', private readonly array $ruta = [])
    {
    }

    /** Parámetros de la URL (?a=1&b=2). Los valores llegan como texto. */
    public static function consulta(Request $request): self
    {
        return new self($request->query->all(), 'query');
    }

    /** Parámetros de la ruta (/recurso/{id}). */
    public static function ruta(Request $request): self
    {
        return new self((array) $request->attributes->get('_route_params', []), 'path');
    }

    /** Parámetro de ruta entero (p. ej. {id}); 422 si no lo es. */
    public static function enteroDeRuta(Request $request, string $nombre = 'id'): int
    {
        $e = self::ruta($request);
        [, $valor] = $e->campo(\App\Crud\Campo::entero($nombre, requerido: true), true);
        $e->validar();

        return (int) $valor;
    }

    /** Parámetro de ruta UUID (hexadecimal para la base); 422 si no lo es. */
    public static function uuidDeRuta(Request $request, string $nombre = 'id'): string
    {
        $e = self::ruta($request);
        [, $valor] = $e->campo(new \App\Crud\Campo($nombre, \App\Crud\Campo::UUID, true), true);
        $e->validar();

        return Ident::aHex((string) $valor);
    }

    /**
     * Paginación skip/limit con cota superior fija (anti-abuso).
     *
     * @return array{0: int, 1: int} [saltar, límite]
     */
    public static function paginacion(Request $request, int $maximo = 200, int $defecto = 50): array
    {
        $e = self::consulta($request);
        [$hay, $saltar] = $e->campo(\App\Crud\Campo::entero('skip', minimo: 0), false);
        [$hayLimite, $limite] = $e->campo(\App\Crud\Campo::entero('limit', minimo: 1, maximo: $maximo), false);
        $e->validar();

        return [$hay && null !== $saltar ? (int) $saltar : 0, $hayLimite && null !== $limite ? (int) $limite : $defecto];
    }

    /** Cuerpo JSON (objeto). Sin cuerpo = objeto vacío; JSON inválido = 422. */
    public static function json(Request $request): self
    {
        $contenido = trim($request->getContent());
        if ('' === $contenido) {
            return new self([]);
        }
        try {
            $datos = json_decode($contenido, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ErrorValidacion([['type' => 'json_invalid', 'loc' => ['body', 0], 'msg' => 'JSON decode error',
                'input' => [], 'ctx' => ['error' => $e->getMessage()]]]);
        }
        // «[]» y «{}» se decodifican igual: la lista vacía se distingue por el texto.
        if (!\is_array($datos) || array_is_list($datos) && ([] !== $datos || str_starts_with($contenido, '['))) {
            throw new ErrorValidacion([['type' => 'model_attributes_type', 'loc' => ['body'],
                'msg' => 'Input should be a valid dictionary or object to extract fields from', 'input' => $datos]]);
        }

        return new self($datos);
    }

    /** @param array<string, mixed> $datos */
    public static function de(array $datos): self
    {
        return new self($datos);
    }

    public function tiene(string $campo): bool
    {
        return \array_key_exists($campo, $this->datos) && null !== $this->datos[$campo];
    }

    /** Texto obligatorio (o null si `opcional` y no viene). */
    public function texto(string $campo, int $min = 0, ?int $max = null, bool $opcional = false): ?string
    {
        if (!$this->tiene($campo)) {
            if (!$opcional) {
                $this->error('missing', $campo, 'Field required', null);
            }

            return null;
        }
        $valor = $this->datos[$campo];
        if (!\is_string($valor)) {
            $this->error('string_type', $campo, 'Input should be a valid string', $valor);

            return null;
        }
        $largo = mb_strlen($valor);
        if ($largo < $min) {
            $this->error('string_too_short', $campo, "String should have at least {$min} character".(1 === $min ? '' : 's'),
                $valor, ['min_length' => $min]);

            return null;
        }
        if (null !== $max && $largo > $max) {
            $this->error('string_too_long', $campo, "String should have at most {$max} character".(1 === $max ? '' : 's'),
                $valor, ['max_length' => $max]);

            return null;
        }

        return $valor;
    }

    /** ¿Vino el campo, aunque sea null? (null explícito = vaciar la columna). */
    public function presente(string $campo): bool
    {
        return \array_key_exists($campo, $this->datos);
    }

    /** Idioma de un documento (`lang`: es, en o it; español si no viene). */
    public function idioma(): \App\Documentos\Idioma
    {
        return \App\Documentos\Idioma::desde($this->patron('lang', \App\Documentos\Idioma::PATRON));
    }

    /** Texto opcional que, si viene, debe cumplir la expresión (como `Field(pattern=…)`). */
    public function patron(string $campo, string $patron, ?int $max = null): ?string
    {
        $valor = $this->texto($campo, max: $max, opcional: true);
        if (null !== $valor && 1 !== preg_match('/'.$patron.'/', $valor)) {
            $this->error('string_pattern_mismatch', $campo, "String should match pattern '{$patron}'", $valor, ['pattern' => $patron]);

            return null;
        }

        return $valor;
    }

    /** Moneda admitida (GTQ, USD, CHF), en mayúsculas; acepta minúsculas como el backend anterior. */
    public function moneda(string $campo): ?string
    {
        $valor = $this->texto($campo, opcional: true);
        if (null === $valor) {
            return null;
        }
        $codigo = strtoupper(trim($valor));
        if (!\in_array($codigo, ['GTQ', 'USD', 'CHF'], true)) {
            $this->errorDeValor($campo, 'UNSUPPORTED_CURRENCY', $valor);

            return null;
        }

        return $codigo;
    }

    /**
     * Lista de enteros (ids).
     *
     * @return list<int>|null
     */
    public function enteros(string $campo, int $max): ?array
    {
        $lista = $this->lista($campo, $max);
        if (null === $lista) {
            return null;
        }
        foreach ($lista as $i => $v) {
            if (!\is_int($v) && !(\is_string($v) && 1 === preg_match('/^-?\d{1,18}$/', $v))) {
                $this->errores[] = ['type' => 'int_parsing', 'loc' => [$this->ubicacion, ...$this->ruta, $campo, $i],
                    'msg' => 'Input should be a valid integer, unable to parse string as an integer', 'input' => $v];

                return null;
            }
        }

        return array_map('intval', $lista);
    }

    /**
     * Lista de textos (sin recortar).
     *
     * @return list<string>|null
     */
    public function textos(string $campo, int $max): ?array
    {
        $lista = $this->lista($campo, $max);
        foreach ($lista ?? [] as $i => $v) {
            if (!\is_string($v)) {
                $this->errores[] = ['type' => 'string_type', 'loc' => [$this->ubicacion, ...$this->ruta, $campo, $i], 'msg' => 'Input should be a valid string', 'input' => $v];

                return null;
            }
        }

        return $lista;
    }

    /**
     * Lista de UUID (en hex de la base).
     *
     * @return list<string>|null
     */
    public function uuids(string $campo, int $max, int $min = 0): ?array
    {
        $lista = $this->lista($campo, $max);
        if (null === $lista) {
            if ($min > 0) {
                $this->error('missing', $campo, 'Field required', null);
            }

            return null;
        }
        if (\count($lista) < $min) {
            $this->error('too_short', $campo, "List should have at least {$min} item".(1 === $min ? '' : 's').' after validation, not '.\count($lista),
                $lista, ['field_type' => 'List', 'min_length' => $min, 'actual_length' => \count($lista)]);

            return null;
        }
        foreach ($lista as $i => $v) {
            if (!\is_string($v) || !Ident::esValido($v)) {
                $this->errores[] = ['type' => 'uuid_parsing', 'loc' => [$this->ubicacion, ...$this->ruta, $campo, $i], 'msg' => 'Input should be a valid UUID', 'input' => $v];

                return null;
            }
        }

        return array_map(static fn (string $v): string => Ident::aHex($v), $lista);
    }

    /** Sub-objeto del cuerpo como otra Entrada (errores con su ubicación). */
    public function objeto(string $campo, bool $requerido = false): ?self
    {
        if (!$this->tiene($campo)) {
            if ($requerido) {
                $this->error('missing', $campo, 'Field required', null);
            }

            return null;
        }
        $v = $this->datos[$campo];
        if (!\is_array($v) || (array_is_list($v) && [] !== $v)) {
            $this->error('model_attributes_type', $campo, 'Input should be a valid dictionary or object to extract fields from', $v);

            return null;
        }

        return new self($v, $this->ubicacion, [...$this->ruta, $campo]);
    }

    /** Errores de otra Entrada (sub-objeto) se suman a esta. */
    public function absorber(self $otra): void
    {
        array_push($this->errores, ...$otra->errores);
        $otra->errores = [];
    }

    /** Valor crudo de un campo (para validaciones a medida). */
    public function crudo(string $campo): mixed
    {
        return $this->datos[$campo] ?? null;
    }

    public function logico(string $campo): ?bool
    {
        if (!$this->tiene($campo)) {
            return null;
        }
        $valor = $this->datos[$campo];
        if (\is_bool($valor)) {
            return $valor;
        }
        $this->error('bool_type', $campo, 'Input should be a valid boolean', $valor);

        return null;
    }

    /**
     * @param list<string> $permitidos
     */
    public function opcion(string $campo, array $permitidos): ?string
    {
        if (!$this->tiene($campo)) {
            return null;
        }
        $valor = $this->datos[$campo];
        if (\is_string($valor) && \in_array($valor, $permitidos, true)) {
            return $valor;
        }
        $esperado = self::literales($permitidos);
        $this->error('literal_error', $campo, "Input should be {$esperado}", $valor, ['expected' => $esperado]);

        return null;
    }

    /**
     * Valores permitidos como los enumera Pydantic: 'A' · 'A' or 'B' · 'A', 'B' or 'C'.
     *
     * @param list<string> $permitidos
     */
    public static function literales(array $permitidos): string
    {
        $citados = array_map(static fn (string $p): string => "'{$p}'", $permitidos);
        $ultimo = array_pop($citados);

        return [] === $citados ? (string) $ultimo : implode(', ', $citados).' or '.$ultimo;
    }

    /** @return list<mixed>|null */
    public function lista(string $campo, int $max): ?array
    {
        if (!$this->tiene($campo)) {
            return null;
        }
        $valor = $this->datos[$campo];
        if (!\is_array($valor) || !array_is_list($valor)) {
            $this->error('list_type', $campo, 'Input should be a valid list', $valor);

            return null;
        }
        if (\count($valor) > $max) {
            $this->error('too_long', $campo, "List should have at most {$max} items after validation, not ".\count($valor),
                $valor, ['field_type' => 'List', 'max_length' => $max, 'actual_length' => \count($valor)]);

            return null;
        }

        return $valor;
    }

    /**
     * Valor de un campo según su definición. Los textos se recortan (como el
     * esquema base del backend anterior) antes de validar su longitud.
     *
     * @return array{0: bool, 1: mixed} [¿vino en la entrada?, valor validado]
     */
    public function campo(Campo $c, bool $creacion): array
    {
        if (!\array_key_exists($c->nombre, $this->datos)) {
            if ($creacion && $c->requerido) {
                $this->error('missing', $c->nombre, 'Field required', null);
            }

            return [false, null];
        }
        $valor = $this->datos[$c->nombre];
        if (null === $valor) {
            if ($creacion && $c->requerido) {
                $this->error($this->tipoError($c), $c->nombre, $this->mensajeTipo($c), null);
            }

            return [true, null];
        }
        $resultado = match ($c->tipo) {
            Campo::TEXTO => $this->validarTexto($c, $valor),
            Campo::ENTERO => $this->validarNumero($c, $valor, true),
            Campo::DECIMAL => $this->validarNumero($c, $valor, false),
            Campo::LOGICO => $this->validarLogico($c, $valor),
            Campo::FECHA => $this->validarFecha($c, $valor),
            Campo::FECHAHORA => $this->validarFechaHora($c, $valor),
            Campo::UUID => \is_string($valor) && Ident::esValido($valor) ? Ident::aTexto(Ident::aHex($valor))
                : $this->fallo('uuid_parsing', $c, 'Input should be a valid UUID', $valor),
            Campo::CORREO => $this->validarCorreo($c, $valor),
            default => throw new \LogicException("Tipo de campo desconocido: {$c->tipo}"),
        };

        return [true, $resultado];
    }

    /**
     * Error de tipo de un campo con validación a medida (p. ej. null en un campo no opcional).
     *
     * @param array<string, mixed>|null $ctx
     */
    public function errorDeTipo(string $tipo, string $campo, string $mensaje, mixed $valor, ?array $ctx = null): void
    {
        $this->error($tipo, $campo, $mensaje, $valor, $ctx);
    }

    /** ¿Se acumuló algún error? */
    public function conErrores(): bool
    {
        return [] !== $this->errores;
    }

    /** Error de una regla propia del dominio ("Value error, <CÓDIGO>"). */
    public function errorDeValor(string $campo, string $codigo, mixed $valor): void
    {
        $this->error('value_error', $campo, "Value error, {$codigo}", $valor, ['error' => $codigo]);
    }

    /**
     * Regla del modelo completo de esta entrada (`model_validator`): `loc` es la
     * del objeto (["body"] o ["body", "config"]) e `input`, el objeto recibido.
     */
    public function errorDeModelo(string $codigo): void
    {
        $this->errores[] = ['type' => 'value_error', 'loc' => [$this->ubicacion, ...$this->ruta], 'msg' => "Value error, {$codigo}",
            'input' => $this->datos, 'ctx' => ['error' => $codigo]];
    }

    /** Lanza 422 si hubo errores. */
    public function validar(): void
    {
        if ([] !== $this->errores) {
            throw new ErrorValidacion($this->errores);
        }
    }

    private function validarTexto(Campo $c, mixed $valor): ?string
    {
        if (!\is_string($valor)) {
            return $this->fallo('string_type', $c, 'Input should be a valid string', $valor);
        }
        $valor = trim($valor);
        $largo = mb_strlen($valor);
        if (null !== $c->min && $largo < $c->min) {
            $this->error('string_too_short', $c->nombre, "String should have at least {$c->min} character".(1 === $c->min ? '' : 's'),
                $valor, ['min_length' => $c->min]);

            return null;
        }
        if (null !== $c->max && $largo > $c->max) {
            $this->error('string_too_long', $c->nombre, "String should have at most {$c->max} character".(1 === $c->max ? '' : 's'),
                $valor, ['max_length' => $c->max]);

            return null;
        }
        if (null !== $c->opciones && !\in_array($valor, $c->opciones, true)) {
            $esperado = self::literales($c->opciones);
            $this->error('literal_error', $c->nombre, "Input should be {$esperado}", $valor, ['expected' => $esperado]);

            return null;
        }

        return $valor;
    }

    private function validarNumero(Campo $c, mixed $valor, bool $entero): int|string|null
    {
        $texto = match (true) {
            \is_int($valor) => (string) $valor,
            \is_float($valor) && is_finite($valor) => $entero && floor($valor) === $valor ? (string) (int) $valor : rtrim(rtrim(\sprintf('%.10F', $valor), '0'), '.'),
            \is_string($valor) => trim($valor),
            default => null,
        };
        $patron = $entero ? '/^[+-]?\d{1,18}$/' : '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';
        if (null === $texto || 1 !== preg_match($patron, $texto)) {
            return $this->fallo($entero ? 'int_parsing' : 'decimal_parsing', $c,
                $entero ? 'Input should be a valid integer, unable to parse string as an integer' : 'Input should be a valid decimal', $valor);
        }
        $numero = (float) $texto;
        if (null !== $c->minimo && $numero < $c->minimo) {
            $this->error('greater_than_equal', $c->nombre, "Input should be greater than or equal to {$c->minimo}", $valor, ['ge' => $c->minimo]);

            return null;
        }
        if (null !== $c->maximo && $numero > $c->maximo) {
            $this->error('less_than_equal', $c->nombre, "Input should be less than or equal to {$c->maximo}", $valor, ['le' => $c->maximo]);

            return null;
        }

        if (!$entero) {
            // decimal_places = escala; max_digits = `max` del campo (si se definió).
            [$enteros, $decimales] = array_pad(explode('.', ltrim($texto, '+-')), 2, '');
            $decimales = rtrim($decimales, '0');
            if (\strlen($decimales) > $c->escala) {
                $this->error('decimal_max_places', $c->nombre, "Decimal input should have no more than {$c->escala} decimal place".(1 === $c->escala ? '' : 's'),
                    $valor, ['decimal_places' => $c->escala]);

                return null;
            }
            if (null !== $c->max && \strlen(ltrim($enteros, '0')) > $c->max - $c->escala) {
                $this->error('decimal_whole_digits', $c->nombre, 'Decimal input should have no more than '.($c->max - $c->escala).' digits before the decimal point',
                    $valor, ['whole_digits' => $c->max - $c->escala]);

                return null;
            }
        }

        return $entero ? (int) $texto : ltrim($texto, '+');
    }

    private function validarLogico(Campo $c, mixed $valor): ?bool
    {
        if (\is_bool($valor)) {
            return $valor;
        }
        $texto = \is_string($valor) ? strtolower(trim($valor)) : (\is_int($valor) ? (string) $valor : null);

        return match ($texto) {
            '1', 'true', 't', 'yes', 'y', 'on' => true,
            '0', 'false', 'f', 'no', 'n', 'off' => false,
            default => $this->fallo('bool_parsing', $c, 'Input should be a valid boolean, unable to interpret input', $valor),
        };
    }

    private function validarFecha(Campo $c, mixed $valor): ?string
    {
        if (\is_string($valor) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
            if (false !== $fecha && $fecha->format('Y-m-d') === $valor) {
                return $valor;
            }
        }

        return $this->fallo('date_from_datetime_parsing', $c, 'Input should be a valid date in the format YYYY-MM-DD', $valor);
    }

    private function validarFechaHora(Campo $c, mixed $valor): ?\DateTimeImmutable
    {
        if (\is_string($valor) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?$/', $valor)) {
            try {
                $tieneZona = 1 === preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $valor);
                $fecha = new \DateTimeImmutable($valor, new \DateTimeZone('UTC'));

                // Sin zona: ya es UTC (como guarda el sistema); con zona: se pasa a UTC.
                return $tieneZona ? $fecha->setTimezone(new \DateTimeZone('UTC')) : $fecha;
            } catch (\Exception) {
            }
        }

        return $this->fallo('datetime_from_date_parsing', $c, 'Input should be a valid datetime', $valor);
    }

    /** Correo corporativo: sintaxis estricta, admite dominios internos (.local); dominio en minúsculas. */
    private function validarCorreo(Campo $c, mixed $valor): ?string
    {
        $correo = self::correoCorporativo($valor);
        if (null !== $correo) {
            return $correo;
        }
        if (!\is_string($valor)) {
            return $this->fallo('string_type', $c, 'Input should be a valid string', $valor);
        }
        $this->errorDeValor($c->nombre, 'value is not a valid email address', $valor);

        return null;
    }

    /**
     * Lista de correos corporativos (cada error con su índice en `loc`).
     *
     * @return list<string>|null
     */
    public function correos(string $campo, int $max): ?array
    {
        $lista = $this->lista($campo, $max);
        if (null === $lista) {
            return null;
        }
        $correos = [];
        foreach ($lista as $i => $v) {
            $correo = self::correoCorporativo($v);
            if (null !== $correo) {
                $correos[] = $correo;
            } elseif (!\is_string($v)) {
                $this->errores[] = ['type' => 'string_type', 'loc' => [$this->ubicacion, ...$this->ruta, $campo, $i], 'msg' => 'Input should be a valid string', 'input' => $v];
            } else {
                $this->errores[] = ['type' => 'value_error', 'loc' => [$this->ubicacion, ...$this->ruta, $campo, $i],
                    'msg' => 'Value error, value is not a valid email address', 'input' => $v, 'ctx' => ['error' => 'value is not a valid email address']];
            }
        }

        return \count($correos) === \count($lista) ? $correos : null;
    }

    /** Correo normalizado (dominio en minúsculas) o null si no es válido. */
    private static function correoCorporativo(mixed $valor): ?string
    {
        $etiqueta = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
        $texto = \is_string($valor) ? trim($valor) : '';
        if (\strlen($texto) > 254 || 1 !== preg_match("/^[A-Za-z0-9.!#\$%&'*+\\/=?^_`{|}~-]{1,64}@{$etiqueta}(?:\\.{$etiqueta})+$/", $texto)
            || str_contains(explode('@', $texto)[0], '..')) {
            return null;
        }
        [$local, $dominio] = explode('@', $texto, 2);

        return $local.'@'.strtolower($dominio);
    }

    private function fallo(string $tipo, Campo $c, string $mensaje, mixed $valor): null
    {
        $this->error($tipo, $c->nombre, $mensaje, $valor);

        return null;
    }

    private function tipoError(Campo $c): string
    {
        return match ($c->tipo) {
            Campo::ENTERO => 'int_type',
            Campo::DECIMAL => 'decimal_type',
            Campo::LOGICO => 'bool_type',
            default => 'string_type',
        };
    }

    private function mensajeTipo(Campo $c): string
    {
        return match ($c->tipo) {
            Campo::ENTERO => 'Input should be a valid integer',
            Campo::DECIMAL => 'Input should be a valid decimal',
            Campo::LOGICO => 'Input should be a valid boolean',
            default => 'Input should be a valid string',
        };
    }

    /** @param array<string, mixed>|null $ctx */
    private function error(string $tipo, string $campo, string $mensaje, mixed $valor, ?array $ctx = null): void
    {
        $error = ['type' => $tipo, 'loc' => [$this->ubicacion, ...$this->ruta, $campo], 'msg' => $mensaje, 'input' => $valor];
        if (null !== $ctx) {
            $error['ctx'] = $ctx;
        }
        $this->errores[] = $error;
    }
}
