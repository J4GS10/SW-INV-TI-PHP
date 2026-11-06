<?php

declare(strict_types=1);

namespace App\Crud;

use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Http\Entrada;

/**
 * Validación de la entrada y conversión de la salida según la definición de
 * la entidad (equivalente a los esquemas de entrada/respuesta del backend anterior).
 */
final class Esquema
{
    /**
     * Datos para un INSERT: todos los campos de entrada (con su valor por defecto).
     *
     * @param list<string>|null $soloCampos limita a estos campos (esquemas de creación parciales)
     *
     * @return array<string, mixed> columna => valor para la base
     */
    public static function creacion(Entidad $e, Entrada $entrada, ?array $soloCampos = null): array
    {
        $datos = [];
        foreach (self::camposDeEntrada($e, $soloCampos) as $campo) {
            [$presente, $valor] = $entrada->campo($campo, true);
            $datos[$campo->nombre] = $presente ? $valor : $campo->defecto;
        }

        return self::aBase($e, $datos);
    }

    /**
     * Datos para un UPDATE parcial: solo los campos enviados (como exclude_unset).
     *
     * @param list<string>|null $soloCampos
     *
     * @return array<string, mixed>
     */
    public static function actualizacion(Entidad $e, Entrada $entrada, ?array $soloCampos = null): array
    {
        $datos = [];
        foreach (self::camposDeEntrada($e, $soloCampos) as $campo) {
            if (!$campo->editable) {
                continue;
            }
            [$presente, $valor] = $entrada->campo($campo, false);
            if ($presente) {
                $datos[$campo->nombre] = $valor;
            }
        }

        return self::aBase($e, $datos);
    }

    /**
     * Fila de la base → objeto de la API.
     *
     * @param array<string, mixed> $fila
     *
     * @return array<string, mixed>
     */
    public static function aApi(Entidad $e, array $fila): array
    {
        $salida = [$e->clave => self::valorApi($e->campoClave(), $fila[$e->clave] ?? null)];
        foreach ($e->campos as $nombre => $campo) {
            $salida[$nombre] = self::valorApi($campo, $fila[$nombre] ?? null);
        }

        return $salida;
    }

    public static function valorApi(Campo $campo, mixed $valor): mixed
    {
        if (null === $valor) {
            return null;
        }

        return match ($campo->tipo) {
            Campo::ENTERO => (int) $valor,
            Campo::DECIMAL => self::decimal((string) $valor, $campo->escala),
            Campo::LOGICO => 1 === (int) $valor,
            Campo::FECHA => substr((string) $valor, 0, 10),
            Campo::FECHAHORA => Reloj::iso(Reloj::desdeBd((string) $valor)),
            Campo::UUID => Ident::aTexto((string) $valor),
            default => (string) $valor,
        };
    }

    /** Decimal con su escala, sin pasar por coma flotante ("1500" → "1500.00"). */
    public static function decimal(string $valor, int $escala): string
    {
        $valor = trim($valor);
        $negativo = str_starts_with($valor, '-');
        $valor = ltrim($valor, '+-');
        [$entera, $fraccion] = array_pad(explode('.', $valor, 2), 2, '');
        $entera = '' === $entera ? '0' : $entera;
        $fraccion = substr(str_pad($fraccion, $escala, '0'), 0, $escala);
        $texto = 0 === $escala ? $entera : "{$entera}.{$fraccion}";
        $esCero = '' === trim($entera.$fraccion, '0');

        return ($negativo && !$esCero ? '-' : '').$texto;
    }

    /**
     * @param array<string, mixed> $datos valores ya validados
     *
     * @return array<string, mixed>
     */
    private static function aBase(Entidad $e, array $datos): array
    {
        foreach ($datos as $columna => $valor) {
            $campo = $e->campos[$columna] ?? null;
            if (null === $campo || null === $valor) {
                continue;
            }
            $datos[$columna] = match ($campo->tipo) {
                Campo::LOGICO => $valor ? 1 : 0,
                Campo::UUID => Ident::aHex((string) $valor),
                Campo::FECHAHORA => $valor instanceof \DateTimeImmutable ? Reloj::aBd($valor) : $valor,
                default => $valor,
            };
        }

        return $datos;
    }

    /**
     * @param list<string>|null $soloCampos
     *
     * @return list<Campo>
     */
    private static function camposDeEntrada(Entidad $e, ?array $soloCampos): array
    {
        return array_values(array_filter(
            $e->campos,
            static fn (Campo $c): bool => $c->entrada && (null === $soloCampos || \in_array($c->nombre, $soloCampos, true)),
        ));
    }
}
