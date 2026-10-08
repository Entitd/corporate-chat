<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('crm_id', 36)->nullable()->unique();
            $table->string('crm_username')->nullable()->unique();
            $table->string('crm_password_hash')->nullable();
            $table->boolean('crm_active')->default(true);
            $table->timestamp('crm_synced_at')->nullable();
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Перед откатом миграции необходимо заполнить email у импортированных пользователей.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->dropUnique(['crm_id']);
            $table->dropUnique(['crm_username']);
            $table->dropColumn(['crm_id', 'crm_username', 'crm_password_hash', 'crm_active', 'crm_synced_at']);
        });
    }
};
