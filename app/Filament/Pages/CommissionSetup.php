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

        Notification::make()->success()
            ->title(__('Commission charges saved'))
            ->body(__('New rates apply to every approval from now on; already-approved rows are untouched.'))
            ->send();
    }
}
