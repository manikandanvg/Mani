<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated member's profile for the mobile app. Kept lean — the heavy
 * business data (earnings, genealogy, bonds) gets its own endpoints in later phases.
 */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $wallet = $this->wallet;

        return [
            'id' => $this->id,
            'member_code' => $this->member_code,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'status' => $this->status,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch?->id,
                'name' => $this->branch?->name,
            ]),
            'rank' => $this->whenLoaded('rank', fn () => [
                'id' => $this->rank?->id,
                'name' => $this->rank?->name,
            ]),
            'bv' => (float) $this->bv,
            'gbv' => (float) $this->gbv,
            // Profile card (item 21) + Re-KYC gate (item 18), board 2026-08-12.
            'dob' => optional($this->dob)->toDateString(),
            'address' => $this->address,
            'city' => $this->city,
            'pincode' => $this->pincode,
            'upi' => $this->upi,
            'photo_url' => ProductResource::imageUrl($this->photo_path),
            'kyc' => [
                'verified' => (bool) $this->pan_verified && (bool) $this->aadhaar_verified,
                'pan_verified' => (bool) $this->pan_verified,
                'aadhaar_verified' => (bool) $this->aadhaar_verified,
                'aadhaar_doc_uploaded' => filled($this->aadhaar_doc_path),
                'rekyc_required' => \App\Models\KycSetting::rekycRequiredFor($this->resource),
            ],
            // Payroll (2026-07): drives the app's Attendance/Payslip cards.
            'is_employee' => (bool) ($this->employeeProfile && $this->employeeProfile->status === 'active'),
            'wallet' => $wallet ? [
                'currency_code' => $wallet->currency_code ?: 'INR',
                'cash_balance' => (float) $wallet->cash_balance,
                'epin_balance' => (float) $wallet->epin_balance,
                'coupon_balance' => (float) $wallet->coupon_balance,
                'earning_total' => (float) $wallet->earning_total,
                'digi_gold_grams' => (float) ($wallet->digi_gold_grams ?? 0),
                'digi_silver_grams' => (float) ($wallet->digi_silver_grams ?? 0),
            ] : null,
        ];
    }
}
