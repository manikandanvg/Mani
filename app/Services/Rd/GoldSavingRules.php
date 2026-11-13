<?php

namespace App\Services\Rd;

use App\Models\Bond;
use App\Models\Plan;
use App\Services\Qr\GoldQrPricing;
use App\Support\Money;

/**
 * Gold Saving (G11) rules — Lord's board 2026-11-13, in force from 1 Nov 2026.
 *
 *   PLUS 1 (rd_qr_on 'always')         joining = whole 100 mg pieces × the day's 100 mg price
 *                                      (live rate + making + wastage + GST, the App price list
 *                                      figure). Pieces are fixed for the scheme; every renewal
 *                                      collects pieces × that day's price and gives a gold QR.
 *   PLUS 2 (rd_qr_on 'first_renewal')  joining in rd_step (₹1,500) multiples; month 2 collects
 *                                      pieces × that day's 100 mg price and gives the gold QR;
 *                                      every other month collects the joining amount.
 *   PLUS 3 (no gold QR)                joining from min_value in rd_step (₹500) steps; every
 *                                      renewal is the joining amount.
 *
 * No renewal amount is editable. A bond from before these rules has no rd_pieces: its pieces
 * are bond value ÷ ₹1,500, the basis it joined on in the old system.
 */
class GoldSavingRules
{
    public const PLUS1 = 'plus1';

    public const PLUS2 = 'plus2';

    public const PLUS3 = 'plus3';

    /** Old-system basis: one 100 mg piece per ₹1,500 of the bond value. */
    public const LEGACY_UNIT_VALUE = 1500;

    public static function variant(?Plan $plan): ?string
    {
        if (! $plan || $plan->type !== 'rd') {
            return null;
        }
        if ((float) $plan->rd_qr_grams > 0) {
            return $plan->rd_qr_on === 'first_renewal' ? self::PLUS2 : self::PLUS1;
        }

        return self::PLUS3;
    }

    /** The day's price of one piece (100 mg) incl. making, wastage and GST. */
    public static function unitPrice(Plan $plan, ?float $goldRate = null): float
    {
        return app(GoldQrPricing::class)->price($plan, 'IN', $goldRate);
    }

    /**
     * Is this joining amount (INR) allowed for the plan?
     *
     * @return array{ok: bool, pieces: int|null, message: string|null}
     */
    public static function joining(Plan $plan, float $amount, ?float $goldRate = null): array
    {
        $variant = self::variant($plan);
        if ($variant === null) {
            return ['ok' => true, 'pieces' => null, 'message' => null];
        }
        $amount = round($amount, 2);

        if ($variant === self::PLUS1) {
            $price = self::unitPrice($plan, $goldRate);
            $pieces = $price > 0 ? (int) round($amount / $price) : 0;
            $ok = $pieces >= 1 && abs($amount - round($pieces * $price, 2)) < 0.005;

            return ['ok' => $ok, 'pieces' => $ok ? $pieces : null, 'message' => $ok ? null : sprintf(
                'Scheme %s is billed in whole 100 mg pieces at today\'s price: %s. ₹%s is not one of these.',
                $plan->code, self::amountsHint($plan, $goldRate), Money::group($amount))];
        }

        $step = (float) $plan->rd_step;
        $min = (float) $plan->min_value;
        if ($step <= 0) {
            return ['ok' => true, 'pieces' => $variant === self::PLUS2 ? max(1, intdiv((int) floor($amount), self::LEGACY_UNIT_VALUE)) : null, 'message' => null];
        }
        $units = $amount / $step;
        $ok = $amount + 0.005 >= $min && abs($units - round($units)) < 0.0001;

        return ['ok' => $ok, 'pieces' => $ok && $variant === self::PLUS2 ? (int) round($units) : null, 'message' => $ok ? null : sprintf(
            'Scheme %s is billed at %s. ₹%s is not one of these.', $plan->code, self::amountsHint($plan), Money::group($amount))];
    }

    /** "₹1,835.67 / ₹3,671.34 / ₹5,507.01 …" — the amounts the counter may bill today. */
    public static function amountsHint(Plan $plan, ?float $goldRate = null): string
    {
        $variant = self::variant($plan);
        if ($variant === self::PLUS1) {
            try {
                $price = self::unitPrice($plan, $goldRate);
            } catch (\Throwable) {
                return 'no live gold rate set';
            }

            return '₹' . Money::group($price) . ' (1 × 100 mg) / ₹' . Money::group(round($price * 2, 2)) . ' / ₹' . Money::group(round($price * 3, 2)) . ' …';
        }
        $step = (float) $plan->rd_step;
        $min = max((float) $plan->min_value, $step);
        if ($variant === null || $step <= 0) {
            return 'any amount from ₹' . Money::group((float) ($plan->min_value ?? 0));
        }

        return '₹' . Money::group($min) . ' / ₹' . Money::group($min + $step) . ' / ₹' . Money::group($min + 2 * $step) . ' …';
    }

    /** 100 mg pieces of a PLUS 1 / PLUS 2 bond (0 for PLUS 3). */
    public static function pieces(Bond $bond): int
    {
        $variant = self::variant($bond->plan ?? $bond->load('plan')->plan);
        if ($variant === null || $variant === self::PLUS3) {
            return 0;
        }

        return (int) $bond->rd_pieces > 0
            ? (int) $bond->rd_pieces
            : max(1, intdiv((int) floor((float) $bond->value), self::LEGACY_UNIT_VALUE));
    }

    /** The first month's due — the renewal amount of the fixed months and the bonus-month value. */
    public static function firstDue(Bond $bond): float
    {
        return round((float) $bond->value, 2);
    }
}
