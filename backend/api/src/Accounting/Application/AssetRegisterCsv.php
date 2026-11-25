<?php

declare(strict_types=1);

namespace App\Accounting\Application;

use App\Documentos\ArchivoCsv;
use App\Documentos\Idioma;

/** The fixed-asset register as a file: one line per asset on the books, with the same values the screen shows. */
final readonly class AssetRegisterCsv
{
    private const array HEADERS = [
        'es' => ['Código', 'Tipo', 'Producto', 'Serie', 'Instalado en', 'Sede', 'Categoría contable', 'Cuenta contable', 'Tasa anual %', 'Fecha de compra', 'Factura',
            'Moneda', 'Costo', 'Depreciación mensual', 'Meses depreciados', 'Depreciación acumulada', 'Valor en libros', 'Estado'],
        'en' => ['Code', 'Type', 'Product', 'Serial', 'Installed in', 'Site', 'Accounting category', 'Ledger account', 'Annual rate %', 'Purchase date', 'Invoice',
            'Currency', 'Cost', 'Monthly depreciation', 'Months depreciated', 'Accumulated depreciation', 'Book value', 'Status'],
        'it' => ['Codice', 'Tipo', 'Prodotto', 'Seriale', 'Installato in', 'Sede', 'Categoria contabile', 'Conto contabile', 'Aliquota annua %', 'Data di acquisto', 'Fattura',
            'Valuta', 'Costo', 'Ammortamento mensile', 'Mesi ammortizzati', 'Fondo ammortamento', 'Valore contabile', 'Stato'],
    ];

    public function __construct(private AssetValuation $valuation)
    {
    }

    public function build(\DateTimeImmutable $asOf, ?int $site, Idioma $language): string
    {
        $lines = $this->valuation->register($asOf, $site);
        $codes = array_column($lines, 'code', 'asset_id');
        $rows = array_map(static fn (array $l): array => [
            $l['code'], $l['type'], $l['product'], $l['serial'], null === $l['parent_id'] ? '' : ($codes[$l['parent_id']] ?? ''), $l['site'],
            $l['category']['name'] ?? '', $l['category']['account_code'] ?? '', $l['category']['annual_rate'] ?? '', $l['acquired_on'], $l['invoice'],
            $l['currency'], $l['acquisition_cost'], $l['monthly_depreciation'], $l['months_elapsed'], $l['accumulated_depreciation'], $l['book_value'], $l['status'],
        ], $lines);

        return ArchivoCsv::entreComillas($language->de(self::HEADERS))->filas($rows)->contenido();
    }
}
