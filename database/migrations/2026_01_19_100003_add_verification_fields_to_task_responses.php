<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавление полей верификации в таблицу task_responses.
     */
    public function up(): void
    {
        Schema::table('task_responses', function (Blueprint $table) {
            $table->timestampTz('verified_at')->nullable()->after('completed_during_shift');
            $table->unsignedBigInteger('verified_by')->nullable()->after('verified_at');
            $table->text('rejection_reason')->nullable()->after('verified_by');

            $table->foreign('verified_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->index('verified_at');
        });
    }

    /**
     * Откат миграции.
     */
    public function down(): void
    {
        Schema::table('task_responses', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropIndex(['verified_at']);
            $table->dropColumn(['verified_at', 'verified_by', 'rejection_reason']);
        });
    }
};
