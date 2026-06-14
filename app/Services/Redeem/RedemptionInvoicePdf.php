<?php

namespace App\Services\Redeem;

use App\Models\RedemptionInvoice;
use App\Models\Setting;
use App\Services\Qr\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * Renders the redemption TAX INVOICE (the legacy "12124" format) for a redeemed stock QR:
 * branded header, line table (HSN / weight / rate / making / amount), an HSN-wise
 * CGST+SGST (1.5% + 1.5%) summary, amount-in-words, and the seller branch + bank block.
 */
class RedemptionInvoicePdf
{
    public function stream(RedemptionInvoice $invoice)
    {
        return $this->pdf($invoice)->stream($this->filename($invoice));
    }

    public function download(RedemptionInvoice $invoice)
    {
        return $this->pdf($invoice)->download($this->filename($invoice));
    }

    protected function pdf(RedemptionInvoice $invoice)
    {
        $invoice->loadMissing(['lines.catalogProduct', 'member', 'branch', 'bond.plan', 'qr']);

        return Pdf::setOptions([
            'isRemoteEnabled' => true,
            'isFontSubsettingEnabled' => true,
            'chroot' => storage_path(),
            'defaultFont' => 'DejaVu Sans',
        ])->loadView('pdf.redemption-invoice', [
            'invoice' => $invoice,
            'seller' => $this->seller($invoice),
            'company' => $this->company(),
            'hsn' => $this->hsnSummary($invoice),
            'amountWords' => $this->rupeesInWords((float) $invoice->grand_total),
            'taxWords' => $this->rupeesInWords((float) $invoice->cgst + (float) $invoice->sgst),
            'barcode' => $this->barcode($invoice->invoice_no),
            'qrInvoice' => $this->qr(route('redemption.pdf', $invoice)),
            'qrPlay' => $this->qr('https://play.google.com/store/apps/details?id=com.app.lordicl'),
        ])->setPaper('a4');
    }

    /** Seller = the redeeming branch (name / address / GSTIN). */
    protected function seller(RedemptionInvoice $invoice): array
    {
        $b = $invoice->branch;

        return [
            'name' => $b?->name ?? 'LORD ICL',
            'address' => trim(implode(', ', array_filter([$b?->address, $b?->city, $b?->pincode]))),
            'phone' => $b?->phone,
            'gst_no' => $b?->gst_no,
        ];
    }

    /** Company-level branding (logo + bank block), read defensively from settings. */
    protected function company(): array
    {
        $kv = Setting::where('group', 'company')->pluck('value', 'key');

        return [
            'name' => $kv['name'] ?? 'LORD JEWELLER',
            'legal_name' => $kv['legal_name'] ?? 'LORDICL GLOBAL GOLD PRIVATE LIMITED',
            'tagline' => $kv['tagline'] ?? 'Blessed gold',
            'logo' => $this->logoDataUri($kv['logo'] ?? null),
            'bank_name' => $kv['bank_name'] ?? null,
            'bank_account' => $kv['bank_account'] ?? null,
            'bank_ifsc' => $kv['bank_ifsc'] ?? null,
            'bank_branch' => $kv['bank_branch'] ?? null,
        ];
    }

    protected function logoDataUri(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }
        $abs = storage_path('app/public/' . ltrim($path, '/'));
        if (! is_file($abs)) {
            return null;
        }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'png';

        return 'data:image/' . $ext . ';base64,' . base64_encode((string) file_get_contents($abs));
    }

    /**
     * HSN-wise tax summary. Per line: taxable = amount + making + wastage; the GST
     * already baked into line_total is split equally into CGST + SGST.
     *
     * @return array<int,array{hsn:string,rate:float,taxable:float,cgst:float,sgst:float}>
     */
    protected function hsnSummary(RedemptionInvoice $invoice): array
    {
        $rows = [];
        foreach ($invoice->lines as $l) {
            $hsn = (string) ($l->hsn_code ?: '7113');
            $taxable = round((float) $l->amount + (float) $l->making + (float) $l->wastage, 2);
            $gst = round((float) $l->line_total - $taxable, 2);
            $rows[$hsn] ??= ['hsn' => $hsn, 'rate' => (float) $l->gst_pct, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0];
            $rows[$hsn]['taxable'] += $taxable;
            $rows[$hsn]['cgst'] += round($gst / 2, 2);
            $rows[$hsn]['sgst'] += round($gst / 2, 2);
        }

        return array_values($rows);
    }

    protected function filename(RedemptionInvoice $invoice): string
    {
        return 'tax-invoice-' . str_replace(['/', '\\', ' '], '-', (string) $invoice->invoice_no) . '.pdf';
    }

    /** Code-39 barcode of the invoice number as a base64 PNG data URI (top-right of the invoice). */
    protected function barcode(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        try {
            $png = (new BarcodeGeneratorPNG)->getBarcode($value, BarcodeGeneratorPNG::TYPE_CODE_39, 2, 50);

            return 'data:image/png;base64,' . base64_encode($png);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** A QR PNG (data URI) for the given text — used for the two bottom QR codes. */
    protected function qr(string $text): ?string
    {
        try {
            return 'data:image/png;base64,' . base64_encode(app(QrCodeService::class)->png($text));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Indian-format rupees in words, e.g. 1,03,000 -> "One Lakh Three Thousand Rupees Only". */
    public function rupeesInWords(float $amount): string
    {
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        $words = trim($this->numToWords($rupees));
        $out = ($words === '' ? 'Zero' : $words) . ' Rupees';
        if ($paise > 0) {
            $out .= ' and ' . trim($this->numToWords($paise)) . ' Paise';
        }

        return $out . ' Only';
    }

    protected function numToWords(int $n): string
    {
        if ($n === 0) {
            return '';
        }
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $two = function (int $x) use ($ones, $tens): string {
            if ($x < 20) {
                return $ones[$x];
            }

            return trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
        };

        $three = function (int $x) use ($ones, $two): string {
            $h = intdiv($x, 100);
            $r = $x % 100;

            return trim(($h ? $ones[$h] . ' Hundred' . ($r ? ' ' : '') : '') . ($r ? $two($r) : ''));
        };

        $parts = [];
        $crore = intdiv($n, 10000000);
        $n %= 10000000;
        $lakh = intdiv($n, 100000);
        $n %= 100000;
        $thousand = intdiv($n, 1000);
        $n %= 1000;

        if ($crore) {
            $parts[] = $this->numToWords($crore) . ' Crore';
        }
        if ($lakh) {
            $parts[] = $two($lakh) . ' Lakh';
        }
        if ($thousand) {
            $parts[] = $two($thousand) . ' Thousand';
        }
        if ($n) {
            $parts[] = $three($n);
        }

        return trim(implode(' ', $parts));
    }
}
