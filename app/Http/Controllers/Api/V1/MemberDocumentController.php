<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bond;
use App\Models\Member;
use App\Models\RedemptionInvoice;
use App\Services\Contract\ContractService;
use App\Services\Redeem\RedemptionInvoicePdf;
use App\Support\Translatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Document Vault (Phase 2b) — a member's contracts and tax invoices as PDFs.
 *
 * The list is token-authed; each item carries a short-lived SIGNED download URL so
 * the app can open the PDF directly in a viewer/browser without juggling the bearer
 * token. The signature covers the member id, so a link can't be re-pointed to
 * another member's document.
 */
class MemberDocumentController extends Controller
{
    public const LINK_TTL_MINUTES = 15;

    /** GET /member/documents — the member's contracts + redemption invoices. */
    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $contracts = Bond::where('member_id', $member->id)
            ->whereHas('plan', fn ($q) => $q->where('is_contract', true))
            ->with('plan')->latest('bond_date')->get()
            ->map(fn (Bond $b) => [
                'type' => 'contract',
                'id' => $b->id,
                'title' => trim((Translatable::pick($b->plan?->name) ?: $b->plan?->code ?: 'Plan') . ' — Contract'),
                'reference' => $b->invoice_no,
                'date' => optional($b->bond_date)->toDateString(),
                'download_url' => $this->signedUrl('contract', $b->id, $member->id),
            ]);

        $invoices = RedemptionInvoice::where('member_id', $member->id)->latest('invoice_date')->get()
            ->map(fn (RedemptionInvoice $i) => [
                'type' => 'redemption_invoice',
                'id' => $i->id,
                'title' => 'Tax Invoice — ' . $i->invoice_no,
                'reference' => $i->invoice_no,
                'date' => optional($i->invoice_date)->toDateString(),
                'download_url' => $this->signedUrl('redemption_invoice', $i->id, $member->id),
            ]);

        $docs = $contracts->concat($invoices)
            ->sortByDesc('date')
            ->values();

        return response()->json(['data' => $docs]);
    }

    /** GET /member/documents/{type}/{id} (signed) — stream the PDF, scoped to the member. */
    public function download(Request $request, string $type, int $id)
    {
        $memberId = (int) $request->query('m');

        return match ($type) {
            'contract' => app(ContractService::class)->stream(
                Bond::where('id', $id)->where('member_id', $memberId)->firstOrFail()
            ),
            'redemption_invoice' => app(RedemptionInvoicePdf::class)->stream(
                RedemptionInvoice::where('id', $id)->where('member_id', $memberId)->firstOrFail()
            ),
            default => abort(404),
        };
    }

    protected function signedUrl(string $type, int $id, int $memberId): string
    {
        return URL::temporarySignedRoute(
            'api.member.document',
            now()->addMinutes(self::LINK_TTL_MINUTES),
            ['type' => $type, 'id' => $id, 'm' => $memberId],
        );
    }

    protected function member(Request $request): Member
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403, 'This area is for distributors.');

        return $user;
    }
}
