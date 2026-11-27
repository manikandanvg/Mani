<?php

namespace App\Filament\Pages;

use App\Filament\Support\BranchBankForm;
use App\Models\Branch;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Master → My Bank Details (branch login). Board 2026-11-25: the branch enters its own bank
 * account — bank name, account holder, account number, IFSC — and sees the same change log
 * Head Office sees on Master → Branches → edit. Only the four bank fields can be saved here;
 * the branch is always the one of the login.
 */
class MyBankDetails extends Page implements HasForms
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Master';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'My Bank Details';

    protected static ?string $title = 'My Bank Details';

    protected static ?string $slug = 'my-bank-details';

    protected static string $view = 'filament.pages.my-bank-details';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) ($u && method_exists($u, 'isDistributor') && $u->isDistributor() && $u->branch_id);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getBranch(): ?Branch
    {
        return Branch::find(auth()->user()?->branch_id);
    }

    public function mount(): void
    {
        $this->form->fill($this->getBranch()?->only(Branch::BANK_FIELDS) ?? []);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Section::make('Bank details')
                ->description(fn () => ($this->getBranch()?->name ?? '') . ' — ' . __('the bank account of your branch. Every change is recorded and visible to Head Office.'))
                ->columns(2)
                ->schema(BranchBankForm::fields(required: true)),
            Section::make('Bank change log')->schema([
                Placeholder::make('bank_change_log')->label('')
                    ->content(fn () => BranchBankForm::log($this->getBranch())),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $branch = $this->getBranch();
        abort_unless($branch, 404);

        $data = array_intersect_key($this->form->getState(), array_flip(Branch::BANK_FIELDS));
        $branch->fill($data);
        if (! $branch->isDirty(Branch::BANK_FIELDS)) {
            Notification::make()->info()->title(__('Nothing changed'))->send();

            return;
        }
        try {
            $branch->save();   // the Branch model writes the change log
        } catch (\Throwable $e) {
            // never a blank error page: say it failed and keep what was typed (complaint 2026-11-27)
            report($e);
            Notification::make()->danger()->title(__('Bank details were NOT saved'))
                ->body(__('Please try again; if it fails again, send this to Head Office: ') . mb_substr($e->getMessage(), 0, 200))
                ->persistent()->send();

            return;
        }

        // read back what the database now holds, so the screen never shows an unsaved value
        $saved = $branch->fresh();
        Notification::make()->success()->title(__('Bank details saved'))
            ->body(__('A/c :no · IFSC :ifsc — the change was recorded in the log.', ['no' => $saved->bank_acno, 'ifsc' => $saved->bank_ifsc]))->send();
        $this->form->fill($saved->only(Branch::BANK_FIELDS));
    }
}
