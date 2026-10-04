<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        // Real GitHub accounts in every sync and clone state; needs network access.
        if (app()->isLocal()) {
            $this->call(DemoSeeder::class);
        }
    }
}
