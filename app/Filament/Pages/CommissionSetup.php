<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\CommissionApprovalService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * System — Commission Setup (board spec 2026-08-09). Per-stream TDS % and service
 * charge % applied at the commission-approval gate. Stored in the settings table
 * (group 'commission', key = stream, value = {"tds": %, "service": %}); unset
 * streams fall back to the 5% / 5% defaults. CBC is a coupon and stays exempt —
 * shown here read-only so the exemption is visible policy, not a gap.
 */
class CommissionSetup extends Page implements HasForms
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use \App\Filament\Concerns\HqOnly;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Commission Setup';

    protected static ?string $title = 'Commission Setup';

    protected static string $view = 'filament.pages.commission-setup';

    public ?array $data = [];

    /** Streams that take deductions (everything except the exempt CBC coupon). */
    protected static function configurableTypes(): array
    {
        return array_diff_key(CommissionApprovalService::TYPES, ['CBC' => true]);
    }

    public function mount(): void
    {
        $fill = [];
        foreach (array_keys(static::configurableTypes()) as $type) {
            [$tds, $svc] = CommissionApprovalService::chargesFor($type);
            $fill[$type] = ['tds' => $tds, 'service' => $svc];
        }
        $fill['digi_market_fee'] = \App\Services\Wallet\DigiMarketService::platformFeePct();
        $fill['customize'] = [
            'gold_margin_per_g' => \App\Support\CustomizeOrderPricing::marginPerGram('gold'),
            'silver_margin_per_g' => \App\Support\CustomizeOrderPricing::marginPerGram('silver'),
            'gst_pct' => \App\Support\CustomizeOrderPricing::gstPct(),
            'coin_product_id' => \App\Support\CustomizeOrderPricing::coinProduct()?->id,
        ];
        $this->form->fill($fill);
    }

    protected function getFormActions(): array
    {
        return [];
    }

    public function form(Form $form): Form
    {
        $sections = [];

        foreach (static::configurableTypes() as $type => $label) {
            $sections[] = Forms\Components\Section::make($label)
                ->compact()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make("{$type}.tds")
                        ->label('TDS %')
                        ->numeric()->minValue(0)->maxValue(30)->required()->suffix('%'),
                    Forms\Components\TextInput::make("{$type}.service")
                        ->label('Service charge %')
                        ->numeric()->minValue(0)->maxValue(30)->required()->suffix('%'),
                ]);
        }

        $sections[] = Forms\Components\Section::make(CommissionApprovalService::TYPES['CBC'])
            ->compact()
            ->schema([
                Forms\Components\Placeholder::make('cbc_note')
                    ->hiddenLabel()
                    ->content(__('Exempt — CBC pays out as 40% E-pin + 60% coupon with no TDS or service charge.')),
            ]);

        // Board 2026-08-11: fee withheld when a distributor transfers Digi
        // Gold/Silver back to the cash wallet (both metals, % of live value).
        $sections[] = Forms\Components\Section::make(__('Digi Market'))
            ->compact()
            ->description(__('Platform fee charged when Digi Gold/Silver is transferred back into the cash wallet.'))
            ->schema([
                Forms\Components\TextInput::make('digi_market_fee')
                    ->label('Platform fee %')
                    ->numeric()->minValue(0)->maxValue(30)->required()->suffix('%'),
            ]);

        // Board 2026-08-26: distributor Customize Order Form pricing — price per gram =
        // live rate + this marginal cost + the distributor's own cost, GST on top.
        $sections[] = Forms\Components\Section::make(__('Customize Order pricing'))
            ->compact()
            ->columns(2)
            ->description(__('Marginal cost added per gram on the distributor Customize Order Form (on top of the live rate); making/wastage come from the Charge Brackets for the weight, then GST. The coin item is what RD customers hand back as part-payment.'))
            ->schema([
                Forms\Components\TextInput::make('customize.gold_margin_per_g')
                    ->label('Gold marginal cost (₹/g)')
                    ->numeric()->minValue(0)->required()->prefix('₹'),
                Forms\Components\TextInput::make('customize.silver_margin_per_g')
                    ->label('Silver marginal cost (₹/g)')
                    ->numeric()->minValue(0)->required()->prefix('₹'),
                Forms\Components\TextInput::make('customize.gst_pct')
                    ->label('GST %')
                    ->numeric()->minValue(0)->maxValue(30)->required()->suffix('%'),
                Forms\Components\Select::make('customize.coin_product_id')
                    ->label('RD coin catalog item')
                    ->options(fn () => \App\Support\CustomizeOrderPricing::coinProductOptions())
                    ->searchable()->native(false),
            ]);

        return $form->schema([
            Forms\Components\Grid::make(2)->schema($sections),
        ])->statePath('data');
    }

    public function save(): void
    {
        $d = $this->form->getState();

        foreach (array_keys(static::configurableTypes()) as $type) {
            Setting::updateOrCreate(
                ['group' => 'commission', 'key' => $type],
                ['value' => json_encode([
                    'tds' => (float) $d[$type]['tds'],
                    'service' => (float) $d[$type]['service'],
                ]), 'type' => 'json'],
            );
        }

        Setting::updateOrCreate(
            ['group' => 'digi_market', 'key' => 'platform_fee_pct'],
            ['value' => (string) (float) $d['digi_market_fee'], 'type' => 'float'],
        );

        \App\Support\CustomizeOrderPricing::save([
            'gold_margin_per_g' => (float) ($d['customize']['gold_margin_per_g'] ?? 0),
            'silver_margin_per_g' => (float) ($d['customize']['silver_margin_per_g'] ?? 0),
            'gst_pct' => (float) ($d['customize']['gst_pct'] ?? \App\Support\CustomizeOrderPricing::DEFAULT_GST_PCT),
            'coin_product_id' => $d['customize']['coin_product_id'] ?? null,
        ]);

        Notification::make()->success()
            ->title(__('Commission charges saved'))
            ->body(__('New rates apply to every approval from now on; already-approved rows are untouched.'))
            ->send();
    }
}
