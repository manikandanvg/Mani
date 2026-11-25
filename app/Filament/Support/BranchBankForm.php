<?php

namespace App\Filament\Support;

use App\Models\Branch;
use Filament\Forms;
use Illuminate\Support\HtmlString;

/**
 * Branch bank details (board 2026-11-25) — the same four fields and the same change log on the
 * Head Office branch form (Master → Branches → edit) and on the dealer's own "My Bank Details".
 */
class BranchBankForm
{
    /** @return array<int, Forms\Components\Component> */
    public static function fields(bool $required = false): array
    {
        return [
            Forms\Components\TextInput::make('bank_name')->label('Bank name')->maxLength(150)->required($required)
                ->placeholder('e.g. State Bank of India'),
            Forms\Components\TextInput::make('bank_holder')->label('Account holder')->maxLength(150)->required($required)
                ->helperText('The name exactly as on the bank account.'),
            Forms\Components\TextInput::make('bank_acno')->label('Account number')->maxLength(40)->required($required)
                ->regex('/^[0-9]{6,20}$/')
                ->validationMessages(['regex' => 'The account number is 6 to 20 digits, without spaces.'])
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? preg_replace('/\s+/', '', $state) : null),
            Forms\Components\TextInput::make('bank_ifsc')->label('IFSC code')->maxLength(11)->required($required)
                ->placeholder('SBIN0001234')
                ->extraInputAttributes(['style' => 'text-transform:uppercase'])
                ->regex('/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/')
                ->validationMessages(['regex' => 'An IFSC code is 11 characters: 4 letters, a zero, then 6 letters or digits (e.g. SBIN0001234).'])
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? strtoupper(trim($state)) : null),
        ];
    }

    /** The change log as a table: when, who, from → to. */
    public static function log(?Branch $branch): HtmlString
    {
        if (! $branch || ! $branch->exists) {
            return new HtmlString('<span class="text-sm text-gray-500">' . e(__('The log starts once the branch is saved.')) . '</span>');
        }
        $rows = $branch->bankChanges()->limit(100)->get();
        if ($rows->isEmpty()) {
            return new HtmlString('<span class="text-sm text-gray-500">' . e(__('No bank details were entered yet.')) . '</span>');
        }
        $changes = $rows->where('is_first', false)->count();
        $html = '<div class="text-sm font-semibold" style="margin-bottom:.35rem">'
            . e(__('Bank details changed :n time(s)', ['n' => $changes]))
            . ($changes >= 2 ? ' <span style="color:#dc2626">— ' . e(__('check before paying this branch')) . '</span>' : '') . '</div>'
            . '<div class="icl-scroll-x" style="max-height:18rem;overflow:auto"><table class="w-full text-sm"><thead><tr class="text-left text-gray-500">'
            . '<th class="py-1 pr-3">#</th><th class="py-1 pr-3">' . e(__('Date')) . '</th><th class="py-1 pr-3">' . e(__('Changed by')) . '</th>'
            . '<th class="py-1 pr-3">' . e(__('Before')) . '</th><th class="py-1">' . e(__('After')) . '</th></tr></thead><tbody>';
        $n = $rows->count();
        foreach ($rows as $r) {
            $html .= '<tr class="border-t border-gray-100 dark:border-white/10" style="vertical-align:top"><td class="py-1 pr-3">' . $n-- . '</td>'
                . '<td class="py-1 pr-3" style="white-space:nowrap">' . e($r->created_at?->format('d M Y, h:i A') ?? '—') . '</td>'
                . '<td class="py-1 pr-3">' . e($r->changed_by_name ?: __('System')) . '</td>'
                . '<td class="py-1 pr-3">' . e($r->is_first ? __('(first entry)') : $r->describe('old')) . '</td>'
                . '<td class="py-1 font-semibold">' . e($r->describe('new')) . '</td></tr>';
        }

        return new HtmlString($html . '</tbody></table></div>');
    }
}
