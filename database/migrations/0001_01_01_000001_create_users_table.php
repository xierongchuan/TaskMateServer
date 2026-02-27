<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Enums\Role;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id'); // BIGSERIAL
            $table->string('login', 100)->unique();
            $table->string('full_name', 255)->nullable();
            $table->bigInteger('telegram_id')->nullable()->unique();
            $table->string('phone', 50)->nullable()->index();
            $table->string('role', 50)->default(Role::EMPLOYEE->value);
            $table->bigInteger('company_id')->nullable();
            $table->bigInteger('dealership_id')->nullable();
            $table->string('password')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        // Skip PostgreSQL specific alterations for SQLite
        if (config('database.default') !== 'sqlite') {
            DB::statement(
                "ALTER TABLE users ALTER COLUMN role DROP DEFAULT;"
            );
            DB::statement(
                "ALTER TABLE users ALTER COLUMN role TYPE user_roles USING role::user_roles;"
            );
            DB::statement(
                "ALTER TABLE users ALTER COLUMN role SET DEFAULT '"
                . Role::EMPLOYEE->value
                . "';"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
