<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Language;
use App\Models\LiveRate;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // --- Roles --- (super_admin sees all; staff roles drive menu visibility;
        // distributor = a dealer who logs into the panel as a branch-scoped "semi-admin")
        foreach (['super_admin', 'admin', 'branch_user', 'manager', 'accounts', 'biller', 'distributor'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        // --- Super admin ---
        $admin = User::firstOrCreate(
            ['email' => 'admin@lordicl.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'status' => 'active',
                'locale' => 'en',
                'currency_code' => 'INR',
            ]
        );
        $admin->assignRole('super_admin');

        // --- Currencies (multi-currency core; INR is base) ---
        $currencies = [
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'rate_to_base' => 1, 'is_base' => true],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'rate_to_base' => 0.012],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'rate_to_base' => 0.011],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'rate_to_base' => 0.044],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'rate_to_base' => 0.0095],
            ['code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'rate_to_base' => 0.053],   // Malaysia
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'rate_to_base' => 0.0155],   // Singapore
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => '﷼', 'rate_to_base' => 0.045],          // Saudi Arabia
        ];
        foreach ($currencies as $c) {
            Currency::updateOrCreate(['code' => $c['code']], $c);
        }

        // --- Languages (multi-locale core) ---
        $languages = [
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_default' => true, 'sort' => 1],
            ['code' => 'ta', 'name' => 'Tamil', 'native_name' => 'தமிழ்', 'sort' => 2],
            ['code' => 'hi', 'name' => 'Hindi', 'native_name' => 'हिन्दी', 'sort' => 3],
            ['code' => 'te', 'name' => 'Telugu', 'native_name' => 'తెలుగు', 'sort' => 4],
            ['code' => 'ml', 'name' => 'Malayalam', 'native_name' => 'മലയാളം', 'sort' => 5],
            ['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'is_rtl' => true, 'sort' => 6],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'sort' => 7],
            ['code' => 'ms', 'name' => 'Malay', 'native_name' => 'Bahasa Melayu', 'sort' => 8],     // Malaysia / Singapore
            ['code' => 'zh', 'name' => 'Chinese', 'native_name' => '中文', 'sort' => 9],            // Singapore
        ];
        foreach ($languages as $l) {
            Language::updateOrCreate(['code' => $l['code']], $l);
        }

        // --- Ranks (legacy tbl_tar_deapth). target_bv on TALUK is the entry-gate BV (₹50k);
        // tier_template = the balanced-leg thresholds checked against the top-5 legs.
        // Display names follow the 2026-07 board/auditor terminology (Director → Admin,
        // Member → Distributor); the internal codes are unchanged. ---
        $ranks = [
            ['code' => 'MEMBER', 'name' => ['en' => 'Distributor', 'ta' => 'விநியோகஸ்தர்'], 'depth' => 0, 'target_bv' => 0, 'tier_template' => null, 'sort' => 1],
            ['code' => 'TALUK_DIRECTOR', 'name' => ['en' => 'Taluk Admin'], 'depth' => 1, 'target_bv' => 50000, 'tier_template' => null, 'reward_amount' => 0, 'sort' => 2],
            ['code' => 'DISTRICT_DIRECTOR', 'name' => ['en' => 'District Admin'], 'depth' => 2, 'target_bv' => 1000000, 'tier_template' => [500000, 500000, 0, 0, 0], 'sort' => 3],
            ['code' => 'ZONAL_DIRECTOR', 'name' => ['en' => 'Zonal Admin'], 'depth' => 3, 'target_bv' => 6000000, 'tier_template' => [2500000, 2500000, 1000000, 0, 0], 'sort' => 4],
            ['code' => 'STATE_DIRECTOR', 'name' => ['en' => 'State Admin'], 'depth' => 4, 'target_bv' => 32500000, 'tier_template' => [12500000, 12500000, 5000000, 2500000, 0], 'sort' => 5],
            ['code' => 'CORPORATE_DIRECTOR', 'name' => ['en' => 'Corporate Admin'], 'depth' => 5, 'target_bv' => 137500000, 'tier_template' => [62500000, 62500000, 25000000, 12500000, 7500000], 'sort' => 6],
        ];
        foreach ($ranks as $r) {
            Rank::updateOrCreate(['code' => $r['code']], $r);
        }

        // --- Head office branch (store-locator demo coords: Coimbatore) ---
        $hqBranch = Branch::firstOrCreate(
            ['name' => 'Head Office'],
            ['city' => 'Coimbatore', 'country' => 'IN', 'gst_no' => '33ABCDE1234F1Z5',
             'latitude' => 11.0168445, 'longitude' => 76.9558321, 'level' => 'hq', 'is_active' => true]
        );

        // --- Demo distributor (semi-admin) — branch-scoped dealer login ---
        // A real distributor branch + their own login, mirroring legacy ci_users
        // (branch created first, then linked). Logs into /admin scoped to this branch.
        $distBranch = Branch::firstOrCreate(
            ['name' => 'Trichy — Aishwaryam Jewellers'],
            ['city' => 'Tiruchirappalli', 'country' => 'IN', 'incharge' => 'Taj',
             'gst_no' => '33TAJAA1234A1Z9', 'bill_margin' => 5,
             'level' => 'district', 'source_branch_id' => $hqBranch->id, 'is_active' => true]
        );
        // The member this distributor login maps to (legacy ci_users.mapid → tbl_member).
        // BV ₹50L + invested ₹25L → order limit max(BV, invested) = ₹50,00,000 (like real Taj).
        $distMember = \App\Models\Member::firstOrCreate(
            ['member_code' => 'LJW24DEMO'],
            [
                'name' => 'Taj — Aishwaryam Jewellers', 'phone' => '9003153545',
                'joined_on' => now(), 'placement' => 'level',
                'rank_id' => Rank::where('depth', 0)->value('id'),
                'status' => 'active', 'branch_id' => $distBranch->id,
                'bv' => 5000000, 'gbv' => 6595112,
            ]
        );
        $distributor = User::firstOrCreate(
            ['email' => 'distributor@lordicl.com'],
            [
                'name' => 'Taj (District Distributor)',
                'password' => Hash::make('password'),
                'status' => 'active',
                'branch_id' => $distBranch->id,
                'designation' => 'District',
                'member_code' => $distMember->member_code,
                'invested' => 2500000,
                'locale' => 'en',
                'currency_code' => 'INR',
            ]
        );
        $distributor->branch_id = $distBranch->id;   // keep in sync if row pre-existed
        $distributor->invested = 2500000;
        $distributor->member_code = $distMember->member_code;
        $distributor->save();
        $distributor->syncRoles('distributor');

        // --- Categories: e-commerce vs trade (separate worlds) ---
        $cats = [
            ['domain' => 'ecommerce', 'material' => 'gold', 'slug' => 'ecom-gold', 'name' => ['en' => 'Gold']],
            ['domain' => 'ecommerce', 'material' => 'silver', 'slug' => 'ecom-silver', 'name' => ['en' => 'Silver']],
            ['domain' => 'ecommerce', 'material' => 'accessory', 'slug' => 'ecom-accessories', 'name' => ['en' => 'Accessories']],
            ['domain' => 'trade', 'material' => 'gold', 'slug' => 'trade-gold', 'name' => ['en' => 'Gold']],
            ['domain' => 'trade', 'material' => 'silver', 'slug' => 'trade-silver', 'name' => ['en' => 'Silver']],
            ['domain' => 'trade', 'material' => 'vessel', 'slug' => 'trade-vessel', 'name' => ['en' => 'Vessels']],
        ];
        foreach ($cats as $c) {
            Category::firstOrCreate(['slug' => $c['slug']], $c);
        }

        // --- Initial live rate (India, per gram) ---
        if (! LiveRate::where('country', 'IN')->exists()) {
            LiveRate::create([
                'country' => 'IN', 'gold' => 7000, 'silver' => 90, 'diamond' => 0,
                'source' => 'manual', 'is_override' => true, 'effective_at' => now(),
            ]);
        }

        // --- Charge brackets (legacy tbl_othercharges → cash→gold reversal making/wastage) ---
        // Legacy weights were in MILLIGRAMS; stored here in GRAMS to match per-gram rates and
        // the ChargeResolver. mc/wc percentages preserved verbatim (legacy material 1=gold, 2=silver).
        $brackets = [
            ['material' => 'gold', 'wt_from' => 0.001, 'wt_to' => 0.100, 'making_pct' => 20, 'wastage_pct' => 20],
            ['material' => 'gold', 'wt_from' => 0.101, 'wt_to' => 0.300, 'making_pct' => 15, 'wastage_pct' => 15],
            ['material' => 'gold', 'wt_from' => 0.301, 'wt_to' => 0.500, 'making_pct' => 10, 'wastage_pct' => 10],
            ['material' => 'gold', 'wt_from' => 0.501, 'wt_to' => 1.000, 'making_pct' => 2.5, 'wastage_pct' => 2.5],
            ['material' => 'gold', 'wt_from' => 1.001, 'wt_to' => 8.000, 'making_pct' => 2.5, 'wastage_pct' => 2.5],
            ['material' => 'gold', 'wt_from' => 8.001, 'wt_to' => 800.000, 'making_pct' => 2.5, 'wastage_pct' => 2.5],
            ['material' => 'silver', 'wt_from' => 0.001, 'wt_to' => 8000.000, 'making_pct' => 20, 'wastage_pct' => 20],
        ];
        foreach ($brackets as $b) {
            \App\Models\ChargeBracket::updateOrCreate(
                ['material' => $b['material'], 'wt_from' => $b['wt_from'], 'wt_to' => $b['wt_to']],
                $b
            );
        }
    }
}
