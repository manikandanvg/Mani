<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// File drive: nested folders + files with sharing/visibility. Storage via Laravel
// filesystem disks (local/S3); this tracks metadata.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('drive_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->foreignId('parent_id')->nullable()->constrained('drive_folders')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->enum('visibility', ['private', 'shared', 'public'])->default('private');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('drive_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained('drive_folders')->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->enum('visibility', ['private', 'shared', 'public'])->default('private');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('drive_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->nullable()->constrained('drive_files')->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('drive_folders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('permission', ['view', 'edit'])->default('view');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_shares');
        Schema::dropIfExists('drive_files');
        Schema::dropIfExists('drive_folders');
    }
};
