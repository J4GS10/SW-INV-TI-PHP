<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Documentos\ArchivoCsv;
use App\Documentos\Idioma;

/** The purchasing reports as a file, with the same rows the screen shows. */
final readonly class PurchasingReportsCsv
{
    private const array HEADERS = [
        'stock' => [
            'es' => ['Tipo', 'Producto', 'Total', 'Disponibles', 'Asignados', 'Última entrada'],
            'en' => ['Type', 'Product', 'Total', 'Available', 'Assigned', 'Last entry'],
            'it' => ['Tipo', 'Prodotto', 'Totale', 'Disponibili', 'Assegnati', 'Ultimo ingresso'],
        ],
        'entries' => [
            'es' => ['Fecha', 'Tipo', 'Producto', 'Cantidad', 'Costo unitario', 'Moneda', 'Documento'],
            'en' => ['Date', 'Type', 'Product', 'Quantity', 'Unit cost', 'Currency', 'Document'],
            'it' => ['Data', 'Tipo', 'Prodotto', 'Quantità', 'Costo unitario', 'Valuta', 'Documento'],
        ],
        'assignments' => [
            'es' => ['Asignado', 'Devuelto', 'Activo', 'Producto', 'Persona', 'Área'],
            'en' => ['Assigned', 'Returned', 'Asset', 'Product', 'Person', 'Area'],
            'it' => ['Assegnato', 'Restituito', 'Asset', 'Prodotto', 'Persona', 'Area'],
        ],
    ];

    public function __construct(private PurchasingReports $reports)
    {
    }

    public function build(string $report, ?string $from, ?string $to, ?int $site, Idioma $language): string
    {
        return ArchivoCsv::entreComillas($language->de(self::HEADERS[$report]))->filas($this->rows($report, $from, $to, $site))->contenido();
    }

    /** @return list<list<mixed>> */
    private function rows(string $report, ?string $from, ?string $to, ?int $site): array
    {
        if ('entries' === $report) {
            return array_map(static fn (array $r): array => [$r['date'], $r['type'], $r['item'], $r['quantity'], $r['unit_cost'], $r['currency'], $r['document']], $this->reports->entries($from, $to, $site));
        }
        if ('assignments' === $report) {
            return array_map(static fn (array $r): array => [$r['assigned_at'], $r['returned_at'], $r['asset_code'], $r['product'], $r['person'], $r['area']], $this->reports->assignments($from, $to, $site));
        }
        $stock = $this->reports->stock($site);

        return [
            ...array_map(static fn (array $r): array => [$r['type'], trim(($r['brand'] ?? '').' '.($r['model'] ?? '')), $r['total'], $r['available'], $r['assigned'], $r['last_entry_at']], $stock['assets']),
            ...array_map(static fn (array $r): array => ['', $r['name'], $r['stock'], $r['stock'], 0, $r['last_entry_at']], $stock['consumables']),
        ];
    }
}
