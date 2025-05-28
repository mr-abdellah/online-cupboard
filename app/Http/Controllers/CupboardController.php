<?php

namespace App\Http\Controllers;

use App\Models\Cupboard;
use App\Models\CupboardUserPermission;
use App\Models\Workspace;
use App\Models\WorkspaceUserPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CupboardController extends Controller
{
    public function getAll(Request $request)
    {
        if (!Auth::user()->hasGlobalPermission('can_view_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $validated = $request->validate([
            'workspace_id' => 'required|exists:workspaces,id',
        ]);

        $userId = Auth::id();
        $workspaceId = $validated['workspace_id'];

        $canAccessWorkspace = Workspace::where('id', $workspaceId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->orWhereHas('users', function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    });
            })
            ->exists();

        if (!$canAccessWorkspace) {
            return response()->json([
                'error' => "You don't have permission to access this workspace"
            ], 403);
        }

        $manageableCupboardIds = CupboardUserPermission::where('user_id', $userId)
            ->where('permission', 'manage')
            ->pluck('cupboard_id')
            ->toArray();

        $cupboards = Cupboard::where('workspace_id', $workspaceId)
            ->whereIn('id', $manageableCupboardIds)
            ->orderBy('order')
            ->with([
                'binders' => function ($binderQuery) {
                    $binderQuery->select('id', 'name', 'cupboard_id', 'order')
                        ->orderBy('order');
                }
            ])
            ->get()
            ->map(function ($cupboard) use ($userId) {
                $permissions = CupboardUserPermission::where('cupboard_id', $cupboard->id)
                    ->where('user_id', $userId)
                    ->pluck('permission')
                    ->toArray();

                return [
                    'id' => $cupboard->id,
                    'name' => $cupboard->name,
                    'workspace_id' => $cupboard->workspace_id,
                    'order' => $cupboard->order,
                    'created_at' => $cupboard->created_at,
                    'updated_at' => $cupboard->updated_at,
                    'permissions' => $permissions,
                    'can_manage' => in_array('manage', $permissions),
                    'binders' => $cupboard->binders->map(function ($binder) {
                        return [
                            'id' => $binder->id,
                            'name' => $binder->name,
                            'cupboard_id' => $binder->cupboard_id,
                            'order' => $binder->order,
                        ];
                    })->values(),
                ];
            });

        return response()->json($cupboards);
    }

    public function index(Request $request)
    {
        if (!Auth::user()->hasGlobalPermission('can_view_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $validated = $request->validate([
            'workspace_id' => 'required|exists:workspaces,id',
            'query' => 'nullable|string',
            'type' => 'nullable|string',
        ]);

        $userId = Auth::id();
        $workspaceId = $validated['workspace_id'];
        $searchQuery = $validated['query'] ?? '';
        $typeFilter = $validated['type'] ?? '';

        $canAccessWorkspace = Workspace::where('id', $workspaceId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->orWhereHas('users', function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    });
            })
            ->exists();

        if (!$canAccessWorkspace) {
            return response()->json([
                'error' => "You don't have permission to access this workspace"
            ], 403);
        }

        $manageableCupboardIds = CupboardUserPermission::where('user_id', $userId)
            ->where('permission', 'manage')
            ->pluck('cupboard_id')
            ->toArray();

        $query = Cupboard::where('workspace_id', $workspaceId)
            ->orderBy('order');

        if ($searchQuery || $typeFilter) {
            $query->where(function ($q) use ($searchQuery, $typeFilter) {
                if ($searchQuery) {
                    $q->where('name', 'like', "%{$searchQuery}%")
                        ->orWhereHas('binders', function ($binderQuery) use ($searchQuery, $typeFilter) {
                            $binderQuery->where('name', 'like', "%{$searchQuery}%")
                                ->orWhereHas('documents', function ($documentQuery) use ($searchQuery, $typeFilter) {
                                    $documentQuery->where('is_searchable', true);
                                    if ($searchQuery) {
                                        $documentQuery->where(function ($dq) use ($searchQuery) {
                                            $dq->where('title', 'like', "%{$searchQuery}%")
                                                ->orWhere('description', 'like', "%{$searchQuery}%")
                                                ->orWhereJsonContains('tags', $searchQuery);
                                        });
                                    }
                                    if ($typeFilter) {
                                        $documentQuery->where('type', $typeFilter);
                                    }
                                });
                        });
                } elseif ($typeFilter) {
                    $q->whereHas('binders.documents', function ($documentQuery) use ($typeFilter) {
                        $documentQuery->where('is_searchable', true)
                            ->where('type', $typeFilter);
                    });
                }
            });
        }

        $cupboards = $query->with([
            'binders' => function ($binderQuery) use ($typeFilter, $searchQuery, $manageableCupboardIds) {
                $binderQuery->whereIn('cupboard_id', $manageableCupboardIds)
                    ->orderBy('order');
                if ($searchQuery || $typeFilter) {
                    $binderQuery->where(function ($bq) use ($searchQuery, $typeFilter) {
                        if ($searchQuery) {
                            $bq->where('name', 'like', "%{$searchQuery}%")
                                ->orWhereHas('documents', function ($documentQuery) use ($searchQuery, $typeFilter) {
                                    $documentQuery->where('is_searchable', true);
                                    if ($searchQuery) {
                                        $documentQuery->where(function ($dq) use ($searchQuery) {
                                            $dq->where('title', 'like', "%{$searchQuery}%")
                                                ->orWhere('description', 'like', "%{$searchQuery}%")
                                                ->orWhereJsonContains('tags', $searchQuery);
                                        });
                                    }
                                    if ($typeFilter) {
                                        $documentQuery->where('type', $typeFilter);
                                    }
                                });
                        } elseif ($typeFilter) {
                            $bq->whereHas('documents', function ($documentQuery) use ($typeFilter) {
                                $documentQuery->where('is_searchable', true)
                                    ->where('type', $typeFilter);
                            });
                        }
                    });
                }
                $binderQuery->with([
                    'documents' => function ($documentQuery) use ($typeFilter, $searchQuery) {
                        $documentQuery->where('is_searchable', true)
                            ->select('id', 'binder_id');
                        if ($searchQuery) {
                            $documentQuery->where(function ($dq) use ($searchQuery) {
                                $dq->where('title', 'like', "%{$searchQuery}%")
                                    ->orWhere('description', 'like', "%{$searchQuery}%")
                                    ->orWhereJsonContains('tags', $searchQuery);
                            });
                        }
                        if ($typeFilter) {
                            $documentQuery->where('type', $typeFilter);
                        }
                    }
                ]);
            }
        ])->get();

        $cupboards = $cupboards->map(function ($cupboard) use ($searchQuery, $typeFilter, $manageableCupboardIds, $userId) {
            $cupboard->binders = $cupboard->binders->filter(function ($binder) use ($searchQuery, $typeFilter) {
                if (!$searchQuery && !$typeFilter) {
                    return true;
                }
                $nameMatches = $searchQuery && stripos($binder->name, $searchQuery) !== false;
                $hasMatchingDocuments = $binder->relationLoaded('documents') && $binder->documents->isNotEmpty();
                return $nameMatches || $hasMatchingDocuments;
            })->map(function ($binder) {
                unset($binder->documents);
                return $binder;
            })->values();

            $permissions = CupboardUserPermission::where('cupboard_id', $cupboard->id)
                ->where('user_id', $userId)
                ->pluck('permission')
                ->toArray();

            return [
                'id' => $cupboard->id,
                'name' => $cupboard->name,
                'workspace_id' => $cupboard->workspace_id,
                'order' => $cupboard->order,
                'created_at' => $cupboard->created_at,
                'updated_at' => $cupboard->updated_at,
                'permissions' => $permissions,
                'can_manage' => in_array('manage', $permissions),
                'binders' => $cupboard->binders,
            ];
        });

        if ($searchQuery || $typeFilter) {
            $cupboards = $cupboards->filter(function ($cupboard) use ($searchQuery) {
                $nameMatches = $searchQuery && stripos($cupboard->name, $searchQuery) !== false;
                return $nameMatches || $cupboard->binders->isNotEmpty();
            })->values();
        }

        return response()->json($cupboards);
    }

    public function store(Request $request)
    {
        if (!Auth::user()->hasGlobalPermission('can_upload_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'workspace_id' => 'required|exists:workspaces,id',
            'order' => 'nullable|integer',
        ]);

        $userId = Auth::id();
        $workspace = Workspace::findOrFail($validated['workspace_id']);
        $canAccessWorkspace = $workspace->user_id === $userId ||
            WorkspaceUserPermission::where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->exists();

        if (!$canAccessWorkspace) {
            return response()->json([
                'error' => "You don't have permission to create cupboards in this workspace"
            ], 403);
        }

        $order = $validated['order'] ?? (Cupboard::where('workspace_id', $validated['workspace_id'])->max('order') + 1);

        Log::info('Creating cupboard', [
            'user_id' => $userId,
            'workspace_id' => $validated['workspace_id'],
            'order' => $order,
        ]);

        $cupboard = Cupboard::create([
            'name' => $validated['name'],
            'workspace_id' => $validated['workspace_id'],
            'order' => $order,
        ]);

        $permissionData = collect(['manage'])->map(function ($permission) use ($cupboard, $userId) {
            return [
                'cupboard_id' => $cupboard->id,
                'user_id' => $userId,
                'permission' => $permission,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->toArray();

        CupboardUserPermission::insert($permissionData);

        Log::info('Assigned cupboard permissions', [
            'cupboard_id' => $cupboard->id,
            'user_id' => $userId,
            'permissions' => ['manage'],
        ]);

        return response()->json([
            'id' => $cupboard->id,
            'name' => $cupboard->name,
            'workspace_id' => $cupboard->workspace_id,
            'order' => $cupboard->order,
            'created_at' => $cupboard->created_at,
            'updated_at' => $cupboard->updated_at,
            'permissions' => ['manage'],
            'can_manage' => true,
        ], 201);
    }

    public function show(Cupboard $cupboard)
    {
        if (!Auth::user()->hasGlobalPermission('can_view_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = Auth::id();
        $canView = $cupboard->workspace->user_id === $userId ||
            WorkspaceUserPermission::where('workspace_id', $cupboard->workspace_id)
            ->where('user_id', $userId)
            ->exists() ||
            CupboardUserPermission::where('cupboard_id', $cupboard->id)
            ->where('user_id', $userId)
            ->exists();

        if (!$canView) {
            return response()->json([
                'error' => "You don't have permission to view this cupboard"
            ], 403);
        }

        $cupboard->load(['binders' => function ($query) {
            $query->select('id', 'name', 'cupboard_id', 'order')->orderBy('order');
        }]);

        $permissions = CupboardUserPermission::where('cupboard_id', $cupboard->id)
            ->where('user_id', $userId)
            ->pluck('permission')
            ->toArray();

        return response()->json([
            'id' => $cupboard->id,
            'name' => $cupboard->name,
            'workspace_id' => $cupboard->workspace_id,
            'order' => $cupboard->order,
            'created_at' => $cupboard->created_at,
            'updated_at' => $cupboard->updated_at,
            'permissions' => $permissions,
            'can_manage' => in_array('manage', $permissions),
            'binders' => $cupboard->binders,
        ]);
    }

    public function update(Request $request, Cupboard $cupboard)
    {
        if (!Auth::user()->hasGlobalPermission('can_edit_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = Auth::id();
        $canEdit = CupboardUserPermission::where('cupboard_id', $cupboard->id)
            ->where('user_id', $userId)
            ->where('permission', 'manage')
            ->exists();

        if (!$canEdit) {
            return response()->json([
                'error' => "You don't have permission to edit this cupboard"
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'order' => 'nullable|integer',
        ]);

        $cupboard->update($validated);

        $permissions = CupboardUserPermission::where('cupboard_id', $cupboard->id)
            ->where('user_id', $userId)
            ->pluck('permission')
            ->toArray();

        return response()->json([
            'id' => $cupboard->id,
            'name' => $cupboard->name,
            'workspace_id' => $cupboard->workspace_id,
            'order' => $cupboard->order,
            'created_at' => $cupboard->created_at,
            'updated_at' => $cupboard->updated_at,
            'permissions' => $permissions,
            'can_manage' => in_array('manage', $permissions),
        ]);
    }

    public function destroy(Cupboard $cupboard)
    {
        if (!Auth::user()->hasGlobalPermission('can_delete_document')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = Auth::id();
        $canDelete = CupboardUserPermission::where('cupboard_id', $cupboard->id)
            ->where('user_id', $userId)
            ->where('permission', 'manage')
            ->exists();

        if (!$canDelete) {
            return response()->json([
                'error' => "You don't have permission to delete this cupboard"
            ], 403);
        }

        $cupboard->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
