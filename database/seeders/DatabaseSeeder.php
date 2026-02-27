<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Always create Admin
        $this->call(AdminSeeder::class);

        // 2. Demo Data (Dealerships, Users, Tasks)
        // Use 'php artisan db:seed-demo' for interactive mode
        // or uncomment below for default full seed:

        // $this->call(DealershipSeeder::class);
        // $this->call(TaskSeeder::class);
    }
}
