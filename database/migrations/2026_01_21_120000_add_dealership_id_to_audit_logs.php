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
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('dealership_id')
                ->nullable()
                ->after('actor_id')
                ->constrained('auto_dealerships')
                ->nullOnDelete();

            // Индекс для быстрой фильтрации по автосалону
            $table->index('dealership_id', 'idx_audit_logs_dealership');

            // Составной индекс для фильтрации по автосалону и дате
            $table->index(['dealership_id', 'created_at'], 'idx_audit_logs_dealership_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('idx_audit_logs_dealership_created');
            $table->dropIndex('idx_audit_logs_dealership');
            $table->dropConstrainedForeignId('dealership_id');
        });
    }
};
