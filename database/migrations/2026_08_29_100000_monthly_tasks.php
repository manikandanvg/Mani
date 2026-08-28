<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly Tasks Engine (board 2026-08-28, answers 2026-08-29):
 *
 *  task_types              catalogue — what can be measured (auto) or proven (manual)
 *  task_targets            rules: employee tasks per TBP stage (rank), branch tasks per branch level
 *  task_assignments        one row per member/branch × task × month, with the measured progress
 *  task_submissions        proof for manual tasks (text / photo / GPS), verified by HQ
 *  task_scores             month score per member/branch — scales GAP + payroll (CBC exempt), locked on the 1st
 *  branch_stock_days       daily stock snapshot per branch × product against the Opening level (chart)
 *  member_month_snapshots  GBV / BV / directs on the 1st — baseline for growth targets
 *  meetings.device_id      in-person L-BOX meetings: the arena box whose RFID taps count as attendance
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_types')) {
            Schema::create('task_types', function (Blueprint $t) {
                $t->id();
                $t->string('key', 40)->unique();
                $t->string('name', 120);
                $t->string('description', 255)->nullable();
                $t->string('scope', 10);                      // employee | branch
                $t->string('mode', 10)->default('auto');      // auto | manual
                $t->string('unit', 10)->default('count');     // count | amount | days | hours | minutes
                $t->string('direction', 6)->default('up');    // up = more is better, down = fewer is better
                $t->decimal('default_target', 15, 2)->default(0);
                $t->decimal('default_per_day', 8, 2)->nullable();   // e.g. 8 (hours/day) for OPEN_HOURS
                $t->unsignedTinyInteger('default_weight')->default(1);
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort')->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('task_targets')) {
            Schema::create('task_targets', function (Blueprint $t) {
                $t->id();
                $t->foreignId('task_type_id')->constrained('task_types')->cascadeOnDelete();
                $t->foreignId('rank_id')->nullable()->constrained('ranks')->cascadeOnDelete();   // employee tasks
                $t->string('branch_level', 20)->nullable();                                     // branch tasks
                $t->decimal('target', 15, 2)->default(0);
                $t->decimal('per_day', 8, 2)->nullable();
                $t->unsignedTinyInteger('weight')->default(1);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['task_type_id', 'rank_id', 'branch_level']);
            });
        }

        if (! Schema::hasTable('task_assignments')) {
            Schema::create('task_assignments', function (Blueprint $t) {
                $t->id();
                $t->date('month');                                   // first day of the month
                $t->string('subject_type', 10);                      // member | branch
                $t->unsignedBigInteger('subject_id');
                $t->foreignId('task_type_id')->constrained('task_types')->cascadeOnDelete();
                $t->decimal('target', 15, 2)->default(0);
                $t->decimal('per_day', 8, 2)->nullable();
                $t->unsignedTinyInteger('weight')->default(1);
                $t->string('source', 10)->default('rule');           // rule | manual
                $t->string('title', 200)->nullable();                // CUSTOM tasks: what HQ typed
                $t->decimal('achieved', 15, 2)->default(0);
                $t->decimal('pct', 6, 2)->default(0);
                $t->string('status', 10)->default('pending');        // pending | behind | on_track | achieved | missed
                $t->json('detail')->nullable();                      // measurer breakdown (days, minutes…)
                $t->timestamp('measured_at')->nullable();
                $t->timestamp('locked_at')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->string('note', 255)->nullable();
                $t->timestamps();
                $t->unique(['month', 'subject_type', 'subject_id', 'task_type_id'], 'task_assignments_unique');
                $t->index(['subject_type', 'subject_id', 'month']);
            });
        }

        if (! Schema::hasTable('task_submissions')) {
            Schema::create('task_submissions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('task_assignment_id')->constrained('task_assignments')->cascadeOnDelete();
                $t->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
                $t->text('body')->nullable();
                $t->string('photo_path', 500)->nullable();
                $t->decimal('lat', 10, 7)->nullable();
                $t->decimal('lng', 10, 7)->nullable();
                $t->decimal('value', 15, 2)->default(1);              // what one submission counts for
                $t->string('status', 10)->default('pending');        // pending | verified | rejected
                $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('verified_at')->nullable();
                $t->string('review_note', 255)->nullable();
                $t->timestamps();
                $t->index(['task_assignment_id', 'status']);
            });
        }

        if (! Schema::hasTable('task_scores')) {
            Schema::create('task_scores', function (Blueprint $t) {
                $t->id();
                $t->date('month');
                $t->string('subject_type', 10);
                $t->unsignedBigInteger('subject_id');
                $t->decimal('score_pct', 6, 2)->default(0);
                $t->unsignedSmallInteger('tasks_total')->default(0);
                $t->unsignedSmallInteger('tasks_achieved')->default(0);
                $t->string('status', 10)->default('open');           // open | locked
                $t->timestamp('locked_at')->nullable();
                $t->decimal('adjusted_pct', 6, 2)->nullable();       // HQ override with a note
                $t->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
                $t->string('adjust_note', 255)->nullable();
                $t->timestamps();
                $t->unique(['month', 'subject_type', 'subject_id'], 'task_scores_unique');
            });
        }

        if (! Schema::hasTable('branch_stock_days')) {
            Schema::create('branch_stock_days', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $t->date('date');
                $t->foreignId('catalog_product_id')->constrained('catalog_products')->cascadeOnDelete();
                $t->decimal('quantity', 15, 4)->default(0);
                $t->decimal('opening_qty', 15, 4)->nullable();
                $t->boolean('is_short')->default(false);
                $t->timestamps();
                $t->unique(['branch_id', 'date', 'catalog_product_id'], 'branch_stock_days_unique');
                $t->index(['branch_id', 'date']);
            });
        }

        if (! Schema::hasTable('member_month_snapshots')) {
            Schema::create('member_month_snapshots', function (Blueprint $t) {
                $t->id();
                $t->foreignId('member_id')->constrained('members')->cascadeOnDelete();
                $t->date('month');
                $t->decimal('gbv', 15, 2)->default(0);
                $t->decimal('bv', 15, 2)->default(0);
                $t->unsignedInteger('direct_count')->default(0);
                $t->timestamps();
                $t->unique(['member_id', 'month']);
            });
        }

        if (! Schema::hasColumn('meetings', 'device_id')) {
            Schema::table('meetings', fn (Blueprint $t) => $t->foreignId('device_id')->nullable()->after('platform')
                ->constrained('devices')->nullOnDelete());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('meetings', 'device_id')) {
            Schema::table('meetings', fn (Blueprint $t) => $t->dropConstrainedForeignId('device_id'));
        }
        foreach (['member_month_snapshots', 'branch_stock_days', 'task_scores', 'task_submissions', 'task_assignments', 'task_targets', 'task_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
