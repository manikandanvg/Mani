<?php

namespace App\Http\Controllers;

use App\Models\DigiGoldPurchase;
use App\Services\Payment\RazorpayService;
use App\Services\Wallet\DigiMarketService;
use Illuminate\Http\Request;

/**
 * Browser side of the app's digi-gold buy (Phase 3). The app opens the SIGNED pay
 * page (30-min expiry, purchase id baked into the signature) in the device browser,
 * Razorpay Checkout runs there, and verify credits the grams. The app itself polls
 * GET /api/v1/digigold/purchases/{id} — the browser page is only the payment leg.
 */
class DigiGoldPayController extends Controller
{
    /** GET /digigold/{purchase}/pay — signed. */
    public function pay(DigiGoldPurchase $purchase, RazorpayService $razorpay)
    {
        if ($purchase->status === 'paid') {
            return view('digigold.done', ['purchase' => $purchase]);
        }

        return view('digigold.pay', [
            'purchase' => $purchase,
            'razorpayKey' => $razorpay->keyId(),
            'amountPaise' => (int) round((float) $purchase->amount * 100),
        ]);
    }

    /** POST /digigold/payment/verify — Checkout JS handler posts here. */
    public function verify(Request $request, DigiMarketService $digiGold)
    {
        $data = $request->validate([
            'purchase_id' => 'required|integer',
            'razorpay_payment_id' => 'required|string',
            'razorpay_order_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $purchase = DigiGoldPurchase::findOrFail($data['purchase_id']);
        abort_unless($purchase->razorpay_order_id === $data['razorpay_order_id'], 422);

        try {
            $purchase = $digiGold->completePurchase($purchase, $data['razorpay_payment_id'], $data['razorpay_signature']);
        } catch (\RuntimeException $e) {
            return view('digigold.done', ['purchase' => $purchase->refresh(), 'error' => $e->getMessage()]);
        }

        return view('digigold.done', ['purchase' => $purchase]);
    }
}
