<?php

namespace App\Services\Payment;

use App\Models\Member;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Push\Notifier;
use App\Support\Money;

/**
 * Storefront order payment (native app + web, 2026-08-24). One place that
 * (a) lazily creates the Razorpay order for an unpaid order and (b) verifies
 * a Checkout result and settles the order. The web pay page and the app's
 * native Razorpay SDK both run through here, so an order is settled the same
 * way whichever client paid it. The webhook (payment.captured) is the safety
 * net for a client that never reported back.
 */
class OrderPaymentService
{
    public function __construct(protected RazorpayService $razorpay) {}

    /** True when the order still needs an online payment and the gateway can take it. */
    public function payable(Order $order): bool
    {
        return $order->payment_status === 'unpaid'
            && (float) $order->total > 0
            && $this->razorpay->configured();
    }

    /**
     * Create the gateway order once and remember its id. Returns null when the
     * gateway is off / the call failed — callers show "try again".
     */
    public function ensureGatewayOrder(Order $order): ?string
    {
        if ($order->razorpay_order_id) {
            return $order->razorpay_order_id;
        }
        if (! $this->payable($order)) {
            return null;
        }

        $rzp = $this->razorpay->createOrder((float) $order->total, $order->order_no, ['order_no' => $order->order_no]);
        if (! $rzp) {
            return null;
        }
        $order->update(['razorpay_order_id' => $rzp['id']]);

        return $rzp['id'];
    }

    /**
     * Everything the native Razorpay Checkout needs, minus the secret. Null when
     * the order cannot be paid online right now.
     *
     * @return array{key_id:string,razorpay_order_id:string,amount_paise:int,currency:string,name:string,description:string,prefill:array{name:?string,email:?string,contact:?string}}|null
     */
    public function checkoutOptions(Order $order): ?array
    {
        $gatewayOrderId = $this->ensureGatewayOrder($order);
        if (! $gatewayOrderId) {
            return null;
        }

        return [
            'key_id' => (string) $this->razorpay->keyId(),
            'razorpay_order_id' => $gatewayOrderId,
            'amount_paise' => (int) round((float) $order->total * 100),
            'currency' => 'INR',
            'name' => (string) config('app.name', 'LORDICL'),
            'description' => 'Order ' . $order->order_no,
            'prefill' => [
                'name' => $order->customer_name,
                'email' => $order->email,
                'contact' => $order->phone,
            ],
        ];
    }

    /**
     * Verify a Checkout result and settle the order. Idempotent: an order the
     * webhook already settled returns true without a second Payment row.
     * Returns false (and records a failed Payment) when the signature is bad.
     */
    public function verify(Order $order, string $gatewayOrderId, string $paymentId, string $signature): bool
    {
        if ($order->payment_status === 'paid') {
            return true;
        }

        $valid = $gatewayOrderId === $order->razorpay_order_id
            && $this->razorpay->verifySignature($gatewayOrderId, $paymentId, $signature);

        $payment = Payment::firstOrNew(['razorpay_order_id' => $gatewayOrderId]);
        $payment->fill([
            'order_id' => $order->id, 'gateway' => 'razorpay',
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
            'amount' => $order->total, 'currency' => 'INR',
        ]);

        if ($valid) {
            $details = $this->razorpay->fetchPayment($paymentId);
            $payment->status = 'paid';
            $payment->method = $details['method'] ?? null;
            $payment->email = $details['email'] ?? $order->email;
            $payment->contact = $details['contact'] ?? $order->phone;
            $payment->meta = $details;
            $payment->save();

            $order->update(['payment_status' => 'paid', 'status' => 'confirmed', 'paid_at' => now()]);

            // Payment-successful acknowledgement (board 2026-08-11) — only members have
            // an app inbox; guest checkouts see the on-screen confirmation instead.
            if ($order->member_id) {
                Notifier::to(
                    Member::find($order->member_id),
                    'order',
                    'Payment successful — ' . $order->order_no,
                    Money::inr((float) $order->total) . ' received. Your order is confirmed and will be processed shortly.',
                    route: '/orders/' . $order->id,
                );
            }
            // HQ bell: a paid order is ready for fulfilment.
            Notifier::admins(
                'Online order paid — ' . $order->order_no,
                ($order->customer_name ?: 'A customer') . ' paid ' . Money::inr((float) $order->total)
                    . '. Process it under Online Orders → Order Management.',
                url: '/admin/order-management',
                category: 'order',
            );

            return true;
        }

        $payment->status = 'failed';
        $payment->save();

        // Payment-failed acknowledgement for member orders (board 2026-08-11).
        if ($order->member_id) {
            Notifier::to(
                Member::find($order->member_id),
                'order',
                'Payment failed — ' . $order->order_no,
                'Your payment could not be verified and was not captured. Please try again; no amount will be charged twice.',
                route: '/orders/' . $order->id,
            );
        }

        return false;
    }
}
