<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

class AdminSeeder extends Seeder
{
    public function run()
    {
        $user = User::updateOrCreate(
            ['email' => 'admin@opg-tiziouzou.dz'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'), // change to secure password
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $permissions = Permission::all();
        $user->givePermissionTo($permissions);
    }
}
