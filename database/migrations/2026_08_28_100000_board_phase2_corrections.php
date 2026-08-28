<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Board "Web-Application correction phase 2" (2026-08-28):
 *
 *  - contract_settlements: an admin-entered settlement value for an EXPIRED contract,
 *    credited straight to the distributor's cash wallet (app: My Earnings → Contract
 *    Settlement tab).
 *  - memos: HQ notes broadcast to every app user as a push + inbox entry (the old
 *    "Messages" scaffold under Community is replaced by this).
 *  - meetings.audience_ranks: multi-select audience (e.g. Taluk Admin AND State Admin)
 *    replacing the single "minimum rank & above" gate. Existing min_rank_depth values
 *    are converted to the equivalent set (min..top) so nothing changes for old rows.
 *  - support_tickets.attachments: a ticket must carry at least one photo/file.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contract_settlements')) {
            Schema::create('contract_settlements', function (Blueprint $t) {
                $t->id();
                $t->foreignId('member_contract_id')->constrained('member_contracts')->cascadeOnDelete();
                $t->foreignId('member_id')->constrained('members')->cascadeOnDelete();
                $t->foreignId('bond_id')->nullable()->constrained('bonds')->nullOnDelete();
                $t->decimal('amount', 15, 2);
                $t->string('note', 255)->nullable();
                $t->date('paid_on');
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['member_id', 'paid_on']);
            });
        }

        if (! Schema::hasTable('memos')) {
            Schema::create('memos', function (Blueprint $t) {
                $t->id();
                $t->string('title', 200);
                $t->text('body');
                $t->unsignedInteger('sent_count')->default(0);
                $t->timestamp('sent_at')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }

        if (! Schema::hasColumn('meetings', 'audience_ranks')) {
            Schema::table('meetings', fn (Blueprint $t) => $t->json('audience_ranks')->nullable()->after('visibility'));
        }
        if (Schema::hasColumn('meetings', 'min_rank_depth')) {
            // "District Admin & above" (2) → [2,3,4,5]; NULL stays NULL (= everyone).
            $top = (int) (DB::table('ranks')->max('depth') ?? 5);
            foreach (DB::table('meetings')->whereNotNull('min_rank_depth')->get(['id', 'min_rank_depth']) as $m) {
                DB::table('meetings')->where('id', $m->id)->update([
                    'audience_ranks' => json_encode(range((int) $m->min_rank_depth, max($top, (int) $m->min_rank_depth))),
                ]);
            }
            Schema::table('meetings', fn (Blueprint $t) => $t->dropColumn('min_rank_depth'));
        }

        if (! Schema::hasColumn('support_tickets', 'attachments')) {
            Schema::table('support_tickets', fn (Blueprint $t) => $t->json('attachments')->nullable()->after('subject'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_settlements');
        Schema::dropIfExists('memos');
        if (Schema::hasColumn('meetings', 'audience_ranks')) {
            Schema::table('meetings', fn (Blueprint $t) => $t->dropColumn('audience_ranks'));
        }
        if (! Schema::hasColumn('meetings', 'min_rank_depth')) {
            Schema::table('meetings', fn (Blueprint $t) => $t->unsignedTinyInteger('min_rank_depth')->nullable()->after('visibility'));
        }
        if (Schema::hasColumn('support_tickets', 'attachments')) {
            Schema::table('support_tickets', fn (Blueprint $t) => $t->dropColumn('attachments'));
        }
    }
};
