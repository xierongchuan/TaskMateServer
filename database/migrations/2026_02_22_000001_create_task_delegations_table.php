<?php

declare(strict_types=1);

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
        Schema::create('task_delegations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('from_user_id');
            $table->unsignedBigInteger('to_user_id');
            $table->string('status', 20)->default('pending')->comment('pending, accepted, rejected, cancelled');
            $table->text('reason')->nullable()->comment('Причина делегирования');
            $table->text('rejection_reason')->nullable()->comment('Причина отказа');
            $table->timestampTz('responded_at')->nullable()->comment('Когда ответил целевой сотрудник');
            $table->unsignedBigInteger('cancelled_by')->nullable()->comment('Кто отменил запрос');
            $table->timestampsTz();

            $table->foreign('task_id')
                ->references('id')
                ->on('tasks')
                ->onDelete('cascade');

            $table->foreign('from_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('to_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('cancelled_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->index(['task_id', 'status']);
            $table->index(['to_user_id', 'status']);
            $table->index(['from_user_id', 'status']);
        });

        // Partial unique index: один pending запрос на задачу от одного пользователя (PostgreSQL)
        DB::statement("
            CREATE UNIQUE INDEX task_delegations_pending_unique
            ON task_delegations (task_id, from_user_id)
            WHERE status = 'pending'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_delegations');
    }
};
