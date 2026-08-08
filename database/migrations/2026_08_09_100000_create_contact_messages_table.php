<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Storefront "Contact us" inquiries — stored so head office can follow up from the
// admin panel instead of the message vanishing after the thank-you flash.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 150);
            $table->string('phone', 20);
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['is_read', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
