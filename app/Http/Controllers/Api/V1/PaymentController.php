<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\DigiGoldPurchase;
use App\Models\Member;
use App\Models\Order;
use App\Services\Payment\OrderPaymentService;
use App\Services\Wallet\DigiMarketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Native in-app payments (2026-08-24). The app runs Razorpay's native Checkout
 * SDK itself — no browser leg — so it needs (1) the Checkout options for an
 * unpaid order / digi purchase and (2) a JSON verify that settles it. The
 * secret never leaves the server; the app only sees the public key id and the
 * gateway order id. The payment.captured webhook still settles anything the
 * app failed to report back.
 */
class PaymentController extends Controller
{
    public function __construct(protected OrderPaymentService $orders, protected DigiMarketService $market) {}

    /** POST /orders/{order}/payment/intent — Checkout options for the native SDK. */
    public function orderIntent(Request $request, Order $order): JsonResponse
    {
        abort_unless($this->owns($request, $order), 404);

        if ($order->payment_status === 'paid') {
            return response()->json(['status' => 'paid', 'order' => new OrderResource($order->load('items'))]);
        }
        if (! $this->orders->payable($order)) {
            return response()->json(['message' => 'This order cannot be paid online right now.'], 422);
        }

        $options = $this->orders->checkoutOptions($order);
        if (! $options) {
            return response()->json(['message' => 'Could not start the payment — please try again.'], 503);
        }

        return response()->json(['status' => 'unpaid', 'checkout' => $options]);
    }

    /** POST /orders/{order}/payment/verify — settle from the SDK's success result. */
    public function orderVerify(Request $request, Order $order): JsonResponse
    {
        abort_unless($this->owns($request, $order), 404);
        $data = $this->checkoutResult($request);

        $ok = $this->orders->verify($order, $data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature']);
        $order->refresh()->load('items');

        if (! $ok) {
            return response()->json([
                'message' => 'Payment could not be verified. Nothing was charged twice — please try again.',
                'order' => new OrderResource($order),
            ], 422);
        }

        return response()->json(['status' => 'paid', 'order' => new OrderResource($order)]);
    }

    /** POST /digimarket/purchases/{purchase}/verify — credit grams from the SDK result. */
    public function digiVerify(Request $request, DigiGoldPurchase $purchase): JsonResponse
    {
        $member = $request->user();
        abort_unless($member instanceof Member, 403, 'This area is for distributors.');
        abort_unless((int) $purchase->member_id === (int) $member->id, 404);
        $data = $this->checkoutResult($request);

        if ($purchase->razorpay_order_id !== $data['razorpay_order_id']) {
            return response()->json(['message' => 'Payment does not belong to this purchase.'], 422);
        }

        try {
            $purchase = $this->market->completePurchase($purchase, $data['razorpay_payment_id'], $data['razorpay_signature']);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'purchase' => DigiGoldController::presentPurchase($purchase->refresh()),
            ], 422);
        }

        return response()->json([
            'purchase' => DigiGoldController::presentPurchase($purchase),
            'grams_balance' => (float) ($member->wallet()->first()?->{$this->market->gramsColumn($purchase->metal)} ?? 0),
        ]);
    }

    /** The three fields Razorpay Checkout hands back on success. */
    protected function checkoutResult(Request $request): array
    {
        return $request->validate([
            'razorpay_payment_id' => 'required|string',
            'razorpay_order_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);
    }

    protected function owns(Request $request, Order $order): bool
    {
        $user = $request->user();

        return $user instanceof Member
            ? (int) $order->member_id === (int) $user->id
            : (int) $order->customer_id === (int) $user->id;
    }
}
