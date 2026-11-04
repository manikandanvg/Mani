<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy parity (user 2026-11-05, LJW01 GBV check): legacy closes a bond once
 * TIMESTAMPDIFF(MONTH, bdate, CURDATE()) >= tbl_planjewel.levelcomduration, and the G10 Gold /
 * G10 Silver purchase plans carry 11 there. The seeded P206 / P211 rows had validity 0, so the
 * recompute's expiry pass treated their bonds as never ending and re-opened 44 bonds legacy had
 * closed. The rest of the code already assumes 11 for these plans (CronTick, contract builder).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plans')->get(['id', 'name', 'validity_months']) as $p) {
            $name = json_decode((string) $p->name, true);
            $key = strtolower(trim((string) (is_array($name) ? ($name['en'] ?? reset($name)) : $name)));
            if (in_array($key, ['g10 gold purchase plan', 'g10 silver purchase plan'], true) && (int) $p->validity_months === 0) {
                DB::table('plans')->where('id', $p->id)->update(['validity_months' => 11]);
            }
        }
    }

    public function down(): void
    {
        // intentionally left: 0 was only the seed default
    }
};
