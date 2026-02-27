<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('expense_approvals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('expense_request_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_role', 30);
            $table->string('action', 30);
            $table->text('comment')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('expense_request_id')->references('id')->on('expense_requests')->onDelete('cascade');
            $table->foreign('actor_id')->references('id')->on('users');
        });

        // индекс
        DB::statement("CREATE INDEX idx_expense_approvals_request ON expense_approvals(expense_request_id);");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_approvals', function (Blueprint $table) {
            $table->dropForeign(['expense_request_id']);
            $table->dropForeign(['actor_id']);
        });

        Schema::dropIfExists('expense_approvals');
    }
};
