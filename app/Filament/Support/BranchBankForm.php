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
            // Complaint 2026-11-27 ("update not working for some branches"): the number was checked
            // exactly as typed, so one pasted with spaces / dashes (1234 5678 9012) or an IFSC with
            // a space around it was refused. Both are now cleaned as soon as the field is left and
            // the check itself ignores spaces and dashes.
            Forms\Components\TextInput::make('bank_acno')->label('Account number')->maxLength(40)->required($required)
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('bank_acno', static::cleanAccount($state)))
                ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                    if (filled($value) && ! preg_match('/^[0-9A-Za-z]{6,20}$/', (string) static::cleanAccount($value))) {
                        $fail('The account number is 6 to 20 digits (spaces and dashes are ignored).');
                    }
                })
                ->dehydrateStateUsing(fn ($state) => static::cleanAccount($state)),
            Forms\Components\TextInput::make('bank_ifsc')->label('IFSC code')->maxLength(20)->required($required)
                ->placeholder('SBIN0001234')
                ->extraInputAttributes(['style' => 'text-transform:uppercase'])
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('bank_ifsc', static::cleanIfsc($state)))
                ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                    if (filled($value) && ! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', (string) static::cleanIfsc($value))) {
                        $fail('An IFSC code is 11 characters: 4 letters, a zero (not the letter O), then 6 letters or digits (e.g. SBIN0001234).');
                    }
                })
                ->dehydrateStateUsing(fn ($state) => static::cleanIfsc($state)),
        ];
    }

    /** "1234 5678-9012" → "123456789012"; blank → null. */
    public static function cleanAccount($value): ?string
    {
        $v = preg_replace('/[\s\-.]+/u', '', (string) $value);

        return $v === '' ? null : $v;
    }

    /** " sbin 0001234 " → "SBIN0001234"; blank → null. */
    public static function cleanIfsc($value): ?string
    {
        $v = strtoupper(preg_replace('/[\s\-]+/u', '', (string) $value));

        return $v === '' ? null : $v;
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
