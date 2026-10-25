<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Rank;
use Illuminate\Console\Command;

/**
 * The dedicated DISTRIBUTOR demo account that Google Play / App Store reviewers log in
 * with (Play rejected 3.11.13 on 2026-10-25: "no demo/guest account"). It is a normal
 * member row on the head-office branch with nothing financial attached, so every screen
 * opens without exposing a real distributor. The phone number must also be listed in
 * REVIEW_LOGINS (phone:code) so the OTP step accepts a fixed code — see OtpService.
 *
 *   php artisan review:account 9000000001 --name="Play Review"
 */
class ReviewAccount extends Command
{
    protected $signature = 'review:account {phone : 10-digit demo phone} {--name=Play Review Distributor}';

    protected $description = 'Create or refresh the distributor demo account used by store reviewers';

    public function handle(): int
    {
        $phone = preg_replace('/\D+/', '', (string) $this->argument('phone'));
        if (strlen($phone) !== 10) {
            $this->error('Give a 10-digit phone number.');

            return self::FAILURE;
        }

        $hq = Branch::where('level', 'hq')->orderBy('id')->first() ?? Branch::orderBy('id')->first();
        $rank = Rank::orderBy('depth')->value('id');

        $member = Member::firstOrNew(['phone' => $phone]);
        $member->fill([
            'member_code' => $member->member_code ?: 'REVIEW' . substr($phone, -4),
            'name' => $this->option('name'),
            'joined_on' => $member->joined_on ?: now()->toDateString(),
            'placement' => 'level',
            'rank_id' => $member->rank_id ?: $rank,
            'branch_id' => $hq?->id,
            'city' => 'Sivakasi',
            'status' => 'active',
            'email' => 'playreview@lordicl.com',
        ]);
        $member->save();
        MemberWallet::firstOrCreate(['member_id' => $member->id]);

        $this->info("Review account ready: {$member->member_code} / {$phone} (branch: " . ($hq?->name ?? '—') . ')');
        $this->line('Now set REVIEW_LOGINS="' . $phone . ':<6-digit code>" in .env and run php artisan config:clear.');

        return self::SUCCESS;
    }
}
