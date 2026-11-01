<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\MemberResource\Pages;
use App\Filament\Resources\MemberResource\RelationManagers;
use App\Models\Member;
use App\Models\Rank;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

class MemberResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Member::class;

    protected static ?string $navigationGroup = 'Cloud Data';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    // Auditor terminology (2026-07): members/customers are displayed as "Distributors".
    protected static ?string $modelLabel = 'Distributor';

    protected static ?string $pluralModelLabel = 'Distributor List';

    protected static ?string $navigationLabel = 'Distributor List';

    /**
     * Board 2026-10-31 (1): only the HQ super admin may open a distributor for editing or
     * delete one. Every other back-office login sees the list read-only (no row click).
     */
    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('user_id')
                    ->numeric(),
                Forms\Components\TextInput::make('member_code')
                    ->label('Distributor Code')
                    ->required()
                    ->maxLength(30),
                Forms\Components\DatePicker::make('joined_on')
                    ->required(),
                Forms\Components\TextInput::make('upline_id')
                    ->label('Senior Distributor ID')
                    ->numeric(),
                Forms\Components\TextInput::make('referrer_id')
                    ->label('Referred Distributor ID')
                    ->numeric(),
                Forms\Components\TextInput::make('placement')
                    ->required(),
                Forms\Components\TextInput::make('left_member_id')
                    ->numeric(),
                Forms\Components\TextInput::make('right_member_id')
                    ->numeric(),
                Forms\Components\TextInput::make('rank_id')
                    ->label('TBP Stage ID')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('branch_id')
                    ->numeric(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(200),
                Forms\Components\TextInput::make('phone')
                    ->tel()
                    ->required()
                    ->maxLength(20),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->maxLength(150),
                Forms\Components\DatePicker::make('dob'),
                Forms\Components\TextInput::make('father_name')
                    ->maxLength(200),
                Forms\Components\TextInput::make('address')
                    ->maxLength(255),
                Forms\Components\TextInput::make('city')
                    ->maxLength(120),
                Forms\Components\TextInput::make('pincode')
                    ->maxLength(12),
                Forms\Components\TextInput::make('country')
                    ->required()
                    ->maxLength(2)
                    ->default('IN'),
                Forms\Components\TextInput::make('pan')
                    ->maxLength(15),
                Forms\Components\Toggle::make('pan_verified')
                    ->label('PAN verified')
                    ->helperText('Digital verification also sets this from the app/Sales screen.'),
                Forms\Components\TextInput::make('aadhaar')
                    ->maxLength(16),
                // Re-KYC review (item 18): the app uploads the Aadhaar card photo;
                // approving = switching this toggle ON (the member is push-notified
                // "Aadhaar verified ✓" by the Member observer).
                Forms\Components\Toggle::make('aadhaar_verified')
                    ->label('Aadhaar verified (manual approval)')
                    ->helperText('Review the uploaded card below before approving.'),
                Forms\Components\Placeholder::make('aadhaar_doc')
                    ->label('Uploaded Aadhaar card')
                    ->content(fn (?\App\Models\Member $record) => $record?->aadhaar_doc_path
                        ? new \Illuminate\Support\HtmlString(
                            '<a href="' . e(asset('storage/' . $record->aadhaar_doc_path)) . '" target="_blank">'
                            . '<img src="' . e(asset('storage/' . $record->aadhaar_doc_path)) . '" style="max-height:180px;border-radius:8px" /></a>'
                        )
                        : 'Not uploaded yet.'),
                Forms\Components\TextInput::make('bank_name')
                    ->maxLength(150),
                Forms\Components\TextInput::make('bank_acno')
                    ->maxLength(30),
                Forms\Components\TextInput::make('ifsc')
                    ->maxLength(15),
                Forms\Components\TextInput::make('upi')
                    ->maxLength(80),
                Forms\Components\TextInput::make('nominee_name')
                    ->maxLength(200),
                Forms\Components\TextInput::make('nominee_relation')
                    ->maxLength(60),
                Forms\Components\TextInput::make('nominee_phone')
                    ->tel()
                    ->maxLength(20),
                Forms\Components\FileUpload::make('photo_path')
                    ->label('Profile photo')
                    ->image()
                    ->directory('members')
                    ->maxSize(6144)
                    ->imageEditor(),
                Forms\Components\TextInput::make('bv')
                    ->label('Business Volume (BV)')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('gbv')
                    ->label('Gross Business Volume (GBV)')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('unpure_bv')
                    ->label('Unpure Business Volume')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('unpure_gbv')
                    ->label('Unpure Gross Business Volume')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('downline_count')
                    ->label('Team size')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('status')
                    ->required(),
            ]);
    }

    /**
     * ONE global search box (board 2026-11-01): every word typed is matched as a sub-string
     * against name, father, phone, code, email, address, city, pincode, PAN, Aadhaar, bank,
     * nominee AND the senior / referred distributor's name or code — whether or not the
     * column is toggled on (Filament only searches visible columns, which is why a word
     * from the father-name column found nothing while that column was hidden).
     */
    public static function applyGlobalSearch(Builder $query, string $search): Builder
    {
        $like = '%' . trim($search) . '%';
        $cols = ['name', 'father_name', 'phone', 'member_code', 'email', 'address', 'city', 'taluka', 'district',
            'state', 'pincode', 'pan', 'aadhaar', 'bank_name', 'bank_acno', 'ifsc', 'upi', 'nominee_name', 'nominee_phone'];

        return $query->where(function (Builder $q) use ($cols, $like) {
            foreach ($cols as $i => $col) {
                $q->{$i === 0 ? 'where' : 'orWhere'}('members.' . $col, 'like', $like);
            }
            $q->orWhereIn('members.upline_id', fn ($s) => $s->select('id')->from('members', 'u')
                ->where(fn ($w) => $w->where('u.name', 'like', $like)->orWhere('u.member_code', 'like', $like)));
            $q->orWhereIn('members.referrer_id', fn ($s) => $s->select('id')->from('members', 'r')
                ->where(fn ($w) => $w->where('r.name', 'like', $like)->orWhere('r.member_code', 'like', $like)));
        });
    }

    /** Sort by a related distributor's name (upline / referrer) without a join. */
    protected static function sortByRelatedName(string $fk): \Closure
    {
        return fn (Builder $query, string $direction) => $query->orderBy(
            DB::table('members', 'rel')->select('rel.name')->whereColumn('rel.id', 'members.' . $fk)->limit(1),
            $direction,
        );
    }

    public static function table(Table $table): Table
    {
        $cash = fn () => DB::table('member_wallets')->select('cash_balance')->whereColumn('member_wallets.member_id', 'members.id')->limit(1);

        return $table
            // Board spec 2026-08-09: internal ids (user/placement/left/right/stage/branch)
            // are hidden; senior + referrer render as "Name (Distributor Code)".
            ->columns([
                // Board 2026-08-26 (1.1): column order fixed by the board — thumb photo first,
                // identity → contact → codes → KYC → docs → bank → nominee → volumes → status.
                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('Photo')
                    ->getStateUsing(fn (Member $r) => $r->photo_path ? media_url($r->photo_path) : null)
                    ->circular()->size(40)
                    ->defaultImageUrl(asset('images/logo.png')),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(query: fn (Builder $query, string $search) => static::applyGlobalSearch($query, $search))
                    ->sortable(),
                Tables\Columns\TextColumn::make('father_name')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('address')
                    ->sortable()->toggleable()->limit(40),
                Tables\Columns\TextColumn::make('city')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('pincode')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('phone')
                    ->sortable(),
                Tables\Columns\TextColumn::make('member_code')
                    ->label('Distributor Code')
                    ->sortable(),
                Tables\Columns\TextColumn::make('upline.name')
                    ->label('Senior Distributor')
                    ->formatStateUsing(fn ($state, Member $record) => $record->upline
                        ? "{$record->upline->name} ({$record->upline->member_code})"
                        : '—')
                    ->sortable(query: static::sortByRelatedName('upline_id')),
                Tables\Columns\TextColumn::make('referrer.name')
                    ->label('Referred Distributor')
                    ->formatStateUsing(fn ($state, Member $record) => $record->referrer
                        ? "{$record->referrer->name} ({$record->referrer->member_code})"
                        : '—')
                    ->sortable(query: static::sortByRelatedName('referrer_id')),
                Tables\Columns\TextColumn::make('joined_on')
                    ->label('Joined on')
                    ->date()
                    ->sortable(),
                // Cash wallet (go-live 2026-11-01): distributors will bill and ask for payouts
                // from day one — HQ must see the spendable balance on the list itself.
                Tables\Columns\TextColumn::make('wallet.cash_balance')
                    ->label('Cash wallet')
                    ->formatStateUsing(fn ($state) => Money::inr((float) $state))
                    ->placeholder(Money::inr(0))
                    ->alignEnd()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy($cash(), $direction)),
                // Green KYC badge (board 2026-08-12 item 1) — shown wherever members list.
                Tables\Columns\TextColumn::make('kyc_verified')
                    ->label('KYC')
                    ->badge()
                    ->state(fn (Member $r) => $r->kyc_verified
                        ? 'Verified'
                        : (($r->pan_verified || $r->aadhaar_verified) ? 'Partial' : 'Pending'))
                    ->color(fn (string $state) => match ($state) {
                        'Verified' => 'success', 'Partial' => 'warning', default => 'gray',
                    })
                    ->icon(fn (string $state) => $state === 'Verified' ? 'heroicon-m-check-badge' : null)
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderByRaw('(COALESCE(members.pan_verified, 0) + COALESCE(members.aadhaar_verified, 0)) ' . ($direction === 'desc' ? 'desc' : 'asc'))),
                Tables\Columns\TextColumn::make('email')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('dob')
                    ->date()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('country')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('pan')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('aadhaar')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('bank_name')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('bank_acno')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('ifsc')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('upi')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('nominee_name')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('nominee_relation')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('nominee_phone')
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('bv')
                    ->label('Business Volume (BV)')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('gbv')
                    ->label('Gross Business Volume (GBV)')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unpure_bv')
                    ->label('Unpure Business Volume')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unpure_gbv')
                    ->label('Unpure Gross Business Volume')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('downline_count')
                    ->label('Team size')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rank.name')
                    ->label('TBP Stage')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        DB::table('ranks')->select('depth')->whereColumn('ranks.id', 'members.rank_id')->limit(1), $direction))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        DB::table('branches')->select('name')->whereColumn('branches.id', 'members.branch_id')->limit(1), $direction))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->searchPlaceholder('Search name, phone, code, city, PAN, bank, senior...')
            ->persistSearchInSession()
            ->persistSortInSession()
            ->persistFiltersInSession()
            // No "all" page size: 17k distributors with photos in one page is the 500 the
            // board saw while paging; 25 rows a page, up to 100.
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('joined_on', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(['active' => 'Active', 'inactive' => 'Inactive']),
                Tables\Filters\SelectFilter::make('rank_id')
                    ->label('TBP Stage')
                    ->options(fn () => Rank::query()->orderBy('depth')->get()
                        ->mapWithKeys(fn (Rank $r) => [$r->id => is_array($r->name) ? ($r->name['en'] ?? reset($r->name)) : (string) $r->name])->all()),
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->relationship('branch', 'name')
                    ->searchable()->preload(),
                Tables\Filters\SelectFilter::make('kyc')
                    ->label('KYC')
                    ->options(['verified' => 'Verified', 'partial' => 'Partial', 'pending' => 'Pending'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'verified' => $query->where('pan_verified', 1)->where('aadhaar_verified', 1),
                        'partial' => $query->where(fn ($w) => $w->where('pan_verified', 1)->orWhere('aadhaar_verified', 1))
                            ->where(fn ($w) => $w->where('pan_verified', 0)->orWhereNull('pan_verified')
                                ->orWhere('aadhaar_verified', 0)->orWhereNull('aadhaar_verified')),
                        'pending' => $query->where(fn ($w) => $w->where('pan_verified', 0)->orWhereNull('pan_verified'))
                            ->where(fn ($w) => $w->where('aadhaar_verified', 0)->orWhereNull('aadhaar_verified')),
                        default => $query,
                    }),
                Tables\Filters\Filter::make('place')
                    ->label('Place')
                    ->form([
                        Forms\Components\TextInput::make('city')->label('City'),
                        Forms\Components\TextInput::make('pincode')->label('Pincode'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['city'] ?? null, fn (Builder $q, $v) => $q->where('city', 'like', '%' . trim($v) . '%'))
                        ->when($data['pincode'] ?? null, fn (Builder $q, $v) => $q->where('pincode', 'like', trim($v) . '%')))
                    ->indicateUsing(fn (array $data) => array_values(array_filter([
                        ($data['city'] ?? null) ? 'City: ' . $data['city'] : null,
                        ($data['pincode'] ?? null) ? 'Pincode: ' . $data['pincode'] : null,
                    ]))),
                Tables\Filters\Filter::make('has_cash')
                    ->label('Cash wallet balance > 0')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('members.id', fn ($s) => $s->select('member_id')->from('member_wallets')->where('cash_balance', '>', 0))),
                Tables\Filters\Filter::make('has_team')
                    ->label('Has a team')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('downline_count', '>', 0)),
                // Board 2026-08-26 (1.2): Date of Join between two dates.
                Tables\Filters\Filter::make('joined_on')
                    ->label('Date of Join')
                    ->form([
                        Forms\Components\DatePicker::make('joined_from')->label('Joined from')->native(false),
                        Forms\Components\DatePicker::make('joined_until')->label('Joined until')->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['joined_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('joined_on', '>=', $d))
                        ->when($data['joined_until'] ?? null, fn (Builder $q, $d) => $q->whereDate('joined_on', '<=', $d)))
                    ->indicateUsing(function (array $data) {
                        $parts = [];
                        if ($data['joined_from'] ?? null) {
                            $parts[] = 'Joined from ' . \Illuminate\Support\Carbon::parse($data['joined_from'])->format('d M Y');
                        }
                        if ($data['joined_until'] ?? null) {
                            $parts[] = 'Joined until ' . \Illuminate\Support\Carbon::parse($data['joined_until'])->format('d M Y');
                        }

                        return $parts;
                    }),
            ])
            ->filtersFormWidth('lg')
            ->filtersFormColumns(2)
            // Non-super-admin staff: no row click, no edit, no delete (board 2026-10-31 item 1).
            ->recordUrl(fn (Member $record) => auth()->user()?->isSuperAdmin()
                ? static::getUrl('edit', ['record' => $record])
                : null)
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn () => (bool) auth()->user()?->isSuperAdmin()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ])->visible(fn () => (bool) auth()->user()?->isSuperAdmin()),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembers::route('/'),
            'create' => Pages\CreateMember::route('/create'),
            'edit' => Pages\EditMember::route('/{record}/edit'),
        ];
    }
}
