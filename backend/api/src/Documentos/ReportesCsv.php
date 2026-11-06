<?php

declare(strict_types=1);

namespace App\Documentos;

use App\Inventario\Reportes;
use App\Inventario\Tomas;
use Doctrine\DBAL\Connection;

/**
 * CSV del inventario: kardex, custodia a una fecha, licencias y resultado de una
 * toma física. Reutiliza los mismos reportes que la pantalla (mismo alcance).
 */
final readonly class ReportesCsv
{
    private const array CABECERAS = [
        'kardex' => [
            'es' => ['Fecha y hora', 'Evento', 'Objeto', 'Referencia', 'Desde (persona)', 'Desde (área)', 'Desde (sede)', 'Hacia (persona)', 'Hacia (área)',
                'Hacia (sede)', 'Equipo', 'Cantidad', 'Saldo', 'Costo', 'Moneda', 'Documento', 'Motivo', 'Registrado por', 'Migrado'],
            'en' => ['Date and time', 'Event', 'Item', 'Reference', 'From (person)', 'From (area)', 'From (site)', 'To (person)', 'To (area)',
                'To (site)', 'Device', 'Quantity', 'Balance', 'Cost', 'Currency', 'Document', 'Reason', 'Recorded by', 'Migrated'],
            'it' => ['Data e ora', 'Evento', 'Oggetto', 'Riferimento', 'Da (persona)', 'Da (area)', 'Da (sede)', 'A (persona)', 'A (area)',
                'A (sede)', 'Dispositivo', 'Quantità', 'Saldo', 'Costo', 'Valuta', 'Documento', 'Motivo', 'Registrato da', 'Migrato'],
        ],
        'custodia' => [
            'es' => ['Código', 'Número de serie', 'Tipo de activo', 'Custodio', 'Área', 'Sede', 'Desde', 'Tipo de movimiento', 'Devolución prevista'],
            'en' => ['Code', 'Serial number', 'Asset type', 'Custodian', 'Area', 'Site', 'Since', 'Movement type', 'Expected return'],
            'it' => ['Codice', 'Numero di serie', 'Tipo di asset', 'Assegnatario', 'Area', 'Sede', 'Dal', 'Tipo di movimento', 'Restituzione prevista'],
        ],
        'licencias' => [
            'es' => ['Software', 'Versión', 'Fabricante', 'Tipo de licencia', 'Total', 'En uso', 'Disponibles', 'Uso (%)', 'Vencimiento', 'Días para vencer',
                'Costo', 'Moneda'],
            'en' => ['Software', 'Version', 'Vendor', 'License type', 'Total', 'In use', 'Available', 'Usage (%)', 'Expiration', 'Days to expire', 'Cost',
                'Currency'],
            'it' => ['Software', 'Versione', 'Produttore', 'Tipo di licenza', 'Totale', 'In uso', 'Disponibili', 'Utilizzo (%)', 'Scadenza',
                'Giorni alla scadenza', 'Costo', 'Valuta'],
        ],
    ];

    /** Resultado de la toma: [resultado, código, área] y la etiqueta de cada grupo del resumen. */
    private const array TOMA = [
        'es' => ['Resultado', 'Código', 'Área', 'FALTANTE', 'OTRA UBICACIÓN', 'OTRA SEDE', 'DE BAJA', 'NO REGISTRADO'],
        'en' => ['Result', 'Code', 'Area', 'MISSING', 'OTHER LOCATION', 'OTHER SITE', 'DECOMMISSIONED', 'NOT REGISTERED'],
        'it' => ['Esito', 'Codice', 'Area', 'MANCANTE', 'ALTRA POSIZIONE', 'ALTRA SEDE', 'DISMESSO', 'NON REGISTRATO'],
    ];
    private const array GRUPOS_TOMA = ['faltantes', 'otra_ubicacion', 'otra_sede', 'de_baja', 'no_registrados'];

    public function __construct(
        private Connection $conexion,
        private Reportes $reportes,
        private Tomas $tomas,
        private FormatoLocal $formato,
    ) {
    }

    /** @param array<string, mixed> $filtros los del kardex en pantalla */
    public function kardex(array $filtros, Idioma $idioma): string
    {
        $vacio = static fn (mixed $v): string => null === $v ? '' : (string) $v;

        return ArchivoCsv::minimo($idioma->de(self::CABECERAS['kardex']))->filas(array_map(fn (array $e): array => [
            $this->formato->fechaHora($e['KDX_Fecha']), Traducciones::tipoEvento($e['KDX_Tipo'], $idioma), Traducciones::objeto($e['KDX_Objeto'], $idioma),
            $vacio($e['KDX_Referencia']),
            $vacio($e['origen_persona']), $vacio($e['origen_area']), $vacio($e['origen_sede']),
            $vacio($e['destino_persona']), $vacio($e['destino_area']), $vacio($e['destino_sede']),
            $e['destino_activo'] ?? $e['origen_activo'] ?? '',
            $vacio($e['KDX_Cantidad']), $vacio($e['KDX_Saldo']), FormatoLocal::monto($e['KDX_Costo']), $vacio($e['KDX_Moneda']),
            implode(' ', array_filter([$e['KDX_Documento_Tipo'], $e['KDX_Documento_Ref']], static fn (?string $x): bool => null !== $x && '' !== $x)),
            $vacio($e['KDX_Motivo']), $vacio($e['usuario']), Traducciones::siNo($e['KDX_Migrado'], $idioma),
        ], $this->reportes->kardexCompleto($filtros)))->contenido();
    }

    public function custodia(?string $fecha, ?string $persona, ?int $area, ?int $sede, Idioma $idioma): string
    {
        $sedes = $this->conexion->fetchAllKeyValue('SELECT "SED_Sede", "SED_Nombre" FROM "INV_SEDE"');

        return ArchivoCsv::minimo($idioma->de(self::CABECERAS['custodia']))->filas(array_map(fn (array $i): array => [
            $i['activo_codigo'], $i['activo_serie'],
            null === $i['activo_tipo'] ? '' : Traducciones::catalogo(Traducciones::TIPO_ACTIVO, $i['activo_tipo'], $idioma),
            $i['persona'], $i['area'] ?? '', null === $i['SED_Sede'] ? '' : ($sedes[$i['SED_Sede']] ?? ''), $this->formato->fechaHora($i['desde']),
            null === $i['tipo'] ? '' : Traducciones::catalogo(Traducciones::TIPO_MOVIMIENTO, $i['tipo'], $idioma),
            $this->formato->fecha($i['devolucion_prevista']),
        ], $this->reportes->custodia($fecha, $persona, $area, $sede)))->contenido();
    }

    /** Uso, vencimiento y costo de las licencias (catálogo de software compartido por las sedes). */
    public function licencias(Idioma $idioma): string
    {
        $hoy = new \DateTimeImmutable($this->formato->ahora()->format('Y-m-d'));
        $filas = $this->conexion->fetchAllAssociative('SELECT s."SOF_Nombre", s."SOF_Version", s."SOF_Fabricante", t."TLI_Nombre", l."LIC_Cantidad_Total", '
            .'l."LIC_Cantidad_Usada", l."LIC_Fecha_Vencimiento", l."LIC_Costo", l."LIC_Moneda" FROM "INV_LICENCIA" l '
            .'JOIN "INV_SOFTWARE" s ON s."SOF_Software" = l."SOF_Software" LEFT JOIN "INV_TIPO_LICENCIA" t ON t."TLI_Tipo_Licencia" = l."TLI_Tipo_Licencia" '
            .'ORDER BY s."SOF_Nombre", l."LIC_Licencia"');

        return ArchivoCsv::minimo($idioma->de(self::CABECERAS['licencias']))->filas(array_map(function (array $l) use ($hoy): array {
            $total = (int) $l['LIC_Cantidad_Total'];
            $usada = (int) $l['LIC_Cantidad_Usada'];
            $vence = $this->formato->fecha($l['LIC_Fecha_Vencimiento']);

            return [$l['SOF_Nombre'], $l['SOF_Version'] ?? '', $l['SOF_Fabricante'] ?? '', $l['TLI_Nombre'] ?? '', $total, $usada, $total - $usada,
                $total > 0 ? (string) (int) round(100 * $usada / $total, 0, \PHP_ROUND_HALF_EVEN) : '0',
                $vence, '' === $vence ? '' : (int) $hoy->diff(new \DateTimeImmutable($vence))->format('%r%a'),
                FormatoLocal::monto($l['LIC_Costo']), $l['LIC_Moneda'] ?? ''];
        }, $filas))->contenido();
    }

    /** Lo que la toma no encontró donde debía: faltantes, en otra ubicación o sede, de baja y no registrados. */
    public function toma(int $id, Idioma $idioma): string
    {
        $resumen = $this->tomas->detalle($id)['resumen'];
        $etiquetas = $idioma->de(self::TOMA);
        $csv = ArchivoCsv::minimo(\array_slice($etiquetas, 0, 3));
        foreach (self::GRUPOS_TOMA as $i => $grupo) {
            foreach ($resumen[$grupo] ?? [] as $item) {
                $csv->fila([$etiquetas[3 + $i], $item['codigo'], $item['area'] ?? '']);
            }
        }

        return $csv->contenido();
    }
}
