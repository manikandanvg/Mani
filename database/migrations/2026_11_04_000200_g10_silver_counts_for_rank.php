<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Board 2026-11-04 (legacy allocation sheet) + legacy R/A BV cron (Ascript::cronrabvreset):
 * every 100 %-allocation plan counts in full toward TBP/rank BV except G10 Gold (206) and
 * PLUS1 (210), which legacy excludes. G10 Silver (212) was imported as not counting — legacy
 * counts it ×1 and the board sheet says 100.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plans')->get(['id', 'name']) as $p) {
            $name = json_decode((string) $p->name, true);
            $key = strtolower(trim((string) (is_array($name) ? ($name['en'] ?? reset($name)) : $name)));
            if ($key === 'g10 silver purchase plan') {
                DB::table('plans')->where('id', $p->id)->update(['rank_factor' => 100, 'counts_for_rank' => true]);
            }
        }
    }

    public function down(): void
    {
        // intentionally left: the previous value (0 / not counting) was only the import default
    }
};
