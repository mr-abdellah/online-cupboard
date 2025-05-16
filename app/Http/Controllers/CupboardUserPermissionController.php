<?php

namespace App\Http\Controllers;

use App\Models\Cupboard;
use App\Models\CupboardUserPermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class CupboardUserPermissionController extends Controller
{
    public function getCupboardWithUsersAndPermissions(Cupboard $cupboard)
    {
        $cupboard->load([
            'users' => function ($query) {
                $query->select('id', 'name', 'email', 'avatar');
            }
        ]);

        $result = [
            'cupboard' => [
                'id' => $cupboard->id,
                'name' => $cupboard->name,
                'order' => $cupboard->order,
                'created_at' => $cupboard->created_at,
                'updated_at' => $cupboard->updated_at,
            ],
            'users' => $cupboard->users->groupBy('id')->map(function ($group) {
                $user = $group->first();
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar && Storage::disk('local')->exists($user->avatar)
                        ? URL::temporarySignedRoute('private-file', now()->addMinutes(1), ['path' => $user->avatar])
                        : null,
                    'permissions' => $group->pluck('pivot.permission')->toArray(),
                ];
            })->values(),
        ];

        return response()->json($result);
    }

    public function getCupboardWithUserPermissions(Cupboard $cupboard, $userId)
    {
        $cupboard->load([
            'users' => function ($query) use ($userId) {
                $query->select('id', 'name', 'email', 'avatar')->where('id', $userId);
            }
        ]);

        $user = $cupboard->users->first();

        $result = [
            'cupboard' => [
                'id' => $cupboard->id,
                'name' => $cupboard->name,
                'order' => $cupboard->order,
                'created_at' => $cupboard->created_at,
                'updated_at' => $cupboard->updated_at,
            ],
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar && Storage::disk('local')->exists($user->avatar)
                    ? URL::temporarySignedRoute('private-file', now()->addMinutes(1), ['path' => $user->avatar])
                    : null,
                'permissions' => $cupboard->users->pluck('pivot.permission')->toArray() ?: ['manage'],
            ] : null,
        ];

        return response()->json($result);
    }

    public function assignManagePermissionToUserForCupboards(Request $request, $userId)
    {
        $validated = $request->validate([
            'cupboard_ids' => 'required|array',
            'cupboard_ids.*' => 'exists:cupboards,id',
        ]);

        $user = User::findOrFail($userId);
        $submittedCupboardIds = $validated['cupboard_ids'];

        // Get all current cupboard permissions for the user
        $existingPermissions = CupboardUserPermission::where('user_id', $userId)->pluck('cupboard_id')->toArray();
        $cupboardsToRemove = array_diff($existingPermissions, $submittedCupboardIds);

        // Delete permissions for cupboards no longer included
        if (!empty($cupboardsToRemove)) {
            CupboardUserPermission::where('user_id', $userId)
                ->whereIn('cupboard_id', $cupboardsToRemove)
                ->delete();
        }

        // Prepare and insert new permissions
        $updates = [];
        $newCupboardIds = array_diff($submittedCupboardIds, $existingPermissions);
        foreach ($newCupboardIds as $cupboardId) {
            $updates[] = [
                'cupboard_id' => $cupboardId,
                'user_id' => $userId,
                'permission' => 'manage',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($updates)) {
            CupboardUserPermission::insert($updates);
        }

        return response()->json([
            'message' => 'Manage permission updated for user across specified cupboards',
        ], 201);
    }

    public function assignManagePermissionToUsersForCupboard(Request $request, $cupboardId)
    {
        $validated = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $cupboard = Cupboard::findOrFail($cupboardId);
        $submittedUserIds = $validated['user_ids'];

        // Get all current users with permissions for this cupboard
        $existingPermissions = CupboardUserPermission::where('cupboard_id', $cupboardId)
            ->pluck('user_id')
            ->toArray();

        // Identify users to remove (those in existing but not in submitted)
        $usersToRemove = array_diff($existingPermissions, $submittedUserIds);

        // Delete permissions for users no longer included
        if (!empty($usersToRemove)) {
            CupboardUserPermission::where('cupboard_id', $cupboardId)
                ->whereIn('user_id', $usersToRemove)
                ->delete();
        }

        // Prepare and insert new permissions for users not already assigned
        $updates = [];
        $newUserIds = array_diff($submittedUserIds, $existingPermissions);
        foreach ($newUserIds as $userId) {
            $updates[] = [
                'cupboard_id' => $cupboardId,
                'user_id' => $userId,
                'permission' => 'manage',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($updates)) {
            CupboardUserPermission::insert($updates);
        }

        return response()->json([
            'message' => 'Manage permission updated for specified users for the cupboard',
        ], 201);
    }
}