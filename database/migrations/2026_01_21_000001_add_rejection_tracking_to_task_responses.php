<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('task_responses', function (Blueprint $table) {
            $table->unsignedSmallInteger('rejection_count')
                ->default(0)
                ->after('rejection_reason')
                ->comment('Количество отклонений доказательств');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_responses', function (Blueprint $table) {
            $table->dropColumn('rejection_count');
        });
    }
};
