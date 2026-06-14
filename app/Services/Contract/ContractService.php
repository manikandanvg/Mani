<?php

namespace App\Services\Contract;

use App\Models\Bond;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the member contract / MOU (legacy "CONTRACT" PDF) for a bond. Used for
 * download from the admin, and for storing a public copy whose URL is sent over
 * WhatsApp as the contract media message.
 */
class ContractService
{
    protected function pdf(Bond $bond)
    {
        $bond->loadMissing(['plan', 'member', 'branch', 'mou', 'memberContract']);

        // Prefer the stored per-sale snapshot; fall back to building it live (old bonds).
        $contract = $bond->memberContract;
        $body = $contract?->content ?: app(ContractContentBuilder::class)->build($bond);

        $contractNo = $contract?->contract_no ?? $bond->invoice_no;

        return Pdf::setOptions([
            'isRemoteEnabled' => true,
            'isFontSubsettingEnabled' => true,
            'chroot' => storage_path(),
            'defaultFont' => 'DejaVu Sans',
        ])->loadView('pdf.contract', [
            'bond' => $bond,
            'contractBody' => $body,
            'contractNo' => $contractNo,
            'barcode' => $this->barcode($contractNo),
        ])->setPaper('a4');
    }

    /** Code-128 barcode of the contract number as a base64 PNG data URI (for the PDF). */
    protected function barcode(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        try {
            $png = (new \Picqer\Barcode\BarcodeGeneratorPNG)
                ->getBarcode($value, \Picqer\Barcode\BarcodeGeneratorPNG::TYPE_CODE_128, 2, 50);

            return 'data:image/png;base64,' . base64_encode($png);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Stream/download the contract inline. */
    public function download(Bond $bond)
    {
        return $this->pdf($bond)->download($this->filename($bond));
    }

    public function stream(Bond $bond)
    {
        return $this->pdf($bond)->stream($this->filename($bond));
    }

    /** Save to the public disk and return the absolute URL (for WhatsApp media). */
    public function store(Bond $bond): string
    {
        $path = 'contracts/' . $this->filename($bond);
        Storage::disk('public')->put($path, $this->pdf($bond)->output());

        return Storage::disk('public')->url($path);
    }

    protected function filename(Bond $bond): string
    {
        return 'contract-' . str_replace(['/', '\\', ' '], '-', (string) $bond->invoice_no) . '.pdf';
    }
}
