<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (['messages' => 'body', 'notifications' => 'data'] as $table => $column) {
                DB::table($table)->whereNotNull($column)->orderBy('id')->chunkById(200, function ($rows) use ($table, $column): void {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)->update([
                            $column => Crypt::encryptString($row->{$column}),
                        ]);
                    }
                });
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Chat encryption cannot be rolled back automatically. Restore a matching database and file backup with the previous code.');
    }
};
