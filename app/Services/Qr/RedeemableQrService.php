<?php

namespace App\Services\Qr;

use App\Models\Bond;
use App\Models\RedeemableQr;
use App\Services\Contract\ContractService;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Redeemable Stock QR — mint a QR token per bond, render its PNG, and deliver the
 * Contract PDF + QR image over WhatsApp. Faithful to the legacy Lscript "GOLD/INVOICE"
 * digi_queue insert + Qr::sendingpendingqr, but instant (not cron) and de-"Digi"-ed.
 */
class RedeemableQrService
{
    public function __construct(
        protected QrCodeService $qr,
        protected WhatsappSender $whatsapp,
        protected ContractService $contracts,
    ) {}

    /**
     * Get (or create) the redeemable QR for a bond. Idempotent: one QR per bond.
     * $goldRate (₹/gram) is used to express the worth in grams when available.
     */
    public function forBond(Bond $bond, float $goldRate = 0.0): RedeemableQr
    {
        if ($existing = RedeemableQr::where('bond_id', $bond->id)->first()) {
            return $existing;
        }

        $cashWorth = (float) ($bond->epin_value ?: $bond->value);
        $gramWorth = $goldRate > 0 ? round($cashWorth / $goldRate, 4) : null;

        return RedeemableQr::create([
            'bond_id' => $bond->id,
            'member_id' => $bond->member_id,
            'branch_id' => $bond->branch_id,
            'invoice_no' => $bond->invoice_no,
            'qr_code' => $this->uniqueToken(),
            'qr_mode' => 'gold',
            'gram_worth' => $gramWorth,
            'cash_worth' => $cashWorth,
            'status' => 'pending',
            'qr_sent' => false,
        ]);
    }

    /** Public URL of the QR PNG (rendered + cached on the public disk). */
    public function imageUrl(RedeemableQr $qr): string
    {
        return $this->qr->store($qr->qr_code);
    }

    /**
     * Deliver the Contract PDF and the redeemable QR to the member over WhatsApp, instantly.
     * Best-effort: never throws into billing. Marks qr_sent on success. The actual recipient
     * is governed by config('services.whatsapp.test_recipient') during testing.
     */
    public function deliver(RedeemableQr $qr): array
    {
        try {
            $qr->loadMissing('member', 'bond');
            $phone = (string) ($qr->member?->phone ?? '');
            if ($phone === '') {
                return ['ok' => false, 'message' => 'Distributor has no phone number.'];
            }

            $results = [];

            // 1) Contract PDF
            if ($qr->bond) {
                $results['contract'] = $this->whatsapp->sendMedia(
                    $phone,
                    $this->contracts->store($qr->bond),
                    'Dear ' . ($qr->member?->name ?? 'Distributor') . ', your Lord Jeweller contract '
                        . $qr->invoice_no . ' is attached.'
                );
            }

            // 2) Redeemable Stock QR
            $results['qr'] = $this->whatsapp->sendMedia($phone, $this->imageUrl($qr), $this->caption($qr));

            $ok = ($results['qr']['ok'] ?? false);
            if ($ok) {
                $qr->forceFill(['qr_sent' => true, 'sent_at' => Carbon::now()])->save();
            }

            // Surface the gateway's reason (e.g. "Instance ID Invalidated") to the caller/UI.
            $message = $results['qr']['message'] ?? $results['contract']['message'] ?? ($ok ? 'sent' : 'failed');

            return ['ok' => $ok, 'message' => $message, 'results' => $results];
        } catch (\Throwable $e) {
            Log::warning('Redeemable QR delivery failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** The WhatsApp caption — mirrors the legacy QR message. */
    public function caption(RedeemableQr $qr): string
    {
        $name = $qr->member?->name ?? 'Distributor';
        $code = $qr->member?->member_code ?? '';
        $gram = $qr->gram_worth !== null ? number_format((float) $qr->gram_worth, 3) . 'gm' : '—';

        return "REDEEMABLE STOCK QR : {$qr->qr_code}"
            . "\nWI : {$gram}"
            . "\nWorth ₹. " . number_format((float) $qr->cash_worth, 2)
            . "\nMode : " . strtoupper($qr->qr_mode)
            . "\nUser : {$name} ({$code})"
            . "\n\nThis QR requires an OTP sent to your mobile number to redeem it."
            . "\n\nPlease contact your nearest Lord Jeweller Dealer.";
    }

    /** Unique 8-char uppercase alphanumeric token (legacy random_string('alnum', 8)). */
    protected function uniqueToken(): string
    {
        do {
            $token = strtoupper(Str::random(8));
        } while (RedeemableQr::where('qr_code', $token)->exists());

        return $token;
    }
}
