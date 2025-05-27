<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceUserPermission;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    /**
     * Get all workspaces that the user has access to
     */
    public function index(Request $request)
    {
        if (!auth()->user()->hasGlobalPermission('can_view_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = auth()->id();
        $searchQuery = $request->input('query');

        // Get workspace IDs where user has permissions or is the creator
        $authorizedWorkspaceIds = WorkspaceUserPermission::where('user_id', $userId)
            ->pluck('workspace_id')
            ->merge(
                Workspace::where('user_id', $userId)->pluck('id')
            )
            ->unique()
            ->toArray();

        $query = Workspace::whereIn('id', $authorizedWorkspaceIds)
            ->where('is_active', true)
            ->orderBy('order');

        if ($searchQuery) {
            $query->where('name', 'like', "%{$searchQuery}%");
        }

        $workspaces = $query->with([
            'cupboards' => function ($cupboardQuery) use ($userId) {
                $cupboardQuery->orderBy('order')
                    ->whereHas('users', function ($userQuery) use ($userId) {
                        $userQuery->where('user_id', $userId);
                    });
            }
        ])->get();

        // Add permission information to each workspace
        $workspaces = $workspaces->map(function ($workspace) use ($userId) {
            $userPermission = $workspace->users()
                ->where('user_id', $userId)
                ->first();

            // If user is creator, they have manage permission
            $permission = $workspace->user_id === $userId ? 'manage' : ($userPermission ? $userPermission->pivot->permission : null);

            return [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'description' => $workspace->description,
                'order' => $workspace->order,
                'is_active' => $workspace->is_active,
                'created_at' => $workspace->created_at,
                'updated_at' => $workspace->updated_at,
                'permission' => $permission,
                'can_manage' => $permission === 'manage',
                'cupboards_count' => $workspace->cupboards->count(),
                'cupboards' => $workspace->cupboards->map(function ($cupboard) {
                    return [
                        'id' => $cupboard->id,
                        'name' => $cupboard->name,
                        'order' => $cupboard->order,
                    ];
                })
            ];
        });

        return response()->json($workspaces);
    }

    /**
     * Get workspaces where user has manage permission
     */
    public function getManageable()
    {
        if (!auth()->user()->hasGlobalPermission('can_view_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = auth()->id();

        // Get workspaces where user is creator or has manage permission
        $manageableWorkspaceIds = WorkspaceUserPermission::where('user_id', $userId)
            ->where('permission', 'manage')
            ->pluck('workspace_id')
            ->merge(
                Workspace::where('user_id', $userId)->pluck('id')
            )
            ->unique()
            ->toArray();

        $workspaces = Workspace::whereIn('id', $manageableWorkspaceIds)
            ->where('is_active', true)
            ->orderBy('order')
            ->select('id', 'name', 'description', 'order')
            ->get();

        return response()->json($workspaces);
    }

    /**
     * Store a newly created workspace
     */
    public function store(Request $request)
    {
        if (!auth()->user()->hasGlobalPermission('can_upload_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'order' => 'nullable|integer',
        ]);

        $workspace = Workspace::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'order' => $validated['order'] ?? null, // Will be set automatically in boot method
            'is_active' => true,
        ]);

        return response()->json([
            'id' => $workspace->id,
            'name' => $workspace->name,
            'description' => $workspace->description,
            'order' => $workspace->order,
            'is_active' => $workspace->is_active,
            'created_at' => $workspace->created_at,
            'updated_at' => $workspace->updated_at,
            'permission' => 'manage',
            'can_manage' => true,
        ], 201);
    }

    /**
     * Display the specified workspace
     */
    public function show(Workspace $workspace)
    {
        if (!auth()->user()->hasGlobalPermission('can_view_documents')) {
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

        $userPermission = $workspace->users()
            ->where('user_id', $userId)
            ->first();

        $permission = $workspace->user_id === $userId ? 'manage' : ($userPermission ? $userPermission->pivot->permission : null);

        return response()->json([
            'id' => $workspace->id,
            'name' => $workspace->name,
            'description' => $workspace->description,
            'order' => $workspace->order,
            'is_active' => $workspace->is_active,
            'created_at' => $workspace->created_at,
            'updated_at' => $workspace->updated_at,
            'permission' => $permission,
            'can_manage' => $permission === 'manage',
            'cupboards' => $workspace->cupboards
        ]);
    }

    /**
     * Update the specified workspace
     */
    public function update(Request $request, Workspace $workspace)
    {
        if (!auth()->user()->hasGlobalPermission('can_edit_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = auth()->id();

        // Check if user can manage this workspace
        $canManage = $workspace->user_id === $userId ||
            $workspace->users()->where('user_id', $userId)
            ->where('permission', 'manage')->exists();

        if (!$canManage) {
            return response()->json([
                'error' => "You don't have permission to manage this workspace"
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $workspace->update($validated);

        return response()->json([
            'id' => $workspace->id,
            'name' => $workspace->name,
            'description' => $workspace->description,
            'order' => $workspace->order,
            'is_active' => $workspace->is_active,
            'created_at' => $workspace->created_at,
            'updated_at' => $workspace->updated_at,
        ]);
    }

    /**
     * Remove the specified workspace
     */
    public function destroy(Workspace $workspace)
    {
        if (!auth()->user()->hasGlobalPermission('can_delete_document')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = auth()->id();

        // Check if user can manage this workspace
        $canManage = $workspace->user_id === $userId ||
            $workspace->users()->where('user_id', $userId)
            ->where('permission', 'manage')->exists();

        if (!$canManage) {
            return response()->json([
                'error' => "You don't have permission to delete this workspace"
            ], 403);
        }

        $workspace->delete();

        return response()->json(['message' => 'Workspace deleted successfully']);
    }

    /**
     * Share workspace with user
     */
    public function shareWithUser(Request $request, Workspace $workspace)
    {
        if (!auth()->user()->hasGlobalPermission('can_edit_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = auth()->id();

        // Check if user can manage this workspace
        $canManage = $workspace->user_id === $userId ||
            $workspace->users()->where('user_id', $userId)
            ->where('permission', 'manage')->exists();

        if (!$canManage) {
            return response()->json([
                'error' => "You don't have permission to share this workspace"
            ], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'permission' => 'required|in:view,edit,manage',
        ]);

        // Check if permission already exists
        $existingPermission = WorkspaceUserPermission::where('workspace_id', $workspace->id)
            ->where('user_id', $validated['user_id'])
            ->first();

        if ($existingPermission) {
            $existingPermission->update(['permission' => $validated['permission']]);
        } else {
            WorkspaceUserPermission::create([
                'workspace_id' => $workspace->id,
                'user_id' => $validated['user_id'],
                'permission' => $validated['permission'],
            ]);
        }

        return response()->json(['message' => 'Workspace shared successfully']);
    }

    /**
     * Remove user permission from workspace
     */
    public function removeUserPermission(Request $request, Workspace $workspace)
    {
        if (!auth()->user()->hasGlobalPermission('can_edit_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = auth()->id();

        // Check if user can manage this workspace
        $canManage = $workspace->user_id === $userId ||
            $workspace->users()->where('user_id', $userId)
            ->where('permission', 'manage')->exists();

        if (!$canManage) {
            return response()->json([
                'error' => "You don't have permission to manage this workspace"
            ], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        WorkspaceUserPermission::where('workspace_id', $workspace->id)
            ->where('user_id', $validated['user_id'])
            ->delete();

        return response()->json(['message' => 'User permission removed successfully']);
    }
}
