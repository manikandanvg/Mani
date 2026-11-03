<?php

namespace App\Filament\Pages;

use App\Models\Member;
use App\Models\PhoneOtp;
use App\Services\Auth\OtpService;
use App\Support\Mask;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;

/**
 * Login Codes (board 2026-11-03: the WhatsApp gateway is off, no new app build). The app still
 * asks for a code at sign-in; the code now shows HERE, within seconds, to the distributor's
 * dealer (own branch only) and to Head Office, who tell it to the person. A dealer can also
 * issue a code for someone at the counter. Codes live 10 minutes, single use.
 */
class LoginCodes extends Page implements HasTable
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'L-BOX';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Login Codes';

    protected static ?string $title = 'Login Codes';

    protected static ?string $slug = 'login-codes';

    protected static string $view = 'filament.pages.login-codes';

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) ($u && (($u->isDistributor() && $u->branch_id) || $u->isSupport() || $u->hasAnyRole(['super_admin', 'admin'])));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return __('A distributor presses "Get OTP" in the app; the code appears here within seconds — tell it to them. Codes are valid :m minutes, single use.', ['m' => OtpService::ttl()]);
    }

    /** "Name (member code / phone)" — phone masked for dealer logins (board 2026-10-31 item 19). */
    protected static function optionLabel(Member $m): string
    {
        $phone = auth()->user()?->isDistributor() ? Mask::phone($m->phone) : $m->phone;

        return trim($m->name) . ' (' . $m->member_code . ($phone ? ' / ' . $phone : '') . ')';
    }

    /** Dealers see their own branch's distributors only; HQ and support see everyone. */
    protected static function dealerBranchId(): ?int
    {
        $u = auth()->user();

        return $u && $u->isDistributor() ? (int) $u->branch_id : null;
    }

    /** members.phone (any format) matched to the OTP's digits on the last 10 digits. */
    protected static function memberMatch(Query|Builder $q): void
    {
        $q->from('members')->whereRaw("SUBSTR(REPLACE(REPLACE(REPLACE(members.phone, '+', ''), ' ', ''), '-', ''), -10) = SUBSTR(phone_otps.phone, -10)");
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('issue')
                ->label(__('Issue a code'))->icon('heroicon-o-key')
                ->modalWidth('xl')
                ->modalHeading(__('Issue a login code'))
                ->modalDescription(__('Pick the distributor; the code appears in the list below and in a message to you. Type a name, member code or phone to search.'))
                ->form([
                    Forms\Components\Select::make('member_id')->label(__('Distributor'))->required()->searchable()
                        ->searchPrompt(__('Type a name, member code or phone…'))
                        ->noSearchResultsMessage(__('No distributor matches — only your own branch\'s distributors are listed.'))
                        ->searchDebounce(300)
                        // the first names show before any typing, so the box is never empty
                        ->options(fn () => Member::query()
                            ->when(static::dealerBranchId(), fn ($q, $b) => $q->where('branch_id', $b))
                            ->where('status', 'active')->orderBy('name')->limit(30)->get()
                            ->mapWithKeys(fn ($m) => [$m->id => static::optionLabel($m)])->all())
                        ->getSearchResultsUsing(fn (string $search) => Member::query()
                            ->when(static::dealerBranchId(), fn ($q, $b) => $q->where('branch_id', $b))
                            ->where(fn ($q) => $q->where('member_code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                            ->limit(30)->get()->mapWithKeys(fn ($m) => [$m->id => static::optionLabel($m)])->all())
                        ->getOptionLabelUsing(fn ($value) => ($m = Member::find($value)) ? static::optionLabel($m) : null),
                ])
                ->action(function (array $data) {
                    $m = Member::query()->when(static::dealerBranchId(), fn ($q, $b) => $q->where('branch_id', $b))->find($data['member_id']);
                    if (! $m || blank($m->phone)) {
                        Notification::make()->danger()->title(__('No phone on that distributor'))->send();

                        return;
                    }
                    $res = app(OtpService::class)->issue($m->phone, 'login');
                    \App\Services\Activity\ActivityLogger::log('otp', 'otp_issued_by_staff', 'Login code issued for ' . $m->member_code . ' by ' . auth()->user()->email, ['member' => $m->id]);
                    Notification::make()->success()->title(__('Code for :code: :otp', ['code' => $m->member_code, 'otp' => $res['otp']->code]))
                        ->body(__('Valid :m minutes. Tell it to the distributor.', ['m' => OtpService::ttl()]))->persistent()->send();
                }),
        ];
    }

    /** Codes from the last few minutes by default; with a search or a date range, the last 30 days. */
    protected function lookingBack(): bool
    {
        $date = $this->tableFilters['date'] ?? [];

        return trim((string) $this->getTableSearch()) !== '' || filled($date['from'] ?? null) || filled($date['to'] ?? null);
    }

    public function table(Table $table): Table
    {
        $branch = static::dealerBranchId();
        $mask = (bool) auth()->user()?->isDistributor();

        return $table
            ->query(PhoneOtp::query()
                ->where('purpose', 'login')
                ->where('created_at', '>=', $this->lookingBack() ? now()->subDays(30) : now()->subMinutes(max(30, OtpService::ttl() * 3)))
                ->when($branch, fn ($q) => $q->whereExists(fn (Query $sub) => static::memberMatch($sub->select(\Illuminate\Support\Facades\DB::raw(1))->where('members.branch_id', $branch))))
                ->addSelect(['*',
                    'member_name' => Member::query()->select('name')->tap(fn ($q) => static::memberMatch($q))->limit(1),
                    'member_code' => Member::query()->select('member_code')->tap(fn ($q) => static::memberMatch($q))->limit(1),
                ]))
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->paginated([25, 50])
            ->searchPlaceholder(__('Member code, name or phone…'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label(__('Asked'))->since()->sortable()
                    ->description(fn (PhoneOtp $r) => $r->created_at?->format('d M H:i')),
                // user 2026-11-03: search by user id / number — name + member code match the member
                // row behind the OTP phone, the phone itself matches on its digits
                Tables\Columns\TextColumn::make('member_name')->label(__('Distributor'))->placeholder(__('— not a distributor —'))
                    ->description(fn (PhoneOtp $r) => $r->member_code)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereExists(fn (Query $sub) => static::memberMatch(
                        $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                            ->where(fn ($m) => $m->where('members.name', 'like', "%{$search}%")->orWhere('members.member_code', 'like', "%{$search}%"))))),
                Tables\Columns\TextColumn::make('phone')->label(__('Phone'))
                    // digits only — "LJW900" must not turn into a phone match on "900"
                    ->searchable(query: fn (Builder $query, string $search) => preg_match('/^[\d\s+\-]{4,}$/', trim($search))
                        ? $query->where('phone', 'like', '%' . preg_replace('/\D+/', '', $search) . '%')
                        : $query->whereRaw('1 = 0'))
                    ->formatStateUsing(fn ($state) => $mask ? Mask::phone($state) : $state),
                Tables\Columns\TextColumn::make('code')->label(__('Login code'))->copyable()->weight('bold')->size('lg')
                    ->formatStateUsing(fn ($state, PhoneOtp $r) => $r->consumed_at || $r->expires_at->isPast() ? '••••••' : $state),
                Tables\Columns\TextColumn::make('expires_at')->label(__('Valid until'))->dateTime('H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge()
                    ->getStateUsing(fn (PhoneOtp $r) => $r->consumed_at ? 'used' : ($r->expires_at->isPast() ? 'expired' : 'live'))
                    ->formatStateUsing(fn ($state) => __(ucfirst($state)))
                    ->color(fn ($state) => match ($state) { 'live' => 'success', 'used' => 'gray', default => 'danger' }),
            ])
            ->filters([
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('Asked from'))->native(false),
                        Forms\Components\DatePicker::make('to')->label(__('Asked to'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? __('From :d', ['d' => $data['from']]) : null,
                        ($data['to'] ?? null) ? __('To :d', ['d' => $data['to']]) : null,
                    ])),
            ]);
    }
}
