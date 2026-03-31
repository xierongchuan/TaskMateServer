<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX idx_tasks_search_trgm ON tasks USING gin ((title || \' \' || COALESCE(description, \'\') || \' \' || COALESCE(comment, \'\')) gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_tasks_search_trgm');
    }
};
