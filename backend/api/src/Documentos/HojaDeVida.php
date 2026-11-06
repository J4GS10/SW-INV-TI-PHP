<?php

declare(strict_types=1);

namespace App\Documentos;

use App\Inventario\Activos;
use App\Inventario\Reportes;
use Doctrine\DBAL\Connection;

/**
 * Hoja de vida de un activo en PDF (A4 horizontal): datos del equipo y todos sus
 * eventos del kardex en orden cronológico (de dónde a dónde, motivo o documento
 * y quién lo registró). Los roles sin costos no ven el costo.
 */
final readonly class HojaDeVida
{
    private const array TEXTOS = [
        'es' => ['Hoja de vida del activo', 'Código', 'Serie', 'Modelo', 'Costo', 'Compra', 'Fecha', 'Evento', 'Desde', 'Hacia', 'Motivo / documento',
            'Registró', 'Sin eventos registrados.', 'Hoja_de_vida'],
        'en' => ['Asset history', 'Code', 'Serial', 'Model', 'Cost', 'Purchase', 'Date', 'Event', 'From', 'To', 'Reason / document', 'Recorded by',
            'No events recorded.', 'Asset_history'],
        'it' => ["Storico dell'asset", 'Codice', 'Seriale', 'Modello', 'Costo', 'Acquisto', 'Data', 'Evento', 'Da', 'A', 'Motivo / documento',
            'Registrato da', 'Nessun evento registrato.', 'Storico_asset'],
    ];
    /** Anchos de las columnas en cm (fecha, evento, desde, hacia, motivo, registró). */
    private const array COLUMNAS = [3.2, 3.6, 5.2, 5.2, 6.3, 3.0];
    private const string ESTILOS = '
        h1{text-align:center;font-size:18pt;margin:0 0 8pt}
        .datos{font-size:10pt;margin-bottom:0.4cm}
        table{border-collapse:collapse;font-size:7.5pt;line-height:9pt}
        th{background:#E8EEF3;font-weight:bold;text-align:left}
        th,td{border:0.3pt solid #B8C4CC;padding:3pt;vertical-align:top}
        tr{page-break-inside:avoid}';

    public function __construct(
        private Connection $conexion,
        private Activos $activos,
        private Reportes $reportes,
        private FormatoLocal $formato,
    ) {
    }

    /** @return array{0: string, 1: string} contenido y nombre del archivo */
    public function generar(string $activoHex, Idioma $idioma, bool $conCosto): array
    {
        $activo = $this->activos->filaO404($activoHex);
        $modelo = $this->conexion->fetchOne('SELECT "MOD_Nombre" FROM "INV_MODELO" WHERE "MOD_Modelo" = :m', ['m' => $activo['MOD_Modelo']]);
        $eventos = $this->reportes->kardexCompleto(['activo' => $activoHex]);
        $t = $idioma->de(self::TEXTOS);
        $e = Pdf::e(...);

        $datos = "{$t[1]}: {$activo['ACT_Codigo_Interno']}   ·   {$t[2]}: {$activo['ACT_Serie_Fabricante']}   ·   "
            ."{$t[3]}: ".(false === $modelo || null === $modelo ? '—' : $modelo).'   ·   '."{$t[5]}: ".$this->formato->fecha($activo['ACT_Fecha_Compra']);
        if ($conCosto) {
            $datos .= "   ·   {$t[4]}: ".FormatoLocal::monto($activo['ACT_Costo']).' '.($activo['ACT_Moneda'] ?? '');
        }
        $html = '<h1>'.$e($t[0]).'</h1><div class="datos">'.$e($datos).'</div>';

        if ([] === $eventos) {
            $html .= '<div class="datos">'.$e($t[12]).'</div>';
        } else {
            $fila = static fn (array $celdas, string $etiqueta): string => '<tr>'.implode('', array_map(
                static fn (string $v, float $ancho): string => \sprintf('<%1$s style="width:%2$.1fcm">%3$s</%1$s>', $etiqueta, $ancho, $e($v)), $celdas, self::COLUMNAS)).'</tr>';
            $html .= '<table><thead>'.$fila([$t[6], $t[7], $t[8], $t[9], $t[10], $t[11]], 'th').'</thead><tbody>';
            foreach ($eventos as $ev) {
                $documento = \App\Inventario\Kardex::documentoLegible($ev['KDX_Documento_Tipo'], $ev['KDX_Documento_Ref']);
                $html .= $fila([
                    $this->formato->fechaHora($ev['KDX_Fecha']), Traducciones::tipoEvento($ev['KDX_Tipo'], $idioma),
                    self::unir(' · ', [$ev['origen_persona'], $ev['origen_area'], $ev['origen_sede'], $ev['origen_activo']]) ?: '—',
                    self::unir(' · ', [$ev['destino_persona'], $ev['destino_area'], $ev['destino_sede'], $ev['destino_activo']]) ?: '—',
                    self::unir(' — ', [$ev['KDX_Motivo'], $documento]), (string) ($ev['usuario'] ?? ''),
                ], 'td');
            }
            $html .= '</tbody></table>';
        }

        return [Pdf::desdeHtml($html, self::ESTILOS, 'a4', 'landscape', '1.2cm', $t[0], '', $idioma), "{$t[13]}_{$activo['ACT_Codigo_Interno']}.pdf"];
    }

    /** @param list<?string> $partes */
    private static function unir(string $separador, array $partes): string
    {
        return implode($separador, array_filter($partes, static fn (?string $p): bool => null !== $p && '' !== $p));
    }
}
