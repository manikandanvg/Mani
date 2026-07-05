<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 2026-07 board/auditor terminology: rank display names change from "Director" to
// "Admin" and the base rank from "Member" to "Distributor". Data-only migration —
// rank codes (TALUK_DIRECTOR, ...) and all FKs are untouched; only the translatable
// `name` JSON is rewritten, keyed by the stable code.
return new class extends Migration
{
    /** code => [old en, new en] (+ Tamil for the base rank). */
    private const RENAMES = [
        'MEMBER' => ['en' => 'Distributor', 'ta' => 'விநியோகஸ்தர்'],
        'TALUK_DIRECTOR' => ['en' => 'Taluk Admin'],
        'DISTRICT_DIRECTOR' => ['en' => 'District Admin'],
        'ZONAL_DIRECTOR' => ['en' => 'Zonal Admin'],
        'STATE_DIRECTOR' => ['en' => 'State Admin'],
        'CORPORATE_DIRECTOR' => ['en' => 'Corporate Admin'],
    ];

    private const OLD_NAMES = [
        'MEMBER' => ['en' => 'Member', 'ta' => 'உறுப்பினர்'],
        'TALUK_DIRECTOR' => ['en' => 'Taluk Director'],
        'DISTRICT_DIRECTOR' => ['en' => 'District Director'],
        'ZONAL_DIRECTOR' => ['en' => 'Zonal Director'],
        'STATE_DIRECTOR' => ['en' => 'State Director'],
        'CORPORATE_DIRECTOR' => ['en' => 'Corporate Director'],
    ];

    public function up(): void
    {
        $this->applyNames(self::RENAMES);
    }

    public function down(): void
    {
        $this->applyNames(self::OLD_NAMES);
    }

    private function applyNames(array $namesByCode): void
    {
        foreach ($namesByCode as $code => $names) {
            $rank = DB::table('ranks')->where('code', $code)->first();
            if (! $rank) {
                continue;
            }

            $current = json_decode($rank->name ?? '{}', true) ?: [];
            DB::table('ranks')->where('code', $code)
                ->update(['name' => json_encode(array_merge($current, $names), JSON_UNESCAPED_UNICODE)]);
        }
    }
};
