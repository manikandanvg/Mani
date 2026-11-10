<?php

namespace App\Support\Gst;

use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes the auditor workbooks: one register per file in exactly the layout the auditor
 * has been receiving (GSTR REPORT B2C / GSTR REPORT B2B — one sheet, bold header row,
 * real date and number cells), and the GSTR-1 offline-tool workbook (B2B / B2CS / HSN)
 * derived from the same two registers so the three documents always agree.
 */
class GstAuditorWorkbook
{
    /** Write one register (rows already numbered) to $path. */
    public static function register(string $register, array $rows, string $path): void
    {
        $columns = $register === 'b2b' ? GstAuditorReport::B2B_COLUMNS : GstAuditorReport::B2C_COLUMNS;
        $options = new Options;
        foreach ([4 => 36, 5 => 48, 6 => 28, 12 => 24] as $col => $w) {
            $options->setColumnWidth($w, $col + 1);
        }
        $options->setColumnWidth(12, 2);
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Sheet1');
        $writer->addRow(Row::fromValues($columns, (new Style)->setFontBold()));
        $date = (new Style)->setFormat('dd-mm-yyyy');
        $money = (new Style)->setFormat('#,##0.00');
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $c) {
                $v = $row[$c] ?? null;
                $cells[] = match (true) {
                    $v instanceof \DateTimeInterface => Cell::fromValue(Carbon::instance($v)->toDateTime(), $date),
                    is_float($v) => Cell::fromValue($v, $money),
                    default => Cell::fromValue($v ?? ''),
                };
            }
            $writer->addRow(new Row($cells));
        }
        $writer->close();
    }

    /**
     * GSTR-1 offline-tool shape from the two numbered registers: B2B (invoice-wise, dealer
     * GSTIN), B2CS (consumer supplies consolidated per rate), HSN (71181000 summary).
     */
    public static function gstr1(array $b2c, array $b2b, string $path, string $placeOfSupply = '33-Tamil Nadu'): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->setFontBold();
        $row = fn (array $v, ?Style $s = null) => Row::fromValues($v, $s);

        $writer->getCurrentSheet()->setName('B2B');
        $writer->addRow($row(['GSTIN/UIN of Recipient', 'Receiver Name', 'Invoice Number', 'Invoice date', 'Invoice Value',
            'Place Of Supply', 'Reverse Charge', 'Applicable % of Tax Rate', 'Invoice Type', 'E-Commerce GSTIN', 'Rate',
            'Taxable Value', 'Cess Amount', 'Ref No'], $bold));
        foreach ($b2b as $r) {
            $writer->addRow($row([
                $r['GST NUMBER'], $r['CUSNAME'], (string) $r['INV NO'], Carbon::instance($r['INV DATE'])->format('d-M-y'),
                round($r['TAXABLE AMOUNT'] + $r['GST (3%)'], 2), $placeOfSupply, 'N', '', 'Regular B2B', '',
                GstAuditorReport::GST_PCT, $r['TAXABLE AMOUNT'], 0, (string) $r['REF NO'],
            ]));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('B2CS');
        $writer->addRow($row(['Type', 'Place Of Supply', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount', 'E-Commerce GSTIN'], $bold));
        if ($b2c) {
            $writer->addRow($row(['OE', $placeOfSupply, '', GstAuditorReport::GST_PCT, round(array_sum(array_column($b2c, 'TAXABLE AMOUNT')), 2), 0, '']));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('HSN');
        $writer->addRow($row(['HSN', 'Description', 'UQC', 'Total Quantity', 'Total Value', 'Taxable Value', 'Integrated Tax Amount',
            'Central Tax Amount', 'State/UT Tax Amount', 'Cess Amount'], $bold));
        $all = array_merge($b2c, $b2b);
        if ($all) {
            $taxable = round(array_sum(array_column($all, 'TAXABLE AMOUNT')), 2);
            $gst = round(array_sum(array_column($all, 'GST (3%)')), 2);
            // Value-denominated rows (legacy digital-gold bills carry ₹ value as "weight" at ₹1/g) have no gram figure.
            $grams = 0.0;
            $valueRows = 0;
            foreach ($all as $r) {
                $rate = (float) ($r['GOLD RATE/G'] ?? $r['ITEM RATE/G'] ?? 0);
                if ($rate > 1) {
                    $grams += (float) $r['GOLD WEIGHT(GM)'];
                } else {
                    $valueRows++;
                }
            }
            $writer->addRow($row([GstAuditorReport::HSN, 'Articles of jewellery of gold (BIS 916 HUID)', 'GMS',
                round($grams, 3), round($taxable + $gst, 2), $taxable, 0, round($gst / 2, 2), round($gst / 2, 2), 0]));
            if ($valueRows) {
                $writer->addRow($row(['', 'Quantity covers rows priced per gram; '.$valueRows.' value-denominated row(s) (rate ₹1/g) carry no gram figure.']));
            }
        }
        $writer->close();
    }
}
