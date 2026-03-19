<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'reporter@scc.test'],
            [
                'name' => 'Demo Reporter',
                'password' => Hash::make('password'),
                'role' => User::ROLE_REPORTER,
                'department' => null,
                'phone' => null,
                'is_active' => true,
                'email_verified_at' => Carbon::now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'admin@scc.test'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
                'department' => null,
                'phone' => null,
                'is_active' => true,
                'email_verified_at' => Carbon::now(),
            ]
        );

        // Backward-compat demo admin login (older UI references)
        User::query()->updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('123456'),
                'role' => User::ROLE_ADMIN,
                'department' => null,
                'phone' => null,
                'is_active' => true,
                'email_verified_at' => Carbon::now(),
            ]
        );
    }
}

