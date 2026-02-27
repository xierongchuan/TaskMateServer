<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Мигрирует существующие записи с rejection_reason и status='pending'
     * на новый явный статус 'rejected'.
     */
    public function up(): void
    {
        DB::table('task_responses')
            ->whereNotNull('rejection_reason')
            ->where('status', 'pending')
            ->update(['status' => 'rejected']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('task_responses')
            ->where('status', 'rejected')
            ->update(['status' => 'pending']);
    }
};
