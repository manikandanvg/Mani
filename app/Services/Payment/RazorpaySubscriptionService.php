<?php

namespace App\Services\Payment;

use App\Models\Bond;
use App\Models\RdMandate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Razorpay Subscriptions — recurring e-mandate (UPI AutoPay / eNACH) for RD renewals.
 * Sits beside RazorpayService (one-time orders) and shares the same account/keys/auth.
 *
 * A Plan (monthly, per installment amount) + a Subscription per RD bond. Razorpay drives
 * the schedule and sends the RBI pre-debit notice; each successful charge arrives as a
 * `subscription.charged` webhook (see RazorpayWebhookController).
 */
class RazorpaySubscriptionService
{
    protected string $base = 'https://api.razorpay.com/v1';

    public function configured(): bool
    {
        return filled(config('services.razorpay.key')) && filled(config('services.razorpay.secret'));
    }

    /** A monthly Razorpay Plan for a per-installment amount (reused across savers). */
    public function createPlan(float $amountInr, string $name): ?array
    {
        return $this->post('/plans', [
            'period' => 'monthly',
            'interval' => 1,
            'item' => [
                'name' => $name,
                'amount' => (int) round($amountInr * 100),   // paise
                'currency' => 'INR',
            ],
        ]);
    }

    /**
     * Register an auto-debit mandate for an RD bond: create (or reuse) the plan, then a
     * subscription for the remaining installments. Returns a saved RdMandate with the
     * customer authorisation link (short_url). No money moves until the customer approves.
     */
    public function enrol(Bond $bond): ?RdMandate
    {
        $bond->loadMissing(['member', 'plan']);
        $amount = (float) $bond->value;
        $total = (int) ($bond->lvlcom_count ?: 0);
        $remaining = max(1, $total - $bond->rdEntries()->count());

        $plan = $this->createPlan($amount, 'RD ' . ($bond->invoice_no ?: $bond->id));
        if (! $plan || empty($plan['id'])) {
            return null;
        }

        $sub = $this->post('/subscriptions', [
            'plan_id' => $plan['id'],
            'total_count' => $remaining,
            'customer_notify' => 1,
            'notes' => ['bond_id' => (string) $bond->id, 'invoice_no' => (string) $bond->invoice_no],
        ]);
        if (! $sub || empty($sub['id'])) {
            return null;
        }

        return RdMandate::updateOrCreate(
            ['bond_id' => $bond->id, 'status' => 'created'],
            [
                'member_id' => $bond->member_id,
                'branch_id' => $bond->branch_id,
                'gateway' => 'razorpay',
                'razorpay_plan_id' => $plan['id'],
                'razorpay_subscription_id' => $sub['id'],
                'amount' => $amount,
                'total_count' => $remaining,
                'status' => $sub['status'] ?? 'created',
                'short_url' => $sub['short_url'] ?? null,
            ],
        );
    }

    public function fetchSubscription(string $id): ?array
    {
        return $this->get('/subscriptions/' . $id);
    }

    public function cancel(string $id, bool $atCycleEnd = false): ?array
    {
        return $this->post('/subscriptions/' . $id . '/cancel', ['cancel_at_cycle_end' => $atCycleEnd ? 1 : 0]);
    }

    /** Verify a Razorpay webhook payload: HMAC-SHA256(body) === X-Razorpay-Signature header. */
    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.razorpay.webhook_secret');
        if ($secret === '' || blank($signature)) {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    // --- low-level HTTP (basic-auth with key/secret, same as RazorpayService) ---

    protected function post(string $path, array $payload): ?array
    {
        return $this->call('post', $path, $payload);
    }

    protected function get(string $path): ?array
    {
        return $this->call('get', $path);
    }

    protected function call(string $method, string $path, array $payload = []): ?array
    {
        try {
            $req = Http::withOptions(['verify' => app_ca()])
                ->withBasicAuth((string) config('services.razorpay.key'), (string) config('services.razorpay.secret'))
                ->timeout(20);

            $res = $method === 'get' ? $req->get($this->base . $path) : $req->post($this->base . $path, $payload);

            return $res->throw()->json();
        } catch (\Throwable $e) {
            Log::warning("Razorpay subscription {$method} {$path} failed: " . $e->getMessage());

            return null;
        }
    }
}
