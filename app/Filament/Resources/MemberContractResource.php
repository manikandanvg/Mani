<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Resources\MemberContractResource\Pages;
use App\Models\MemberContract;
use App\Support\Translatable;
use Filament\Resources\Resource;
use Filament\Forms;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only register of member CONTRACTS (created at billing for is_contract plans).
 * Exists chiefly so the settlement engine's outcomes are visible and actionable:
 * 'matured' contracts await the manual withdraw/renewal decision (dealerships check
 * opening stock by hand); 'closed' ones were settled automatically.
 */
class MemberContractResource extends BaseResource
{
    protected static ?string $model = MemberContract::class;

    protected static ?string $navigationGroup = 'Sales & Contracts';

    protected static ?string $navigationLabel = 'Settlements';

    protected static ?string $modelLabel = 'Settlement';

    protected static ?string $pluralModelLabel = 'Settlements';

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    /** Matured contracts need a human decision — surface the count on the nav item. */
    public static function getNavigationBadge(): ?string
    {
        $n = MemberContract::where('status', 'matured')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['member', 'plan', 'branch', 'bond', 'settlements.qr.redemptionInvoices.branch',
            'rdSettlement.qr.redemptionInvoices.branch']);

        // Branch scope: a distributor login sees only its own branch's contracts.
        $user = auth()->user();
        if ($user && method_exists($user, 'isDistributor') && $user->isDistributor() && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            // User 2026-11-19: every contract, in sequence — earliest end date first, so the
            // expired ones lead and the next to expire follow.
            ->defaultSort(fn (Builder $query) => $query->orderBy('end_date')->orderBy('id'))
            ->columns([
                Tables\Columns\TextColumn::make('contract_no')->label('Contract')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('member.member_code')->label('User ID')->searchable()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('member.name')->label('Distributor')->searchable(),
                Tables\Columns\TextColumn::make('member.phone')->label('Phone')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('plan.code')->label('Scheme')
                    ->description(fn (MemberContract $r) => Translatable::pick($r->plan?->name))
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('start_date')->label('Start')->date()->sortable(),
                Tables\Columns\TextColumn::make('end_date')->label('End')->date()->sortable(),
                Tables\Columns\TextColumn::make('settled_on')->label('Settled')->date()->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'active' => 'success',
                        'matured' => 'warning',
                        'closed' => 'gray',
                        default => 'gray',
                    }),
                // Board phase 2 (2026-08-28): the admin-entered settlement credited to the wallet.
                Tables\Columns\TextColumn::make('settlement_amount')
                    ->label('Settlement')
                    ->state(fn (MemberContract $r) => ($r->settlements->sum('amount') + (float) ($r->rdSettlement?->worth ?? 0)) ?: null)
                    ->summarize(Tables\Columns\Summarizers\Summarizer::make()->label('Total settled')
                        ->using(fn (\Illuminate\Database\Query\Builder $query) => '₹' . \App\Support\Money::group(
                            (float) \Illuminate\Support\Facades\DB::table('contract_settlements')
                                ->whereIn('member_contract_id', $query->clone()->select('id'))->sum('amount')
                            + (float) \Illuminate\Support\Facades\DB::table('rd_settlements')
                                ->whereIn('member_contract_id', $query->clone()->select('id'))->sum('worth'))))
                    ->description(fn (MemberContract $r) => match (true) {
                        (bool) $r->rdSettlement => ($r->rdSettlement->mode === 'manual' ? 'Settled manually (no QR)' : ucfirst($r->rdSettlement->metal) . ' QR')
                            . ' · ' . $r->rdSettlement->dues_paid . '/' . $r->rdSettlement->dues_total . ' dues'
                            . ($r->rdSettlement->coins_missing ? ' · ' . $r->rdSettlement->coins_missing . ' coin(s) not returned' : ''),
                        $r->settlements->contains(fn ($s) => in_array($s->mode, ['gold_qr', 'manual'], true)) => $r->settlements
                            ->map(fn ($s) => ($s->mode === 'manual' ? 'Manual (no QR)' : 'Gold QR') . ' · ' . ($s->stage ?: 'settled'))->implode(' + '),
                        $r->settlements->isNotEmpty() => 'Cash wallet',
                        default => null,
                    })
                    ->formatStateUsing(fn ($state) => $state ? '₹' . \App\Support\Money::group((float) $state) : null)
                    ->placeholder(fn (MemberContract $r) => $r->isExpired() ? __('Expired — not settled') : '—')
                    ->color('success'),
                // User 2026-11-19: once the settlement QR is redeemed, its tax invoice shows here
                // (click = the invoice PDF), with the branch that redeemed it.
                Tables\Columns\TextColumn::make('redeemed_invoice')
                    ->label('Redeemed invoice')
                    ->state(function (MemberContract $r) {
                        $qrs = $r->settlements->pluck('qr')->push($r->rdSettlement?->qr)->filter();
                        $invoices = $qrs->flatMap->redemptionInvoices;
                        if ($invoices->isNotEmpty()) {
                            return $invoices->map(fn ($i) => '<a href="' . e(route('redemption.pdf', $i)) . '" target="_blank" class="text-primary-600 underline">'
                                . e($i->invoice_no) . '</a><div class="text-xs text-gray-500">' . e($i->branch?->name ?? '') . ' · '
                                . e(optional($i->created_at)->format('d M Y')) . '</div>')->implode('');
                        }

                        return $qrs->contains('status', 'pending') ? '<span class="text-xs text-gray-500">QR not redeemed yet</span>' : null;
                    })
                    ->html()
                    ->placeholder('—'),
            ])
            ->filters([
                // User 2026-11-19: the old system had no "settlement", so the page opens on ALL
                // contracts in sequence; the admin decides which to settle. (Was: closed / expired
                // only, board 2026-10-31.)
                Tables\Filters\SelectFilter::make('stage')
                    ->label('Show')
                    ->options([
                        'all' => 'All contracts',
                        'unsettled' => 'Expired — not settled here',
                        'closed' => 'Closed / expired contracts',
                        'due' => 'Settlement due (12th month / end)',
                        'matured' => 'Matured (needs decision)',
                        'active' => 'Running contracts',
                    ])
                    ->default('all')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'all') {
                        // every scheme, Gold Saving included: ended, and no settlement made in this system
                        'unsettled' => $query->where('status', '!=', 'cancelled')
                            ->where(fn ($w) => $w->where('status', 'matured')->orWhereDate('end_date', '<', now()->toDateString()))
                            ->whereDoesntHave('settlements')->whereDoesntHave('rdSettlement'),
                        'closed' => $query->where(fn ($w) => $w->whereIn('status', ['closed', 'matured'])
                            ->orWhereDate('end_date', '<', now()->toDateString())),
                        'due' => static::settlementDue($query),
                        'matured' => $query->where('status', 'matured'),
                        'active' => $query->where('status', 'active')->whereDate('end_date', '>=', now()->toDateString()),
                        default => $query,
                    }),
                Tables\Filters\Filter::make('expired')
                    ->label('Expired, awaiting settlement')
                    ->toggle()
                    ->query(fn (Builder $query) => $query
                        ->where(fn ($w) => $w->where('status', 'matured')
                            ->orWhere(fn ($x) => $x->where('status', '!=', 'closed')->whereDate('end_date', '<', now()->toDateString())))
                        ->whereDoesntHave('settlements')),
                // Board 2026-11-15: the scheme by NAME (the code alone says nothing), and the branch
                // by its full name — searchable on name / in-charge / city / phone.
                Tables\Filters\SelectFilter::make('plan_id')
                    ->label('Scheme')
                    ->options(fn () => \App\Models\Plan::orderBy('code')->get()
                        ->mapWithKeys(fn ($p) => [$p->id => trim($p->code . ' — ' . (Translatable::pick($p->name) ?? ''))])->all())
                    ->searchable(),
                \App\Filament\Support\CommonFilters::branch('branch_id', false),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')
                    ->label('Contract PDF')
                    ->icon('heroicon-o-document-text')
                    ->url(fn (MemberContract $record) => route('contract.pdf', $record->bond_id), shouldOpenInNewTab: true)
                    ->visible(fn (MemberContract $record) => $record->bond_id !== null),
                // Board phase 2 (2026-08-28): once a contract has EXPIRED, Head Office types the
                // settlement value; it goes straight to the distributor's cash wallet.
                Tables\Actions\Action::make('generate_settlement')
                    ->label('Generate settlement')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->modalHeading(fn (MemberContract $record) => 'Generate settlement — ' . $record->contract_no)
                    ->modalDescription(fn (MemberContract $record) => ($record->member?->name ?? 'Distributor')
                        . ' · ' . ($record->plan?->code ?? '') . ' · contract value ₹' . \App\Support\Money::group((float) $record->amount)
                        . ' · ended ' . ($record->end_date?->format('d M Y') ?? '—')
                        . '. The value entered below is credited to the distributor\'s cash wallet immediately.')
                    ->modalWidth('md')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Settlement value (₹)')
                            ->numeric()->minValue(0.01)->required()
                            ->default(fn (MemberContract $record) => (float) $record->amount ?: null)
                            ->prefix('₹'),
                        Forms\Components\TextInput::make('note')->label('Note (optional)')->maxLength(255),
                    ])
                    ->visible(fn (MemberContract $record) => ! auth()->user()?->isDistributor()
                        && ! \App\Services\Rd\GoldSavingSettlementService::isGoldSaving($record)   // Gold Saving settles by QR, below
                        && ! \App\Services\ContractSettlementService::settlesByQr($record)          // Retailer / Wholesaler / Sub Dealer / G12: gold QR, below
                        && \App\Services\ContractSettlementService::canGenerate($record))
                    ->action(function (MemberContract $record, array $data) {
                        try {
                            $row = app(\App\Services\ContractSettlementService::class)
                                ->generate($record, (float) $data['amount'], $data['note'] ?? null, auth()->id());
                        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                            \Filament\Notifications\Notification::make()->danger()
                                ->title('Could not generate settlement')->body($e->getMessage())->send();

                            return;
                        }
                        \Filament\Notifications\Notification::make()->success()
                            ->title('Settlement credited')
                            ->body('₹' . \App\Support\Money::group((float) $row->amount) . ' added to '
                                . ($record->member?->name ?? 'the distributor') . "'s wallet.")
                            ->send();
                    }),
                // Lord's board 2026-11-15 — Retailer / Wholesaler / Sub Dealer / G12 Forward settle
                // with a REDEEMABLE GOLD QR worth bill × allocation % × settlement %.
                Tables\Actions\Action::make('settle_gold_qr')
                    ->label('Settle by Gold QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('warning')
                    ->modalHeading(fn (MemberContract $record) => 'Settle by Gold QR — ' . $record->contract_no)
                    ->modalWidth('lg')
                    ->form(fn (MemberContract $record) => [
                        Forms\Components\Placeholder::make('summary')->label('')
                            ->content(fn () => self::qrSettlementSummary($record)),
                        // User 2026-11-15: the admin decides — interim or final — and when.
                        Forms\Components\Radio::make('stage')
                            ->label('Which settlement are you issuing?')
                            ->options(fn () => \App\Services\ContractSettlementService::stageOptions($record))
                            ->descriptions([
                                'interim' => 'The contract keeps running. The final settlement can be issued later — even the next day.',
                                'final' => 'Closes the contract and its bond. Nothing more can be settled on it afterwards.',
                            ])
                            ->default(fn () => \App\Services\ContractSettlementService::suggestedStage($record))
                            ->required(),
                        Forms\Components\TextInput::make('amount')
                            ->label('Approved settlement amount (₹)')
                            ->numeric()->minValue(0.01)->required()->prefix('₹')
                            ->default(fn () => \App\Services\ContractSettlementService::rule($record)['worth'] ?? null)
                            ->helperText('Pre-filled with the scheme rule. The gold QR is issued for the amount approved here, at the gold price of the day.'),
                        self::issueQrToggle($record),
                        Forms\Components\TextInput::make('note')->label('Note (optional)')->maxLength(255),
                    ])
                    ->modalSubmitActionLabel('Approve & issue Gold QR')
                    ->visible(fn (MemberContract $record) => ! auth()->user()?->isDistributor()
                        && ! \App\Services\Rd\GoldSavingSettlementService::isGoldSaving($record)
                        && \App\Services\ContractSettlementService::settlesByQr($record)
                        && \App\Services\ContractSettlementService::canGenerate($record))
                    ->action(function (MemberContract $record, array $data) {
                        try {
                            $row = app(\App\Services\ContractSettlementService::class)
                                ->generateQr($record, (float) $data['amount'], $data['note'] ?? null, auth()->id(), stage: $data['stage'] ?? null,
                                    issueQr: (bool) ($data['issue_qr'] ?? true));
                        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                            \Filament\Notifications\Notification::make()->danger()
                                ->title('Could not settle')->body($e->getMessage())->send();

                            return;
                        }
                        \Filament\Notifications\Notification::make()->success()
                            ->title($row->stage === 'final' ? 'Final settlement issued — contract closed' : 'Interim settlement issued')
                            ->body($row->mode === 'manual'
                                ? 'Recorded as settled manually for ₹' . \App\Support\Money::group((float) $row->amount) . ' — no QR issued.'
                                : 'Redeemable gold QR worth ₹' . \App\Support\Money::group((float) $row->amount)
                                    . ' issued to ' . ($record->member?->name ?? 'the distributor') . '.')
                            ->send();
                    }),
                // Lord's board 2026-11-13 — Gold Saving: after the scheme date, the saver hands the
                // coins back and Head Office settles with a gold (or silver) QR.
                Tables\Actions\Action::make('settle_gold_saving')
                    ->label('Settle Gold Saving')
                    ->icon('heroicon-o-scale')
                    ->color('success')
                    ->modalHeading(fn (MemberContract $record) => 'Settle Gold Saving — ' . $record->contract_no)
                    ->modalWidth('lg')
                    ->form(fn (MemberContract $record) => [
                        Forms\Components\Placeholder::make('summary')->label('')
                            ->content(fn (Forms\Get $get) => self::goldSavingSummary($record, $get('coins_returned'))),
                        Forms\Components\TextInput::make('coins_returned')
                            ->label('100 mg coins handed back at the counter')
                            ->numeric()->integer()->minValue(0)->live(debounce: 500)
                            ->default(fn () => app(\App\Services\Rd\GoldSavingSettlementService::class)->quote($record)['coins_redeemed'])
                            ->maxValue(fn () => app(\App\Services\Rd\GoldSavingSettlementService::class)->quote($record)['coins_redeemed'])
                            ->visible(fn () => app(\App\Services\Rd\GoldSavingSettlementService::class)->quote($record)['coins_redeemed'] > 0)
                            ->helperText('Every coin not handed back is deducted at today\'s 100 mg price. QRs never redeemed are cancelled automatically.'),
                        \App\Filament\Support\CommonFilters::branchSelect(
                            // User 2026-11-19: the system names the branch — the contract's own. It takes
                            // the coins into its stock and is the only one (Head Office excepted) that
                            // may redeem the settlement QR. Open to choose only when the contract has none.
                            Forms\Components\Select::make('branch_id')
                                ->label('Branch settling (takes the coins into its stock, redeems the QR)')
                                ->default(fn () => \App\Services\ContractSettlementService::homeBranchId($record)
                                    ?: \App\Models\Branch::where('level', 'hq')->value('id'))
                                ->disabled(fn () => \App\Services\ContractSettlementService::homeBranchId($record) !== null)
                                ->dehydrated()
                                ->helperText('The branch where this contract was billed. The settlement QR can be redeemed only there, or at Head Office.')
                                ->required(),
                            fn ($q) => $q),
                        // Board 2026-11-15: the QR is issued for the amount the admin APPROVES.
                        Forms\Components\TextInput::make('approved_worth')
                            ->label('Approved settlement amount (₹)')
                            ->numeric()->minValue(0.01)->prefix('₹')
                            ->placeholder(fn (Forms\Get $get) => (string) (self::goldSavingWorth($record, $get('coins_returned')) ?? ''))
                            ->helperText('Leave empty to approve the amount worked out above. The gold / silver QR is issued for the approved amount.'),
                        self::issueQrToggle($record),
                        Forms\Components\TextInput::make('note')->label('Note (optional)')->maxLength(255),
                    ])
                    ->modalSubmitActionLabel('Approve & issue QR')
                    ->visible(fn (MemberContract $record) => ! auth()->user()?->isDistributor()
                        && \App\Services\Rd\GoldSavingSettlementService::canSettle($record))
                    ->action(function (MemberContract $record, array $data) {
                        try {
                            $row = app(\App\Services\Rd\GoldSavingSettlementService::class)->settle(
                                $record,
                                // never trusted from the form when the contract has its own branch
                                \App\Services\ContractSettlementService::homeBranchId($record) ?: (int) $data['branch_id'],
                                isset($data['coins_returned']) && $data['coins_returned'] !== '' ? (int) $data['coins_returned'] : null,
                                $data['note'] ?? null, auth()->id(),
                                approvedWorth: filled($data['approved_worth'] ?? null) ? (float) $data['approved_worth'] : null,
                                issueQr: (bool) ($data['issue_qr'] ?? true));
                        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                            \Filament\Notifications\Notification::make()->danger()
                                ->title('Could not settle')->body($e->getMessage())->send();

                            return;
                        }
                        \Filament\Notifications\Notification::make()->success()
                            ->title('Gold Saving settled')
                            ->body($row->mode === 'manual'
                                ? 'Recorded as settled manually for ₹' . \App\Support\Money::group((float) $row->worth) . ' — no QR issued.'
                                : ucfirst($row->metal) . ' QR worth ₹' . \App\Support\Money::group((float) $row->worth)
                                    . ' issued to ' . ($record->member?->name ?? 'the distributor') . '.')
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /**
     * User 2026-11-19 — the admin decides whether the user gets a QR. On: a redeemable QR, good
     * only at the contract's own branch (or Head Office). Off: Head Office settled by hand and
     * paid outside the system — the settlement is recorded, no QR, nothing credited.
     */
    protected static function issueQrToggle(MemberContract $record): Forms\Components\Toggle
    {
        $branch = \App\Models\Branch::whereKey(\App\Services\ContractSettlementService::homeBranchId($record))->value('name');

        return Forms\Components\Toggle::make('issue_qr')
            ->label('Issue a redeemable QR to the user')
            ->default(true)
            ->helperText('On: the QR can be redeemed only at ' . ($branch ?: 'the contract\'s branch') . ' or at Head Office. '
                . 'Off: settled manually by Head Office (paid outside the system) — the settlement is recorded and no QR is issued.');
    }

    /**
     * Gold-QR contracts with a settlement waiting (user 2026-11-15): the interim one once the
     * scheme's cycle (12th month) has passed and nothing was settled, or the final one once the
     * contract has ended and fewer settlements exist than its settlement dates.
     */
    protected static function settlementDue(Builder $query): Builder
    {
        $today = now();
        $plans = \App\Models\Plan::whereNotNull('settlement_qr_pct')->whereNotNull('settlement_alloc_pct')->where('type', '!=', 'rd')->get();

        return $query->where('status', '!=', 'cancelled')->where(function (Builder $w) use ($plans, $today) {
            $w->whereRaw('1 = 0');
            foreach ($plans as $plan) {
                $cycle = (int) $plan->settlement_cycle_months;
                $w->orWhere(function (Builder $p) use ($plan, $cycle, $today) {
                    $p->where('plan_id', $plan->id)->where(function (Builder $d) use ($cycle, $today) {
                        // final: ended, and still short of a settlement (2 dates when the cycle is shorter than the contract)
                        $d->where(fn (Builder $f) => $f->whereDate('end_date', '<=', $today->toDateString())
                            ->whereDoesntHave('settlements', fn (Builder $s) => $s->where('stage', 'final')));
                        if ($cycle > 0) {
                            // interim: the cycle has passed, nothing settled yet
                            $d->orWhere(fn (Builder $i) => $i->whereDate('start_date', '<=', $today->copy()->subMonthsNoOverflow($cycle)->toDateString())
                                ->doesntHave('settlements'));
                        }
                    });
                });
            }
        });
    }

    /** The settlement worked out for the modal: dues, coins, deduction, and what is given. */
    protected static function goldSavingSummary(MemberContract $record, $coinsReturned): \Illuminate\Support\HtmlString
    {
        try {
            $q = app(\App\Services\Rd\GoldSavingSettlementService::class)
                ->quote($record, $coinsReturned === null || $coinsReturned === '' ? null : (int) $coinsReturned);
        } catch (\Throwable $e) {
            return new \Illuminate\Support\HtmlString('<span class="text-sm text-danger-600">' . e($e->getMessage()) . '</span>');
        }
        $m = fn (float $v) => '₹' . \App\Support\Money::group($v);
        $rows = [
            ['Dues paid', $q['dues_paid'] . ' of ' . $q['dues_total']],
            ['Paid in total', $m($q['paid_total'])],
            ['Bonus', $q['bonus'] > 0 ? $q['bonus_months'] . ' × first month ' . $m($q['first_due']) . ' = ' . $m($q['bonus']) : 'none (' . ($q['dues_paid'] < $q['dues_total'] ? 'not all dues paid' : 'scheme has no bonus') . ')'],
            ['100 mg coins given', $q['coins_given'] . ' (' . $q['coins_cancelled'] . ' never redeemed — QR will be cancelled; ' . $q['coins_redeemed'] . ' to hand back)'],
            ['Coins not returned', $q['coins_missing'] . ($q['coins_missing'] ? ' × ' . $m($q['coin_price']) . ' = − ' . $m($q['deduction']) : '')],
            ['Settlement', strtoupper($q['metal']) . ' QR worth ' . $m($q['worth'])],
        ];
        $html = '<div class="grid grid-cols-2 gap-2 text-sm">';
        foreach ($rows as [$k, $v]) {
            $html .= '<div class="text-gray-500">' . e($k) . '</div><div class="font-semibold">' . e($v) . '</div>';
        }
        $html .= '</div>';

        // Board 2026-11-15: every due paid, with the sum.
        $dues = app(\App\Services\Rd\GoldSavingSettlementService::class)->dues($record);
        $html .= '<div class="mt-4 text-sm font-semibold">Dues paid</div>'
            . '<div class="icl-scroll-x" style="max-height:15rem;overflow-y:auto"><table class="w-full text-sm" style="margin-top:.25rem">'
            . '<thead><tr class="text-left text-gray-500"><th class="py-1 pr-3">#</th><th class="py-1 pr-3">Paid on</th><th class="py-1 pr-3">Branch</th><th class="py-1 text-right">Amount</th></tr></thead><tbody>';
        foreach ($dues as $i => $d) {
            $html .= '<tr class="border-t border-gray-100 dark:border-white/10"><td class="py-1 pr-3">' . ($i + 1) . '</td><td class="py-1 pr-3">' . e($d['date'] ?? '—')
                . '</td><td class="py-1 pr-3">' . e($d['branch'] ?? '—') . '</td><td class="py-1 text-right">' . e($m((float) $d['amount'])) . '</td></tr>';
        }
        $html .= '</tbody><tfoot><tr class="border-t-2 border-gray-300 font-bold"><td class="py-1 pr-3" colspan="3">Sum of ' . count($dues) . ' due(s) paid</td>'
            . '<td class="py-1 text-right">' . e($m((float) array_sum(array_column($dues, 'amount')))) . '</td></tr></tfoot></table></div>';

        return new \Illuminate\Support\HtmlString($html);
    }

    /** The rule amount for the "approved amount" box placeholder. */
    protected static function goldSavingWorth(MemberContract $record, $coinsReturned): ?float
    {
        try {
            return (float) app(\App\Services\Rd\GoldSavingSettlementService::class)
                ->quote($record, $coinsReturned === null || $coinsReturned === '' ? null : (int) $coinsReturned)['worth'];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Bill → allocation → settlement %, as the board wrote it (2026-11-15). */
    protected static function qrSettlementSummary(MemberContract $record): \Illuminate\Support\HtmlString
    {
        $r = \App\Services\ContractSettlementService::rule($record);
        if (! $r) {
            return new \Illuminate\Support\HtmlString('<span class="text-sm text-danger-600">This scheme has no gold-QR settlement rule.</span>');
        }
        $m = fn (float $v) => '₹' . \App\Support\Money::group($v);
        $p = fn (float $v) => rtrim(rtrim(number_format($v, 3), '0'), '.') . ' %';
        $rows = [
            ['Distributor', ($record->member?->name ?? '—') . ' · ' . ($record->member?->member_code ?? '')],
            ['Scheme', trim(($record->plan?->code ?? '') . ' — ' . (Translatable::pick($record->plan?->name) ?? ''))],
            ['Contract / bill value', $m($r['bill'])],
            ['Allocation', $p($r['alloc_pct']) . ' = ' . $m($r['allocation'])],
            ['Settlement', $p($r['qr_pct']) . ' of the allocation = ' . $m($r['worth'])],
            ['Given as', 'GOLD QR (redeemable) worth ' . $m($r['worth'])],
            ['Usual settlement dates (guide)', collect(\App\Services\ContractSettlementService::milestones($record))->map->format('d M Y')->implode('  ·  ')],
            ['Already settled', $record->settlements->isEmpty() ? 'nothing yet'
                : $record->settlements->map(fn ($s) => ucfirst((string) ($s->stage ?: 'settlement')) . ' ₹' . \App\Support\Money::group((float) $s->amount)
                    . ' on ' . $s->paid_on?->format('d M Y'))->implode(', ')],
        ];
        $html = '<div class="grid grid-cols-2 gap-2 text-sm">';
        foreach ($rows as [$k, $v]) {
            $html .= '<div class="text-gray-500">' . e($k) . '</div><div class="font-semibold">' . e($v) . '</div>';
        }

        return new \Illuminate\Support\HtmlString($html . '</div>');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMemberContracts::route('/'),
        ];
    }
}
