<?php

namespace App\Console\Commands;

use App\Filament\Resources\SupportThreadResource;
use App\Services\Support\SupportService;
use Illuminate\Console\Command;

/**
 * User 2026-11-20: the desk answered nothing for a week and people raised the same thing again
 * as new chats. Folds each person's OPEN chats into one (their first), messages in written order.
 * Same thing as the "Merge duplicate chats" button on Support Chat.
 *
 *   php artisan support:merge-duplicates            list only
 *   php artisan support:merge-duplicates --apply
 */
class SupportMergeDuplicates extends Command
{
    protected $signature = 'support:merge-duplicates {--apply : merge (default: list only)}';

    protected $description = 'Merge each customer\'s duplicate open support chats into one chat';

    public function handle(SupportService $support): int
    {
        $groups = $support->duplicateGroups();
        $this->table(['Customer', 'Open chats', 'Kept chat', 'Removed chats', 'Messages'], $groups->map(fn ($g) => [
            SupportThreadResource::ownerLabel($g->first()), $g->count(), '#' . $g->first()->id,
            $g->slice(1)->pluck('id')->map(fn ($id) => '#' . $id)->implode(' '),
            $g->sum(fn ($t) => $t->messages()->count()),
        ])->all());
        $this->info($groups->count() . ' customer(s), ' . $groups->sum->count() . ' open chats → ' . $groups->count() . ' chats.');

        if (! $this->option('apply')) {
            $this->line('List only — nothing written. Add --apply.');

            return self::SUCCESS;
        }
        $r = $support->mergeAllDuplicates();
        $this->info("Merged: {$r['removed']} duplicate chat(s) removed.");

        return self::SUCCESS;
    }
}
