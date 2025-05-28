<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'can_view_workspaces',
            'can_create_workspaces',
            'can_view_documents',
            'can_edit_documents',
            'can_delete_document',
            'can_upload_documents',
            'can_view_users',
            'can_edit_users',
            'can_delete_users',
            'can_create_users',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }
}
