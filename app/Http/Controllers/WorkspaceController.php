<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceUserPermission;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    /**
     * Get workspaces where user has any permission
     */
    public function getManageable(Request $request)
    {
        if (!auth()->user()->hasGlobalPermission('can_view_workspaces')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = auth()->id();

        // Get workspaces where user is creator or has any permission
        $workspaceIds = WorkspaceUserPermission::where('user_id', $userId)
            ->pluck('workspace_id')
            ->merge(
                Workspace::where('user_id', $userId)->pluck('id')
            )
            ->unique()
            ->toArray();

        $query = Workspace::whereIn('id', $workspaceIds);

        // Add search functionality
        if ($request->has('query')) {
            $search = $request->input('query');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $workspaces = $query->orderBy('order')
            ->select('id', 'name', 'description', 'order', 'is_active', 'created_at', 'updated_at')
            ->get()
            ->map(function ($workspace) use ($userId) {
                $permissions = $workspace->user_id === $userId
                    ? ['view', 'edit', 'delete']
                    : WorkspaceUserPermission::where('workspace_id', $workspace->id)
                    ->where('user_id', $userId)
                    ->pluck('permission')
                    ->toArray();

                return [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'description' => $workspace->description,
                    'order' => $workspace->order,
                    'is_active' => $workspace->is_active,
                    'permissions' => $permissions,
                    'created_at' => $workspace->created_at,
                    'updated_at' => $workspace->updated_at,
                ];
            });

        return response()->json($workspaces);
    }

    /**
     * Store a newly created workspace
     */
    public function store(Request $request)
    {
        if (!auth()->user()->hasGlobalPermission('can_create_workspaces')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'users' => 'nullable|array',
            'users.*.user_id' => 'required|exists:users,id',
            'users.*.permissions' => 'array',
            'users.*.permissions.*' => 'in:view,edit,delete',
        ]);

        $workspace = Workspace::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        if (!empty($validated['users'])) {
            foreach ($validated['users'] as $user) {
                foreach ($user['permissions'] as $permission) {
                    WorkspaceUserPermission::create([
                        'workspace_id' => $workspace->id,
                        'user_id' => $user['user_id'],
                        'permission' => $permission,
                    ]);
                }
            }
        }

        // Include creator's permissions by default
        $creatorPermissions = ['view', 'edit', 'delete'];
        foreach ($creatorPermissions as $permission) {
            WorkspaceUserPermission::create([
                'workspace_id' => $workspace->id,
                'user_id' => auth()->id(),
                'permission' => $permission,
            ]);
        }

        $users = $workspace->users()->get()->map(function ($user) {
            return [
                'user_id' => $user->id,
                'permissions' => WorkspaceUserPermission::where('workspace_id', $user->pivot->workspace_id)
                    ->where('user_id', $user->id)
                    ->pluck('permission')
                    ->toArray(),
            ];
        });

        return response()->json([
            'id' => $workspace->id,
            'name' => $workspace->name,
            'description' => $workspace->description,
            'order' => $workspace->order,
            'is_active' => $workspace->is_active,
            'created_at' => $workspace->created_at,
            'updated_at' => $workspace->updated_at,
            'permissions' => $creatorPermissions,
            'can_manage' => true,
            'users' => $users,
        ], 201);
    }

    /**
     * Display the specified workspace
     */
    public function show(Workspace $workspace)
    {
        if (!auth()->user()->hasGlobalPermission('can_view_workspaces')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = auth()->id();

        // Check if user has access to this workspace
        $hasAccess = $workspace->user_id === $userId ||
            $workspace->users()->where('user_id', $userId)->exists();

        if (!$hasAccess) {
            return response()->json([
                'error' => "You don't have access to this workspace"
            ], 403);
        }

        $workspace->load([
            'cupboards' => function ($query) use ($userId) {
                $query->orderBy('order')
                    ->whereHas('users', function ($userQuery) use ($userId) {
                        $userQuery->where('user_id', $userId);
                    })
                    ->with(['binders' => function ($binderQuery) {
                        $binderQuery->orderBy('order');
                    }]);
            }
        ]);

        $permissions = $workspace->user_id === $userId
            ? ['view', 'edit', 'delete']
            : WorkspaceUserPermission::where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->pluck('permission')
            ->toArray();

        return response()->json([
            'id' => $workspace->id,
            'name' => $workspace->name,
            'description' => $workspace->description,
            'order' => $workspace->order,
            'is_active' => $workspace->is_active,
            'created_at' => $workspace->created_at,
            'updated_at' => $workspace->updated_at,
            'permissions' => $permissions,
            'can_manage' => in_array('delete', $permissions),
            'cupboards' => $workspace->cupboards
        ]);
    }

    /**
     * Update the specified workspace
     */
    public function update(Request $request, Workspace $workspace)
    {
        $userId = auth()->id();

        // Check if user has edit permission
        $canEdit = $workspace->user_id === $userId ||
            WorkspaceUserPermission::where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->where('permission', 'edit')
            ->exists();

        if (!$canEdit) {
            return response()->json([
                'error' => "You don't have permission to edit this workspace"
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        $workspace->update($validated);

        $permissions = $workspace->user_id === $userId
            ? ['view', 'edit', 'delete']
            : WorkspaceUserPermission::where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->pluck('permission')
            ->toArray();

        return response()->json([
            'id' => $workspace->id,
            'name' => $workspace->name,
            'description' => $workspace->description,
            'order' => $workspace->order,
            'is_active' => $workspace->is_active,
            'created_at' => $workspace->created_at,
            'updated_at' => $workspace->updated_at,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Remove the specified workspace
     */
    public function destroy(Workspace $workspace)
    {
        $userId = auth()->id();

        // Check if user has delete permission
        $canDelete = $workspace->user_id === $userId ||
            WorkspaceUserPermission::where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->where('permission', 'delete')
            ->exists();

        if (!$canDelete) {
            return response()->json([
                'error' => "You don't have permission to delete this workspace"
            ], 403);
        }

        $workspace->delete();

        return response()->json(['message' => 'Workspace deleted successfully']);
    }
}
