<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redemption (GM) margin = a flat ₹/gram on the PRODUCT (legacy tbl_product.margin),
 * accrued into SEPARATE gold vs silver balances on the dealer/branch (legacy
 * ci_users.goldgmmargin / silvergmmargin). Also add the product HSN code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            // INR per gram redemption margin (legacy tbl_product.margin: 0 / 25 / 100…).
            $table->decimal('gm_margin', 12, 2)->default(0)->after('wastage_charge_pct');
            $table->string('hsn_code', 20)->nullable()->after('gst_pct');
        });

        Schema::table('branches', function (Blueprint $table) {
            // Earned GM-margin balances, split by metal (legacy goldgmmargin / silvergmmargin).
            $table->decimal('gold_gm_margin', 12, 2)->default(0)->after('bill_margin');
            $table->decimal('silver_gm_margin', 12, 2)->default(0)->after('gold_gm_margin');
            $table->dropColumn('gm_margin');   // was an unused single % field
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropColumn(['gm_margin', 'hsn_code']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('gm_margin', 8, 3)->default(0);
            $table->dropColumn(['gold_gm_margin', 'silver_gm_margin']);
        });
    }
};
