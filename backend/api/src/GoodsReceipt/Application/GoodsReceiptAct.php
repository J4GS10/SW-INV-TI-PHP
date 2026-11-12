<?php

declare(strict_types=1);

namespace App\GoodsReceipt\Application;

use App\Documentos\Idioma;
use App\Documentos\Pdf;
use App\GoodsReceipt\Domain\GoodsReceiptStatus;
use App\GoodsReceipt\Infrastructure\GoodsReceiptRepository;
use App\Dominio\ErrorDominio;
use Doctrine\DBAL\Connection;

/** The receipt act handed to purchasing once IT has confirmed what truly arrived. */
final readonly class GoodsReceiptAct
{
    private const array TEXTS = [
        'es' => ['title' => 'Acta de recibido', 'code' => 'Ingreso', 'supplier' => 'Proveedor', 'document' => 'Factura o envío', 'date' => 'Fecha de recepción', 'site' => 'Sede', 'item' => 'Producto',
            'declared' => 'Declarado', 'received' => 'Recibido', 'cost' => 'Costo unitario', 'registered' => 'Registrado por', 'confirmed' => 'Confirmado por', 'reason' => 'Motivo de la diferencia'],
        'en' => ['title' => 'Receipt act', 'code' => 'Receipt', 'supplier' => 'Supplier', 'document' => 'Invoice or delivery note', 'date' => 'Received on', 'site' => 'Site', 'item' => 'Product',
            'declared' => 'Declared', 'received' => 'Received', 'cost' => 'Unit cost', 'registered' => 'Registered by', 'confirmed' => 'Confirmed by', 'reason' => 'Reason for the difference'],
        'it' => ['title' => 'Verbale di ricevimento', 'code' => 'Entrata', 'supplier' => 'Fornitore', 'document' => 'Fattura o bolla', 'date' => 'Data di ricevimento', 'site' => 'Sede', 'item' => 'Prodotto',
            'declared' => 'Dichiarato', 'received' => 'Ricevuto', 'cost' => 'Costo unitario', 'registered' => 'Registrato da', 'confirmed' => 'Confermato da', 'reason' => 'Motivo della differenza'],
    ];

    public function __construct(private GoodsReceiptRepository $receipts, private Connection $connection)
    {
    }

    public function generate(int $id, Idioma $language): string
    {
        $receipt = $this->receipts->find($id) ?? throw ErrorDominio::noEncontrado('GOODS_RECEIPT_NOT_FOUND');
        if (!GoodsReceiptStatus::from((string) $receipt['GRC_STATUS'])->hasEnteredInventory()) {
            throw ErrorDominio::conflicto('GOODS_RECEIPT_NOT_CONFIRMED');
        }
        $t = self::TEXTS[$language->value];
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';
        foreach ($this->receipts->lines($id) as $line) {
            $rows .= '<tr><td>'.$e($line['GRL_ITEM_TEXT']).'</td><td class="n">'.(int) $line['GRL_QUANTITY_DECLARED'].'</td><td class="n">'.(int) $line['GRL_QUANTITY_RECEIVED'].'</td><td class="n">'
                .$e(bcadd((string) $line['GRL_UNIT_COST'], '0', 2)).' '.$e($receipt['GRC_CURRENCY']).'</td></tr>';
        }
        $company = (string) ($this->connection->fetchOne('SELECT "SYS_Nombre_Empresa" FROM "SYS_CONFIGURACION" WHERE "SYS_Configuracion" = 1') ?: '');
        $reason = null === $receipt['GRC_DIFFERENCE_REASON'] ? '' : '<p><b>'.$e($t['reason']).':</b> '.$e($receipt['GRC_DIFFERENCE_REASON']).'</p>';
        $body = '<h1>'.$e($t['title']).'</h1><p class="c">'.$e($company).'</p>'
            .'<table class="d"><tr><th>'.$e($t['code']).'</th><td>'.$e($receipt['GRC_CODE']).'</td><th>'.$e($t['date']).'</th><td>'.$e($receipt['RECEIVED_DATE']).'</td></tr>'
            .'<tr><th>'.$e($t['supplier']).'</th><td>'.$e($receipt['SUPPLIER_NAME']).'</td><th>'.$e($t['document']).'</th><td>'.$e($receipt['GRC_DOCUMENT_NUMBER']).'</td></tr>'
            .'<tr><th>'.$e($t['site']).'</th><td colspan="3">'.$e($receipt['SITE_NAME']).'</td></tr></table>'
            .'<table class="l"><thead><tr><th>'.$e($t['item']).'</th><th>'.$e($t['declared']).'</th><th>'.$e($t['received']).'</th><th>'.$e($t['cost']).'</th></tr></thead><tbody>'.$rows.'</tbody></table>'.$reason
            .'<table class="f"><tr><td>'.$e($t['registered']).'<br><b>'.$e($this->username($receipt['GRC_USU_CREATED_BY'])).'</b></td><td>'.$e($t['confirmed']).'<br><b>'.$e($this->username($receipt['GRC_USU_CONFIRMED_BY'])).'</b></td></tr></table>';
        $styles = 'body{font-family:Helvetica;font-size:10pt}h1{font-size:15pt;text-align:center;margin:0}.c{text-align:center;color:#555}table{width:100%;border-collapse:collapse;margin-top:12pt}'
            .'.d th{text-align:left;width:18%;background:#eee}.d td,.d th,.l td,.l th{border:1px solid #bbb;padding:4pt}.l th{background:#eee}.n{text-align:right}.f{margin-top:50pt}.f td{width:50%;text-align:center;padding-top:6pt;border-top:1px solid #333}';

        return Pdf::desdeHtml($body, $styles, 'letter', 'portrait', '0.8in 0.9in 0.7in 0.9in', $t['title'], $company, $language);
    }

    private function username(mixed $user): string
    {
        return null === $user ? '' : (string) $this->connection->fetchOne('SELECT "USU_Username" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $user]);
    }
}
