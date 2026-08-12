<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DigiGoldPurchase;
use App\Models\DigiGoldTxn;
use App\Models\Member;
use App\Services\Wallet\DigiMarketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Digi Market — gold + silver investment purses (board 2026-08-11).
 *
 * Buy by amount or by weight, funded from the cash wallet (instant) or online
 * (signed web pay page → Razorpay Checkout → app polls until paid). Withdraw
 * converts metal back to the cash wallet at the live rate minus the platform
 * fee — always previewed via /quote first. Scan & Pay from metal is retired.
 */
class DigiGoldController extends Controller
{
    public function __construct(protected DigiMarketService $market)
    {
    }

    /** GET /digimarket — both purses, live rates, fee, recent ledger. */
    public function summary(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $wallet = $member->wallet;

        $metals = [];
        foreach (DigiMarketService::METALS as $metal) {
            try {
                $rate = $this->market->rate($metal);
            } catch (\RuntimeException) {
                $rate = 0.0;
            }
            $grams = (float) ($wallet?->{$this->market->gramsColumn($metal)} ?? 0);
            $invested = (float) DigiGoldTxn::where('member_id', $member->id)
                ->where('metal', $metal)->where('type', 'credit')->sum('value');
            $released = (float) DigiGoldTxn::where('member_id', $member->id)
                ->where('metal', $metal)->where('type', 'debit')->sum('value');

            $metals[$metal] = [
                'rate' => $rate,
                'grams' => $grams,
                'value' => round($grams * $rate, 2),
                // net money put in so far — lets the app show grown/sliced value
                'invested' => round($invested - $released, 2),
            ];
        }

        return response()->json([
            'metals' => $metals,
            'cash_balance' => (float) ($wallet?->cash_balance ?? 0),
            'min_buy' => DigiMarketService::MIN_BUY_INR,
            'platform_fee_pct' => DigiMarketService::platformFeePct(),
            'txns' => DigiGoldTxn::where('member_id', $member->id)
                ->orderByDesc('id')->limit(30)->get()
                ->map(fn (DigiGoldTxn $t) => [
                    'id' => $t->id,
                    'metal' => $t->metal,
                    'type' => $t->type,
                    'grams' => (float) $t->grams,
                    'rate' => (float) $t->rate,
                    'value' => (float) $t->value,
                    'fee' => (float) $t->fee,
                    'source' => $t->source,
                    'at' => $t->created_at->toIso8601String(),
                ]),
        ]);
    }

    /**
     * POST /digimarket/quote — {metal, amount?|grams?, direction?: buy|withdraw}.
     * Withdraw quotes include the platform fee + net credited.
     */
    public function quote(Request $request): JsonResponse
    {
        $this->member($request);
        $data = $this->quoteInput($request);

        try {
            $q = $data['direction'] === 'withdraw'
                ? $this->market->withdrawQuote($data['metal'], $data['amount'], $data['grams'])
                : $this->market->quote($data['metal'], $data['amount'], $data['grams']);

            return response()->json($q);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /digimarket/buy — {metal, amount?|grams?, funding: wallet|online}.
     * Wallet funding settles instantly; online returns a signed browser pay URL.
     */
    public function buy(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $data = $this->quoteInput($request, [
            'funding' => ['required', 'in:wallet,online'],
        ]);

        try {
            // "By weight" buys convert to amount at the live rate first.
            $amount = $data['amount'] ?? $this->market->quote($data['metal'], null, $data['grams'])['amount'];
            $purchase = $this->market->beginPurchase($member, $data['metal'], (float) $amount, $data['funding']);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $wallet = $member->wallet()->first();

        return response()->json(array_filter([
            'purchase' => $this->present($purchase),
            'pay_url' => $purchase->status === 'created'
                ? URL::temporarySignedRoute('digigold.pay', now()->addMinutes(30), ['purchase' => $purchase->id])
                : null,
            'cash_balance' => (float) ($wallet?->cash_balance ?? 0),
            'grams_balance' => (float) ($wallet?->{$this->market->gramsColumn($purchase->metal)} ?? 0),
        ], fn ($v) => $v !== null), 201);
    }

    /** POST /digimarket/withdraw — {metal, amount?|grams?} → cash wallet, fee applied. */
    public function withdraw(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $data = $this->quoteInput($request);

        try {
            $txn = $this->market->withdrawToWallet($member, $data['metal'], $data['grams'], $data['amount']);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $wallet = $member->wallet()->first();

        return response()->json([
            'ok' => true,
            'txn' => [
                'id' => $txn->id,
                'metal' => $txn->metal,
                'grams' => (float) $txn->grams,
                'rate' => (float) $txn->rate,
                'value' => (float) $txn->value,
                'fee' => (float) $txn->fee,
                'net' => round((float) $txn->value - (float) $txn->fee, 2),
            ],
            'cash_balance' => (float) ($wallet?->cash_balance ?? 0),
            'grams_balance' => (float) ($wallet?->{$this->market->gramsColumn($txn->metal)} ?? 0),
        ], 201);
    }

    /** GET /digimarket/purchases/{purchase} — poll until paid/failed. */
    public function purchase(Request $request, DigiGoldPurchase $purchase): JsonResponse
    {
        $member = $this->member($request);
        abort_unless((int) $purchase->member_id === (int) $member->id, 404);

        return response()->json([
            'purchase' => $this->present($purchase),
            'grams_balance' => (float) ($member->wallet()->first()?->{$this->market->gramsColumn($purchase->metal)} ?? 0),
        ]);
    }

    /** Validated {metal, amount|grams, direction} shared by quote/buy/withdraw. */
    protected function quoteInput(Request $request, array $extra = []): array
    {
        $data = $request->validate($extra + [
            'metal' => ['required', 'in:gold,silver'],
            'amount' => ['nullable', 'required_without:grams', 'numeric', 'min:1'],
            'grams' => ['nullable', 'required_without:amount', 'numeric', 'min:0.0001'],
            'direction' => ['nullable', 'in:buy,withdraw'],
        ]);

        return $data + ['amount' => null, 'grams' => null, 'direction' => 'buy'];
    }

    protected function present(DigiGoldPurchase $p): array
    {
        return [
            'id' => $p->id,
            'metal' => $p->metal,
            'funding' => $p->funding,
            'amount' => (float) $p->amount,
            'rate' => (float) $p->rate,
            'grams' => (float) $p->grams,
            'status' => $p->status,
            'paid_at' => optional($p->paid_at)->toIso8601String(),
            'created_at' => $p->created_at->toIso8601String(),
        ];
    }

    protected function member(Request $request): Member
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403, 'This area is for distributors.');

        return $user;
    }
}
