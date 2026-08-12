<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\WalletWithdrawal;
use App\Services\Lbox\WalletWithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cash-wallet withdrawal through the branch L-BOX static QR (no gateway):
 * the member scans (or uploads a photo of) the branch QR, requests an amount,
 * and the incharge hands over cash/gold at the counter.
 *
 * Scan & Pay from digi metal is retired (board 2026-08-11) — the QR now only
 * serves withdrawals; Digi Market moves money strictly wallet ↔ metal.
 */
class WalletController extends Controller
{
    public function __construct(protected WalletWithdrawalService $withdrawals)
    {
    }

    /** GET /member/wallet/qr/{uuid} — what the scanned QR points at (confirm screen). */
    public function resolveQr(Request $request, string $uuid): JsonResponse
    {
        $member = $this->member($request);

        try {
            $device = $this->withdrawals->resolveQr($uuid);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'device' => $device->name,
            'branch' => $device->branch?->name,
            'wallet_balance' => (float) ($member->wallet?->cash_balance ?? 0),
        ]);
    }

    /** POST /member/wallet/withdraw — {device_uuid, amount}. */
    public function withdraw(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $data = $request->validate([
            'device_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        try {
            $withdrawal = $this->withdrawals->request($member, $data['device_uuid'], (float) $data['amount']);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'withdrawal' => $this->present($withdrawal->fresh('branch')),
            'wallet_balance' => (float) $member->wallet()->first()->cash_balance,
        ], 201);
    }

    /** GET /member/wallet/withdrawals — newest first. */
    public function withdrawals(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $rows = WalletWithdrawal::where('member_id', $member->id)
            ->with('branch')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (WalletWithdrawal $w) => $this->present($w)),
        ]);
    }

    protected function present(WalletWithdrawal $w): array
    {
        return [
            'id' => $w->id,
            'branch' => $w->branch?->name,
            'amount' => (float) $w->amount,
            'status' => $w->status,
            'disbursal_mode' => $w->disbursal_mode,
            'requested_at' => $w->created_at->toIso8601String(),
            'disbursed_at' => optional($w->disbursed_at)->toIso8601String(),
        ];
    }

    protected function member(Request $request): Member
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403, 'This area is for distributors.');

        return $user;
    }
}
