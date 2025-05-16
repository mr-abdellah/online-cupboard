<?php

namespace App\Http\Controllers;

use App\Models\Cupboard;
use App\Models\CupboardUserPermission;
use Illuminate\Http\Request;

class CupboardController extends Controller
{

    public function getAll()
    {
        if (!auth()->user()->hasGlobalPermission('can_view_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = auth()->id();
        $manageableCupboardIds = CupboardUserPermission::where('user_id', $userId)
            ->where('permission', 'manage')
            ->pluck('cupboard_id')
            ->toArray();

        $cupboards = Cupboard::whereIn('id', $manageableCupboardIds)
            ->orderBy('order')
            ->with([
                'binders' => function ($binderQuery) {
                    $binderQuery->select('id', 'name', 'cupboard_id', 'order')
                        ->orderBy('order');
                }
            ])
            ->get()
            ->map(function ($cupboard) {
                return [
                    'id' => $cupboard->id,
                    'name' => $cupboard->name,
                    'order' => $cupboard->order,
                    'created_at' => $cupboard->created_at,
                    'updated_at' => $cupboard->updated_at,
                    'can_manage' => true, // All returned cupboards are manageable
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
        if (!auth()->user()->hasGlobalPermission('can_view_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $userId = auth()->id();
        $searchQuery = $request->input('query');
        $typeFilter = $request->input('type');

        // Fetch cupboard IDs where the user has 'manage' permission
        $manageableCupboardIds = CupboardUserPermission::where('user_id', $userId)
            ->where('permission', 'manage')
            ->pluck('cupboard_id')
            ->toArray();

        $query = Cupboard::query()->orderBy('order');

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

        $cupboards = $cupboards->map(function ($cupboard) use ($searchQuery, $typeFilter, $manageableCupboardIds) {
            $cupboard->binders = $cupboard->binders->filter(function ($binder) use ($searchQuery, $typeFilter) {
                if (!$searchQuery && !$typeFilter) {
                    return true; // Include all binders when no search/filter
                }
                $nameMatches = $searchQuery && stripos($binder->name, $searchQuery) !== false;
                $hasMatchingDocuments = $binder->relationLoaded('documents') && $binder->documents->isNotEmpty();
                return $nameMatches || $hasMatchingDocuments;
            })->map(function ($binder) {
                unset($binder->documents);
                return $binder;
            })->values();

            // Add can_manage boolean
            $cupboard->can_manage = in_array($cupboard->id, $manageableCupboardIds);

            return $cupboard;
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
        if (!auth()->user()->hasGlobalPermission('can_upload_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $cupboard = Cupboard::create([
            'name' => $validated['name'],
            'order' => $validated['order'] ?? Cupboard::max('order') + 1,
        ]);

        // Assign 'manage' permission to the authenticated user
        $cupboard->users()->attach(auth()->id(), ['permission' => 'manage', 'created_at' => now(), 'updated_at' => now()]);

        return response()->json($cupboard, 201);
    }

    public function show(Cupboard $cupboard)
    {
        if (!auth()->user()->hasGlobalPermission('can_view_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        return $cupboard->load('binders');
    }

    public function update(Request $request, Cupboard $cupboard)
    {
        if (!auth()->user()->hasGlobalPermission('can_edit_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        $request->validate([
            'name' => 'required|string|max:255',
            'order' => 'nullable|integer',
        ]);

        $cupboard->update($request->only(['name', 'order']));

        return response()->json($cupboard);
    }

    public function destroy(Cupboard $cupboard)
    {
        if (!auth()->user()->hasGlobalPermission('can_delete_document')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        $cupboard->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
