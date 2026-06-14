<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Razorpay gateway — server-side order creation + signature verification. The key
 * SECRET never leaves the server; only the public key id is exposed to Checkout JS.
 * Config: services.razorpay.{key,secret}. Amounts are charged in INR (paise).
 */
class RazorpayService
{
    public function configured(): bool
    {
        return filled(config('services.razorpay.key')) && filled(config('services.razorpay.secret'));
    }

    public function keyId(): ?string
    {
        return config('services.razorpay.key');
    }

    /**
     * Create a Razorpay order. $amountInr is in rupees (major units).
     *
     * @return array{id:string,amount:int,currency:string}|null
     */
    public function createOrder(float $amountInr, string $receipt, array $notes = []): ?array
    {
        try {
            $res = Http::withOptions(['verify' => app_ca()])
                ->withBasicAuth((string) config('services.razorpay.key'), (string) config('services.razorpay.secret'))
                ->timeout(20)
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount' => (int) round($amountInr * 100),   // paise
                    'currency' => 'INR',
                    'receipt' => $receipt,
                    'notes' => $notes,
                    'payment_capture' => 1,
                ])->throw()->json();

            if (! empty($res['id'])) {
                return ['id' => $res['id'], 'amount' => (int) $res['amount'], 'currency' => $res['currency'] ?? 'INR'];
            }
        } catch (\Throwable $e) {
            Log::warning('Razorpay create order failed: ' . $e->getMessage());
        }

        return null;
    }

    /** Verify the Checkout signature: HMAC-SHA256(order_id|payment_id) === signature. */
    public function verifySignature(string $orderId, string $paymentId, string $signature): bool
    {
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, (string) config('services.razorpay.secret'));

        return hash_equals($expected, $signature);
    }

    /** Optional: fetch payment details (method, email, contact) after capture. */
    public function fetchPayment(string $paymentId): ?array
    {
        try {
            return Http::withOptions(['verify' => app_ca()])
                ->withBasicAuth((string) config('services.razorpay.key'), (string) config('services.razorpay.secret'))
                ->timeout(15)
                ->get('https://api.razorpay.com/v1/payments/' . $paymentId)
                ->throw()->json();
        } catch (\Throwable $e) {
            Log::warning('Razorpay fetch payment failed: ' . $e->getMessage());

            return null;
        }
    }
}
