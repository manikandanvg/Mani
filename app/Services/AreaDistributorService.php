<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Member;
use App\Models\Plan;
use App\Models\SalesInvoice;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Push\Notifier;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Complaint 2026-11-11 (1) — legacy parity (Trade::generatesales, plan 211 "AREA DEALER"):
 * billing the Area Distributor scheme opens the dealer's shop on the spot —
 *
 *   branch   a copy of Head Office's name / address / GST, phone = the member's phone,
 *            in-charge "NAME / MEMBER CODE", level area_dealer, supplied by Head Office
 *   login    a distributor login for that branch, mapped to the member code
 *   stock    the items billed on the invoice become the new branch's opening stock
 *
 * and the login is handed over immediately: shown to the biller on the Sales screen and
 * sent to the member as app push + inbox (board: WhatsApp is OTP-only). Legacy used the
 * fixed password 123456; here every login gets its own temporary password.
 *
 * A member who already runs a branch gets no second branch — a repeat Area Distributor bill
 * only adds its items to the stock of the Area Distributor branch they already have.
 */
class AreaDistributorService
{
    public const LEVEL = 'area_dealer';
    public const STOCK_REF = 'area_opening';

    public function appliesTo(?Plan $plan): bool
    {
        return $plan && Branch::levelForHid($plan->hid) === self::LEVEL;
    }

    /** The dealer login already mapped to this member, if any. */
    public function loginOf(Member $member): ?User
    {
        return User::where('member_code', $member->member_code)->whereNotNull('branch_id')->orderBy('id')->first();
    }

    /**
     * Run inside the billing transaction. $pieces = catalog_product_id => pieces billed.
     *
     * @return array{branch_id:int,branch:string,user_id:int,email:string,password:?string,created:bool,member_code:string}|null
     */
    public function open(Member $member, Plan $plan, SalesInvoice $invoice, array $pieces, ?int $byUserId = null): ?array
    {
        if (! $this->appliesTo($plan)) {
            return null;
        }

        $login = $this->loginOf($member);
        if ($login) {
            $branch = Branch::find($login->branch_id);
            if (! $branch || $branch->level !== self::LEVEL) {
                return null;   // already a dealer of another level — nothing to open, no stock hand-over
            }
            $this->receiveStock($branch, $invoice, $pieces, $byUserId);

            return ['branch_id' => $branch->id, 'branch' => $branch->pickLabel(), 'user_id' => $login->id, 'email' => $login->email,
                'password' => null, 'created' => false, 'member_code' => (string) $member->member_code];
        }

        $hq = Branch::where('level', 'hq')->orderBy('id')->first() ?? Branch::find(SalesService::HQ_BRANCH_ID);
        $branch = Branch::create([
            'name' => $hq?->name ?: 'LORD JEWELLER',
            'level' => self::LEVEL,
            'kind' => Branch::KIND_DEALER,
            'source_branch_id' => null,   // supplied by Head Office
            'address' => $hq?->address,
            'city' => $hq?->city,
            'pincode' => $hq?->pincode,
            'country' => $hq?->country ?: 'IN',
            'phone' => $member->phone ? mb_substr((string) $member->phone, 0, 20) : null,
            'incharge' => mb_substr(trim((string) $member->name) . ' / ' . $member->member_code, 0, 200),
            'gst_no' => $hq?->gst_no,
            'currency_code' => $hq?->currency_code ?: 'INR',
            'tax_regime' => $hq?->tax_regime ?: 'gst',
            'is_active' => true,
            'opened_invoice_id' => $invoice->id,
        ]);

        $password = Str::password(8, letters: true, numbers: true, symbols: false, spaces: false);
        $user = new User([
            'name' => mb_substr((string) $member->name, 0, 150),
            'email' => $this->emailFor($member),
            'phone' => $member->phone ? mb_substr((string) $member->phone, 0, 20) : null,
            'status' => 'active',
            'branch_id' => $branch->id,
            'designation' => 'AREA DEALER',
            'member_code' => $member->member_code,
            'invested' => round((float) $invoice->grand_total * (float) ($invoice->fx_rate ?: 1), 2),
            'locale' => 'en',
            'currency_code' => 'INR',
        ]);
        $user->forceFill(['password' => $password])->save();   // 'hashed' cast
        Role::findOrCreate('distributor', 'web');
        $user->assignRole('distributor');

        $this->receiveStock($branch, $invoice, $pieces, $byUserId);

        ActivityLogger::log('branch', 'area_distributor_opened',
            sprintf('Area Distributor branch #%d + login %s opened for %s by invoice %s', $branch->id, $user->email, $member->member_code, $invoice->invoice_no), [
                'subject' => $branch, 'member' => $member->id,
                'details' => ['invoice' => $invoice->invoice_no, 'user_id' => $user->id, 'email' => $user->email],   // never the password
            ]);

        return ['branch_id' => $branch->id, 'branch' => $branch->pickLabel(), 'user_id' => $user->id, 'email' => $user->email,
            'password' => $password, 'created' => true, 'member_code' => (string) $member->member_code];
    }

    /** After commit: the login goes to the member's app (push + inbox). */
    public function sendLogin(Member $member, array $opened): void
    {
        if (empty($opened['created']) || empty($opened['password'])) {
            return;
        }
        Notifier::to($member, 'system', 'Your Area Distributor login', implode("\n", $this->loginLines($opened)), null, ['sensitive' => true]);
    }

    /** The lines shown to the biller and sent to the member. */
    public function loginLines(array $opened): array
    {
        return [
            'Branch: ' . $opened['branch'],
            'Login: ' . $opened['email'],
            'Password: ' . $opened['password'],
            'Sign in: ' . rtrim((string) config('app.url'), '/') . '/admin/login',
            'Please change the password after signing in.',
        ];
    }

    /** Pieces billed on an invoice, from its sale movements (else its lines). */
    public function piecesOfInvoice(SalesInvoice $invoice): array
    {
        $out = [];
        foreach (StockMovement::where('ref_type', 'sale')->where('ref_id', $invoice->id)->get() as $m) {
            $out[$m->catalog_product_id] = ($out[$m->catalog_product_id] ?? 0) + abs((float) $m->qty_change);
        }
        if ($out) {
            return $out;
        }
        foreach ($invoice->lines as $line) {
            if (! $line->catalog_product_id) {
                continue;
            }
            $cp = CatalogProduct::withTrashed()->find($line->catalog_product_id);
            $out[$line->catalog_product_id] = ($out[$line->catalog_product_id] ?? 0)
                + ($cp ? $cp->piecesFromWeight((float) $line->qty) : (float) $line->qty);
        }

        return $out;
    }

    /** Stock handed to an Area Distributor branch by this invoice: catalog_product_id => pieces, per branch. */
    public function stockOpenedBy(SalesInvoice $invoice): array
    {
        $out = [];
        foreach (StockMovement::where('ref_type', self::STOCK_REF)->where('ref_id', $invoice->id)->get() as $m) {
            $out[$m->branch_id][$m->catalog_product_id] = ($out[$m->branch_id][$m->catalog_product_id] ?? 0) + (float) $m->qty_change;
        }

        return $out;
    }

    /** Why the bill's Area Distributor hand-over cannot be undone, or null when it can. */
    public function cancelBlocker(SalesInvoice $invoice): ?string
    {
        $opened = Branch::where('opened_invoice_id', $invoice->id)->first();
        if ($opened && SalesInvoice::where('branch_id', $opened->id)->where('status', '!=', 'cancelled')->exists()) {
            return 'The Area Distributor branch opened by this bill has already billed customers — cancel those bills first.';
        }
        foreach ($this->stockOpenedBy($invoice) as $branchId => $items) {
            foreach ($items as $productId => $pieces) {
                $held = (float) Stock::where('branch_id', $branchId)->where('catalog_product_id', $productId)->where('order_line_id', 0)->value('quantity');
                if ($held + 0.0001 < $pieces) {
                    return 'The Area Distributor branch has already used the stock this bill handed over.';
                }
            }
        }

        return null;
    }

    /** Undo open(): take the opening stock back and close the branch + login this bill opened. */
    public function reverse(SalesInvoice $invoice, ?int $byUserId = null): void
    {
        foreach ($this->stockOpenedBy($invoice) as $branchId => $items) {
            foreach ($items as $productId => $pieces) {
                if ($pieces <= 0) {
                    continue;
                }
                $stock = Stock::where('branch_id', $branchId)->where('catalog_product_id', $productId)->where('order_line_id', 0)->first();
                if (! $stock) {
                    continue;
                }
                $stock->decrement('quantity', $pieces);
                StockMovement::create([
                    'branch_id' => $branchId, 'catalog_product_id' => $productId, 'type' => 'adjustment',
                    'qty_change' => -$pieces, 'balance_after' => $stock->fresh()->quantity,
                    'ref_type' => 'sale_cancel', 'ref_id' => $invoice->id,
                    'note' => 'Invoice ' . $invoice->invoice_no . ' cancelled — opening stock taken back',
                    'moved_on' => now()->toDateString(), 'created_by' => $byUserId,
                ]);
            }
        }

        $opened = Branch::where('opened_invoice_id', $invoice->id)->first();
        if ($opened) {
            $opened->update(['is_active' => false]);
            User::where('branch_id', $opened->id)->update(['status' => 'inactive']);
            ActivityLogger::log('branch', 'area_distributor_closed',
                sprintf('Area Distributor branch #%d and its login closed — invoice %s cancelled', $opened->id, $invoice->invoice_no), ['subject' => $opened]);
        }
    }

    protected function receiveStock(Branch $branch, SalesInvoice $invoice, array $pieces, ?int $byUserId): void
    {
        foreach ($pieces as $productId => $qty) {
            $qty = (float) $qty;
            if (! $productId || $qty <= 0) {
                continue;
            }
            $stock = Stock::firstOrCreate(
                ['branch_id' => $branch->id, 'catalog_product_id' => $productId, 'order_line_id' => 0],
                ['quantity' => 0],
            );
            $stock->increment('quantity', $qty);
            StockMovement::create([
                'branch_id' => $branch->id, 'catalog_product_id' => $productId, 'type' => 'purchase',
                'qty_change' => $qty, 'balance_after' => $stock->fresh()->quantity,
                'ref_type' => self::STOCK_REF, 'ref_id' => $invoice->id,
                'note' => 'Area Distributor stock — invoice ' . $invoice->invoice_no,
                'moved_on' => \Illuminate\Support\Carbon::parse($invoice->date)->toDateString(), 'created_by' => $byUserId,
            ]);
        }
    }

    /** member-code@lordicl.com (legacy: lordicl@member-code, which is not a valid e-mail). */
    protected function emailFor(Member $member): string
    {
        $base = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', (string) $member->member_code)) ?: 'dealer' . $member->id;
        $email = $base . '@lordicl.com';
        for ($i = 2; User::withTrashed()->where('email', $email)->exists(); $i++) {
            $email = $base . '.' . $i . '@lordicl.com';
        }

        return $email;
    }
}
