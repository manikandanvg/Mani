<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Key/value app settings (company info, defaults, feature flags). Secrets stay in
// .env, NOT here. `group` lets the admin UI tab them.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 60)->default('general');
            $table->string('key', 100);
            $table->longText('value')->nullable();
            $table->string('type', 30)->default('string'); // string|bool|int|json|media
            $table->timestamps();
            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
