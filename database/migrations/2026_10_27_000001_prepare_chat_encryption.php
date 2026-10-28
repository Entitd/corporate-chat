<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->longText('body')->nullable()->change();
        });
        Schema::table('notifications', function (Blueprint $table): void {
            $table->longText('data')->change();
        });
        Schema::table('attachments', function (Blueprint $table): void {
            $table->boolean('is_encrypted')->default(false);
        });
        Schema::create('chat_encryption_cleanup', function (Blueprint $table): void {
            $table->string('file_path')->primary();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a matching database and file backup with the previous code to roll back chat encryption.');
    }
};
