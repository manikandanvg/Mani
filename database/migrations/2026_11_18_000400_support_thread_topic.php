<?php

use App\Services\Support\SupportBot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support Chat triage (2026-11-18, 310 open chats — 294 never answered): every chat gets a
 * TOPIC (wallet, withdrawal, incentive, …) worked out from what the user wrote, so the desk can
 * filter one topic and answer all of it together. Only this tag is written — no message, no
 * member record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('support_threads', 'topic')) {
            Schema::table('support_threads', function (Blueprint $t) {
                $t->string('topic', 32)->nullable()->after('subject')->index();
            });
        }

        // The desk's queue: does this chat need a STAFF answer? Starts as "the user wrote last".
        if (! Schema::hasColumn('support_threads', 'needs_staff')) {
            Schema::table('support_threads', function (Blueprint $t) {
                $t->boolean('needs_staff')->default(false)->after('status')->index();
            });
            DB::table('support_threads')
                ->whereRaw("(select m.sender from support_messages m where m.thread_id = support_threads.id order by m.id desc limit 1) = 'user'")
                ->update(['needs_staff' => true]);
        }

        DB::table('support_threads')->whereNull('topic')->orderBy('id')->chunkById(200, function ($threads) {
            $texts = DB::table('support_messages')->whereIn('thread_id', $threads->pluck('id'))->where('sender', 'user')
                ->orderBy('id')->get(['thread_id', 'body'])->groupBy('thread_id');
            foreach ($threads as $t) {
                $text = $t->subject . "\n" . ($texts->get($t->id)?->pluck('body')->implode("\n") ?? '');
                DB::table('support_threads')->where('id', $t->id)->update(['topic' => SupportBot::topicOf($text)]);
            }
        });
    }

    public function down(): void
    {
        foreach (['topic', 'needs_staff'] as $column) {
            if (Schema::hasColumn('support_threads', $column)) {
                Schema::table('support_threads', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
