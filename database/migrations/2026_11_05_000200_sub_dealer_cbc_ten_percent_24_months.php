<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Board (user 2026-11-05): the Sub Dealer scheme carries a Promotional Incentive (CBC) of
 * 10 % of the bill every month for 24 months. Legacy tbl_planjewel had cbvalue 0 / cbcount 0
 * for it (planid 213) and the new plan row was copied from there, so no Sub Dealer bond ever
 * got a coupon.
 *
 * This only sets the plan; the bonds already billed get their coupons from
 * `php artisan cbc:schedule-missing --plan=P213 --apply`.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plans')->get(['id', 'name', 'cbc_value', 'cbc_count']) as $p) {
            $name = json_decode((string) $p->name, true);
            $key = strtolower(trim((string) (is_array($name) ? ($name['en'] ?? reset($name)) : $name)));
            // only when still unset — never overwrite a figure HQ typed on the Plans page
            if ($key === 'sub dealer' && (float) $p->cbc_value == 0.0 && (int) $p->cbc_count === 0) {
                DB::table('plans')->where('id', $p->id)->update(['cbc_value' => 10, 'cbc_count' => 24]);
            }
        }
    }

    public function down(): void
    {
        // intentionally left: the coupons scheduled from it are money owed
    }
};
