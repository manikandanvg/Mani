<?php

namespace App\Filament\Pages;

use App\Services\BranchOrderService;
use Filament\Notifications\Notification;

/**
 * Trade — Branch Sales (old system admin/trade/branchsales, asked back by the user 2026-11-22).
 * Head Office allocates stock DIRECTLY to any branch: pick the branch, add the items, save —
 * the stock is in that branch at once, with no order request from the dealer and no approval
 * step. Same cart and same server-side pricing as the Order Form; the allocation is kept as an
 * approved order of type "Branch Sales (HQ)" in Order Requests and in the stock ledger.
 * The supplier is always Head Office. Head Office staff only.
 */
class BranchSales extends OrderForm
{
    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Trade';

    protected static ?string $navigationLabel = 'Branch Sales';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Branch Sales — allocate stock directly to a branch';

    protected static ?string $slug = 'branch-sales';

    protected static string $view = 'filament.pages.branch-sales';

    public static function canAccess(): bool
    {
        return static::hqUser();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::hqUser();
    }

    public function getSubheading(): ?string
    {
        return 'Head Office only. The stock is added to the selected branch immediately — no order request, no approval.';
    }

    /** Nothing pre-selected: the receiving branch is always chosen on purpose. */
    protected function defaultBranchId(): ?int
    {
        return null;
    }

    /** Head Office allocates to the dealer branches, not to itself. */
    protected function branchOptions(bool $withHq = true): array
    {
        return parent::branchOptions(false);
    }

    protected function branchLabel(): string
    {
        return 'Branch that receives the stock';
    }

    protected function branchHelp(): string
    {
        return 'Any branch can be selected. Supplied by Head Office.';
    }

    /**
     * User 2026-11-22: Head Office sells directly — no URN, no "amount paid on this URN" and no
     * payment-proof attachment are asked (both sections are hidden); the payment mode and the
     * remarks are all that is recorded.
     */
    protected function urnRequired(): bool
    {
        return false;
    }

    protected function paymentOptions(): array
    {
        return ['cash' => 'Cash', 'cheque' => 'Cheque', 'online' => 'Online', 'others' => 'Others'];
    }

    public function save(): void
    {
        abort_unless(static::hqUser(), 403);
        $data = $this->form->getState();

        try {
            $request = app(BranchOrderService::class)->allocate([
                'branch_id' => $data['branch_id'] ?? null,
                'payment_type' => $data['payment_type'] ?? 'cash',
                'payment_remarks' => $data['payment_remarks'] ?? null,
                'lines' => $data['lines'] ?? [],
            ], auth()->user());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            Notification::make()->danger()->title('Stock not allocated')->body($e->getMessage())->persistent()->send();

            return;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Stock not allocated')
                ->body('Something went wrong — nothing was saved. Please try again.')->send();

            return;
        }

        Notification::make()->success()
            ->title('Stock allocated — ' . $request->request_no)
            ->body(($request->branch?->name ?? 'The branch') . ' received ' . $request->no_of_items . ' item(s) worth ₹'
                . \App\Support\Money::group((float) $request->grand_total) . '. It is listed under Order Requests as "Branch Sales (HQ)".')
            ->persistent()
            ->send();

        $this->form->fill($this->blankState());
    }
}
