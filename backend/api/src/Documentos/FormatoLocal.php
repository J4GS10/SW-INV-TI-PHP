<?php

declare(strict_types=1);

namespace App\Documentos;

use App\Config\Ajustes;
use App\Dominio\Reloj;

/**
 * Fechas, horas y montos tal como se muestran en los documentos y reportes. La
 * base guarda TIMESTAMP en UTC: aquí se pasan a la zona de la organización
 * (ORG_TIMEZONE), así un acta generada a las 19:00 en Guatemala no sale con la
 * fecha del día siguiente.
 */
final readonly class FormatoLocal
{
    private const array MESES = [
        'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'it' => ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'],
    ];

    private \DateTimeZone $zona;

    public function __construct(Ajustes $ajustes)
    {
        $this->zona = new \DateTimeZone($ajustes->zonaHoraria);
    }

    /** Momento UTC → hora local de la organización. */
    public function aLocal(\DateTimeImmutable $momento): \DateTimeImmutable
    {
        return $momento->setTimezone($this->zona);
    }

    public function ahora(): \DateTimeImmutable
    {
        return $this->aLocal(Reloj::ahora());
    }

    /** Hoy en la zona de la organización, «AAAAMMDD» (sufijo de los nombres de archivo). */
    public function hoyCompacto(): string
    {
        return $this->ahora()->format('Ymd');
    }

    /** Momento UTC (de la base o ISO de la API) → «AAAA-MM-DD HH:MM:SS» local (sin la «T» ni microsegundos). */
    public function fechaHora(?string $momentoUtc): string
    {
        $momento = self::momento($momentoUtc);

        return null === $momento ? '' : $this->aLocal($momento)->format('Y-m-d H:i:s');
    }

    /** Columna DATE → «AAAA-MM-DD», inequívoca en cualquier configuración regional de Excel. */
    public function fecha(?string $fechaBd): string
    {
        return null === $fechaBd || '' === $fechaBd ? '' : substr($fechaBd, 0, 10);
    }

    /** Momento UTC → fecha local «AAAA-MM-DD». */
    public function fechaDeMomento(?string $momentoUtc): string
    {
        $momento = self::momento($momentoUtc);

        return null === $momento ? '' : $this->aLocal($momento)->format('Y-m-d');
    }

    /** «2026-10-28 14:05:00.123456» (base) o «2026-10-28T14:05:00» (API), ambos en UTC. */
    private static function momento(?string $valor): ?\DateTimeImmutable
    {
        return null === $valor || '' === $valor ? null : new \DateTimeImmutable($valor, new \DateTimeZone('UTC'));
    }

    /** es: 24 de septiembre de 2026 · en: September 24, 2026 · it: 24 settembre 2026 (el día 1: «1º»). */
    public function fechaLarga(\DateTimeImmutable $fecha, Idioma $idioma): string
    {
        $dia = (int) $fecha->format('j');
        $mes = self::MESES[$idioma->value][(int) $fecha->format('n') - 1];
        $anio = $fecha->format('Y');

        return match ($idioma) {
            Idioma::En => "{$mes} {$dia}, {$anio}",
            Idioma::It => (1 === $dia ? '1º' : (string) $dia)." {$mes} {$anio}",
            Idioma::Es => "{$dia} de {$mes} de {$anio}",
        };
    }

    /**
     * Monto con 2 decimales y punto decimal, sin separador de miles (apto para hojas
     * de cálculo). Redondeo bancario, como `Decimal.quantize` del backend anterior.
     */
    public static function monto(?string $valor): string
    {
        if (null === $valor || '' === $valor) {
            return '';
        }
        if (!is_numeric($valor)) {
            return $valor;
        }

        return bcround($valor, 2, \RoundingMode::HalfEven);
    }
}
