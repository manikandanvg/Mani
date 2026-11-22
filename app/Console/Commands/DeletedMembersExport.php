<?php

namespace App\Console\Commands;

use App\Models\DeletedMember;
use Illuminate\Console\Command;

/**
 * The deleted-member list, for when Lord's admin asks for it (user 2026-11-22). Deleted members
 * live only in deleted_members / deleted_member_records — there is no admin screen on purpose.
 *
 *   php artisan members:deleted-export                 list → storage/app/deleted-members-<date>.csv
 *   php artisan members:deleted-export --member=LJW…   one member's full data → …-<code>.json
 */
class DeletedMembersExport extends Command
{
    protected $signature = 'members:deleted-export {--member= : Member code — write that member\'s full snapshot as JSON} {--from= : Deleted on or after (Y-m-d)} {--to= : Deleted on or before (Y-m-d)}';

    protected $description = 'Export the deleted members list (CSV), or one deleted member\'s full data (JSON)';

    public function handle(): int
    {
        $dir = storage_path('app');
        $stamp = now()->format('Ymd-His');

        if ($code = $this->option('member')) {
            $rows = DeletedMember::where('member_code', $code)->orderBy('id')->get();
            if ($rows->isEmpty()) {
                $this->error('No deleted member with code ' . $code . '.');

                return self::FAILURE;
            }
            $out = [];
            foreach ($rows as $m) {
                $records = [];
                foreach ($m->records()->orderBy('id')->cursor() as $r) {
                    $records[$r->source_table][] = ['removed_from_live' => $r->purged, 'row' => $r->data];
                }
                $out[] = ['deleted' => $m->only(['member_id', 'member_code', 'name', 'phone', 'upline_code', 'referrer_code', 'mode', 'reason',
                    'deleted_by_name', 'bills_count', 'issued_total', 'recovered_total', 'charged_total']) + ['removed_at' => $m->removed_at?->toDateTimeString()],
                    'profile' => $m->profile, 'statement' => $m->statement, 'records' => $records];
            }
            $file = $dir . DIRECTORY_SEPARATOR . 'deleted-member-' . preg_replace('/[^A-Za-z0-9_-]/', '', $code) . '-' . $stamp . '.json';
            file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            $this->info('Written: ' . $file . ' (contains personal data — hand over, then delete the file).');

            return self::SUCCESS;
        }

        $query = DeletedMember::query()
            ->when($this->option('from'), fn ($q, $d) => $q->whereDate('removed_at', '>=', $d))
            ->when($this->option('to'), fn ($q, $d) => $q->whereDate('removed_at', '<=', $d))
            ->orderBy('removed_at')->orderBy('id');
        $file = $dir . DIRECTORY_SEPARATOR . 'deleted-members-' . $stamp . '.csv';
        $fh = fopen($file, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['Deleted on', 'Member code', 'Name', 'Phone', 'Joined on', 'Senior (upline)', 'Referred by', 'How', 'Bills cancelled',
            'Paid out / redeemed', 'Taken back from wallets', 'Charged to branch cash stock', 'Reason', 'Deleted by', 'Records kept']);
        $n = 0;
        foreach ($query->cursor() as $m) {
            fputcsv($fh, [
                $m->removed_at?->format('d-m-Y H:i'), $m->member_code, $m->name, $m->phone, $m->joined_on?->format('d-m-Y'),
                $m->upline_code, $m->referrer_code, $m->mode === 'force' ? 'Force delete' : 'Deleted with cancelled invoice', $m->bills_count,
                $m->issued_total, $m->recovered_total, $m->charged_total, $m->reason, $m->deleted_by_name, $m->records_count,
            ]);
            $n++;
        }
        fclose($fh);
        $this->info($n . ' deleted member(s) written: ' . $file);

        return self::SUCCESS;
    }
}
