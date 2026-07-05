<?php

namespace App\Services\Payroll;

use App\Models\Payslip;
use App\Models\Setting;
use App\Services\Redeem\RedemptionInvoicePdf;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders one payslip as an A4 PDF: company header, employee block, attendance,
 * earnings vs statutory deductions (PF / ESI / TDS), net pay + amount in words.
 * Streamed from the admin panel and (signed-URL) from the app.
 */
class PayslipPdf
{
    public function stream(Payslip $payslip)
    {
        return response()->streamDownload(
            fn () => print($this->pdf($payslip)->output()),
            $this->filename($payslip),
            ['Content-Type' => 'application/pdf'],
            'inline',
        );
    }

    protected function pdf(Payslip $payslip)
    {
        @ini_set('memory_limit', '512M');
        $payslip->loadMissing(['run', 'employee.member.branch', 'employee.rank']);

        return Pdf::setOptions(['defaultFont' => 'DejaVu Sans'])
            ->loadView('pdf.payslip', [
                'slip' => $payslip,
                'run' => $payslip->run,
                'employee' => $payslip->employee,
                'member' => $payslip->employee?->member,
                'company' => $this->company(),
                'netWords' => app(RedemptionInvoicePdf::class)->rupeesInWords((float) $payslip->net),
            ])->setPaper('a4');
    }

    protected function company(): array
    {
        $kv = Setting::where('group', 'company')->pluck('value', 'key');

        return [
            'name' => $kv['name'] ?? 'LORD JEWELLER',
            'legal_name' => $kv['legal_name'] ?? 'LORDICL GLOBAL GOLD PRIVATE LIMITED',
        ];
    }

    protected function filename(Payslip $payslip): string
    {
        return 'payslip-' . $payslip->run?->periodLabel() . '-' . ($payslip->employee?->employee_code ?? $payslip->id) . '.pdf';
    }
}
