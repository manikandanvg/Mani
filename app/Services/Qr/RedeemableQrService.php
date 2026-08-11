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
     *
     * Worth (schema v2): RD gold-QR plans (rd_qr_grams) are worth the configured gold
     * weight at the live rate incl. making/wastage/GST; contract-split plans are worth
     * the allocation_cont % of the billed value; everything else the full billed value.
     */
    public function forBond(Bond $bond, float $goldRate = 0.0): ?RedeemableQr
    {
        if ($existing = RedeemableQr::where('bond_id', $bond->id)->first()) {
            return $existing;
        }

        $plan = $bond->loadMissing('plan')->plan;

        // G11 PLUS2-style plans mint their ONE QR at the first renewal (RdCollectionService).
        // Never auto-mint here — a "Show QR" click used to create a phantom billing QR,
        // giving the member two QRs where the scheme promises one.
        if (($plan->rd_qr_on ?? null) === 'first_renewal') {
            return null;
        }
        $grand = (float) ($bond->epin_value ?: $bond->value);
        $cashWorth = match (true) {
            $plan && (float) $plan->rd_qr_grams > 0 => app(GoldQrPricing::class)->price($plan),
            $plan && (float) $plan->allocation_cont > 0 => round($grand * (float) $plan->allocation_cont / 100, 2),
            default => $grand,
        };
        // Plan-defined piece weight wins (100 mg plan ⇒ 0.1000 g exactly); the
        // rate-division fallback is only for QRs with no predefined gold weight.
        $gramWorth = match (true) {
            $plan && (float) $plan->rd_qr_grams > 0 => (float) $plan->rd_qr_grams,
            $goldRate > 0 => round($cashWorth / $goldRate, 4),
            default => null,
        };

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

    /**
     * Mint a SETTLEMENT gold QR at an explicit worth (maturity benefit — e.g. 70/80% of
     * the contract amount, or the RD paid-total + bonus). Unlike forBond this is never
     * idempotent: a bond can hold a sale QR and a settlement QR side by side.
     */
    public function mintSettlementQr(Bond $bond, float $worth): RedeemableQr
    {
        // Gram worth = the plan's PREDEFINED piece (100 mg ⇒ 0.1000) — the cash worth
        // includes charges/GST, so dividing by the rate would overstate the gold
        // (₹1,380.20 ÷ ₹13,400 = 0.103 g ≠ the 0.100 g coin the QR redeems).
        $plan = $bond->loadMissing('plan')->plan;
        $planGrams = (float) ($plan->rd_qr_grams ?? 0);
        $goldRate = (float) (\App\Models\LiveRate::latestFor('IN')?->gold ?? 0);

        return RedeemableQr::create([
            'bond_id' => $bond->id,
            'member_id' => $bond->member_id,
            'branch_id' => $bond->branch_id,
            'invoice_no' => $bond->invoice_no,
            'qr_code' => $this->uniqueToken(),
            'qr_mode' => 'gold',
            'gram_worth' => $planGrams > 0
                ? $planGrams
                : ($goldRate > 0 ? round($worth / $goldRate, 4) : null),
            'cash_worth' => round($worth, 2),
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
    public function deliver(RedeemableQr $qr, bool $withContract = true): array
    {
        try {
            $qr->loadMissing('member', 'bond.plan');
            $member = $qr->member;
            if (! $member) {
                return ['ok' => false, 'message' => 'QR has no distributor attached.'];
            }

            // Board 2026-08-11: WhatsApp is OTP-only — documents go out as app push +
            // inbox entries carrying the download URLs (the app renders/downloads them).

            // 1) Contract PDF — only for contract-bearing plans (schema-v2 is_contract);
            //    settlement QRs pass withContract=false (the contract went out at sale).
            if ($withContract && $qr->bond && $qr->bond->plan?->is_contract) {
                \App\Services\Push\Notifier::to($member, 'contract',
                    'Your contract ' . $qr->invoice_no,
                    'Dear ' . ($member->name ?? 'Distributor') . ', your Lord Jeweller contract is ready. Tap to view.',
                    route: '/contracts/' . $qr->bond_id,
                    data: ['bond_id' => (string) $qr->bond_id, 'contract_url' => $this->contracts->store($qr->bond)],
                );
            }

            // 2) Redeemable Stock QR
            \App\Services\Push\Notifier::to($member, 'qr',
                'Redeemable Gold QR ' . $qr->qr_code,
                $this->caption($qr),
                route: '/qrs/' . $qr->id,
                data: [
                    'qr_id' => (string) $qr->id,
                    'qr_code' => (string) $qr->qr_code,
                    'image_url' => $this->imageUrl($qr),
                    'gram_worth' => (string) $qr->gram_worth,
                    'cash_worth' => (string) $qr->cash_worth,
                ],
            );

            $qr->forceFill(['qr_sent' => true, 'sent_at' => Carbon::now()])->save();

            return ['ok' => true, 'message' => 'sent to app inbox + push', 'results' => []];
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

        // Where can it be redeemed? Mode-A (gold/silver purchase plans) = the billing
        // branch ONLY; savings QRs = any dealer. Tell the member the truth.
        $plan = $qr->bond?->plan;
        $branchLocked = $plan && in_array($plan->type, ['gold', 'silver'], true);
        $where = $branchLocked
            ? 'Please redeem at your billing branch: ' . ($qr->branch?->name ?? 'the branch that billed you') . '.'
            : 'Please contact your nearest Lord Jeweller Dealer.';

        return "REDEEMABLE STOCK QR : {$qr->qr_code}"
            . "\nWI : {$gram}"
            . "\nWorth ₹. " . number_format((float) $qr->cash_worth, 2)
            . "\nMode : " . strtoupper($qr->qr_mode)
            . "\nUser : {$name} ({$code})"
            . "\n\nThis QR requires an OTP sent to your mobile number to redeem it."
            . "\n\n" . $where;
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
