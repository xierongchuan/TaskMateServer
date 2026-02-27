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
        Schema::create('task_verification_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('task_response_id');
            $table->string('action', 20)->comment('submitted, approved, rejected, resubmitted');
            $table->unsignedBigInteger('performed_by');
            $table->text('reason')->nullable()->comment('Причина отклонения');
            $table->string('previous_status', 50)->nullable();
            $table->string('new_status', 50);
            $table->unsignedSmallInteger('proof_count')->default(0)->comment('Количество файлов на момент действия');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('task_response_id')
                ->references('id')
                ->on('task_responses')
                ->onDelete('cascade');

            $table->foreign('performed_by')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index(['task_response_id', 'created_at']);
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_verification_history');
    }
};
