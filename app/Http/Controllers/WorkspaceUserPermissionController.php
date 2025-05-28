<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceUserPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class WorkspaceUserPermissionController extends Controller
{
    /**
     * Store or update workspace user permissions
     */
    public function storeOrUpdate(Request $request, Workspace $workspace)
    {
        $validated = $request->validate([
            'users' => 'nullable|array',
            'users.*.user_id' => 'required|exists:users,id',
            'users.*.permissions' => 'array',
            'users.*.permissions.*' => 'in:view,edit,delete',
        ]);

        Log::info('storeOrUpdate request payload', [
            'workspace_id' => $workspace->id,
            'payload' => $request->all()
        ]);

        // If users data is not provided, return early
        if (!isset($validated['users'])) {
            Log::info('No users provided for permission updates', [
                'workspace_id' => $workspace->id
            ]);
            return response()->json([
                'message' => 'No users provided for permission updates',
                'users' => []
            ], 200);
        }

        $usersData = $validated['users'];
        $authUserId = auth()->id();
        $authUserInRequest = false;

        // Check if auth user is in the request
        foreach ($usersData as $userData) {
            if ($userData['user_id'] == $authUserId) {
                $authUserInRequest = true;
                break;
            }
        }

        DB::beginTransaction();
        try {
            foreach ($usersData as $userData) {
                $userId = $userData['user_id'];
                $permissions = $userData['permissions'] ?? [];

                Log::info('Processing permissions for user', [
                    'user_id' => $userId,
                    'workspace_id' => $workspace->id,
                    'requested_permissions' => $permissions,
                    'is_owner' => $userId == $workspace->user_id
                ]);

                // Delete existing permissions for this user (no owner protection)
                WorkspaceUserPermission::where('workspace_id', $workspace->id)
                    ->where('user_id', $userId)
                    ->delete();
                Log::info('Deleted existing permissions for user', [
                    'user_id' => $userId,
                    'workspace_id' => $workspace->id
                ]);

                // If no permissions provided, skip to next user
                if (empty($permissions)) {
                    Log::info('No permissions provided; skipping insert', [
                        'user_id' => $userId,
                        'workspace_id' => $workspace->id
                    ]);
                    continue;
                }

                // Prepare new permissions for insertion
                $updates = [];
                foreach ($permissions as $permission) {
                    $updates[] = [
                        'workspace_id' => $workspace->id,
                        'user_id' => $userId,
                        'permission' => $permission,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // Insert new permissions
                if (!empty($updates)) {
                    WorkspaceUserPermission::insert($updates);
                    Log::info('Inserted new permissions', [
                        'user_id' => $userId,
                        'workspace_id' => $workspace->id,
                        'permissions' => $permissions
                    ]);
                }
            }

            // Add default permissions for creator if not in request
            if (!$authUserInRequest && $workspace->user_id == $authUserId) {
                $creatorPermissions = ['view', 'edit', 'delete'];
                $updates = [];
                foreach ($creatorPermissions as $permission) {
                    $updates[] = [
                        'workspace_id' => $workspace->id,
                        'user_id' => $authUserId,
                        'permission' => $permission,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                WorkspaceUserPermission::insert($updates);
                Log::info('Inserted default creator permissions', [
                    'user_id' => $authUserId,
                    'workspace_id' => $workspace->id,
                    'permissions' => $creatorPermissions
                ]);
            }

            DB::commit();

            // Fetch updated permissions for all affected users
            $updatedPermissions = WorkspaceUserPermission::where('workspace_id', $workspace->id)
                ->whereIn('user_id', array_column($usersData, 'user_id'))
                ->get()
                ->groupBy('user_id')
                ->map(function ($group) {
                    return [
                        'user_id' => $group->first()->user_id,
                        'permissions' => $group->pluck('permission')->toArray(),
                    ];
                })
                ->values();

            Log::info('Final permissions fetched', [
                'workspace_id' => $workspace->id,
                'permissions' => $updatedPermissions->toArray()
            ]);

            return response()->json([
                'message' => 'Permissions updated successfully',
                'users' => $updatedPermissions,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Permission update failed', [
                'workspace_id' => $workspace->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Failed to update permissions: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get workspace with users and their permissions
     */
    public function getWorkspaceWithUsersAndPermissions(Workspace $workspace)
    {
        $result = [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'description' => $workspace->description,
                'order' => $workspace->order,
                'is_active' => $workspace->is_active,
                'created_at' => $workspace->created_at,
                'updated_at' => $workspace->updated_at,
            ],
        ];

        $workspace->load([
            'users' => function ($query) {
                $query->select('id', 'name', 'email', 'avatar');
            }
        ]);

        $result['users'] = $workspace->users->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar && Storage::disk('local')->exists($user->avatar)
                    ? URL::temporarySignedRoute('private-file', now()->addMinutes(1), ['path' => $user->avatar])
                    : null,
                'permissions' => WorkspaceUserPermission::where('workspace_id', $user->pivot->workspace_id)
                    ->where('user_id', $user->id)
                    ->pluck('permission')
                    ->toArray(),
            ];
        })->values();

        return response()->json($result);
    }
}
