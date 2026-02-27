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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('table_name', 100);
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 50);
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('actor_id')->references('id')->on('users');
        });

        DB::statement("CREATE INDEX idx_audit_logs_table_record ON audit_logs(table_name, record_id);");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['actor_id']);
        });

        Schema::dropIfExists('audit_logs');
    }
};
