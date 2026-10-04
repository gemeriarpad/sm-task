<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The single login of the app, from ADMIN_USERNAME and ADMIN_PASSWORD.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = config('app.admin.username');
        $password = config('app.admin.password');

        if (blank($password)) {
            $password = Str::password(16, symbols: false);
            $this->command?->warn("ADMIN_PASSWORD is not set, generated password for [{$username}]: {$password}");
        }

        User::updateOrCreate(
            ['username' => $username],
            ['name' => Str::headline($username), 'password' => $password],
        );
    }
}
