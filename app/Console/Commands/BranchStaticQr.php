<?php

namespace App\Console\Commands;

use App\Models\Branch;
use Illuminate\Console\Command;

/**
 * Board 2026-10-31: launch without L-BOX — every dealer branch gets its static payment QR
 * (the same QR an L-BOX would carry; the app scans it under Wallet → Withdraw at branch).
 *
 *   php artisan branches:static-qr            # all active dealer branches without an active device
 *   php artisan branches:static-qr --all      # Lord's Branches too
 */
class BranchStaticQr extends Command
{
    protected $signature = 'branches:static-qr {--all : Include Lord\'s Branches (default: dealers only)}';

    protected $description = "Generate the static payment QR (virtual L-BOX device) for every dealer branch";

    public function handle(): int
    {
        $branches = Branch::where('is_active', true)
            ->when(! $this->option('all'), fn ($q) => $q->where('kind', Branch::KIND_DEALER))
            ->orderBy('name')->get();

        [$made, $had] = [0, 0];
        foreach ($branches as $b) {
            $before = $b->staticQrDevice();
            $device = $b->ensureStaticQr();
            $before ? $had++ : $made++;
            app(\App\Services\Qr\QrCodeService::class)->store($device->uuid, "lbox-{$device->serial_no}");   // PNG ready for the app
        }
        $this->info("Static QR ready for {$branches->count()} branch(es): {$made} generated, {$had} already had a device.");
        $this->line('Print: Master → Branches → Static QR. The app store list carries payment_qr_url.');

        return self::SUCCESS;
    }
}
