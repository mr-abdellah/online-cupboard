<?php

namespace App\Http\Controllers;

use App\Models\Binder;
use App\Models\CupboardUserPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BinderController extends Controller
{
    public function index()
    {
        return Binder::with('cupboard', 'documents')->orderBy('order')->get();
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
            'cupboard_id' => 'required|uuid|exists:cupboards,id',
        ]);

        $userId = auth()->id();
        $hasManagePermission = CupboardUserPermission::where('user_id', $userId)
            ->where('cupboard_id', $validated['cupboard_id'])
            ->where('permission', 'manage')
            ->exists();

        if (!$hasManagePermission) {
            return response()->json([
                'error' => "You don't have permission to manage this cupboard"
            ], 403);
        }

        $binder = Binder::create([
            'name' => $validated['name'],
            'cupboard_id' => $validated['cupboard_id'],
            'order' => $validated['order'] ?? Binder::where('cupboard_id', $validated['cupboard_id'])->max('order') + 1,
        ]);

        return response()->json($binder, 201);
    }

    public function show(Binder $binder)
    {
        if (!auth()->user()->hasGlobalPermission('can_view_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        $binder->load([
            'cupboard',
            'documents' => function ($query) {
                $query->select('id', 'title', 'type', 'tags', 'order', 'is_searchable', 'is_public', 'binder_id');
            },
        ]);

        $documents = $binder->documents->map(function ($document) {
            $permissions = DB::table('document_user_permissions')
                ->where('document_id', $document->id)
                ->where('user_id', Auth::id())
                ->pluck('permission')
                ->unique()
                ->values()
                ->toArray();

            $document->permissions = $permissions;
            return $document;
        });

        $binder->setRelation('documents', $documents);

        return response()->json($binder);
    }
    public function update(Request $request, Binder $binder)
    {
        if (!auth()->user()->hasGlobalPermission('can_edit_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        $request->validate([
            'name' => 'required|string|max:255',
            'cupboard_id' => 'required|uuid|exists:cupboards,id',
            'order' => 'nullable|integer',
        ]);

        $binder->update($request->only(['name', 'cupboard_id', 'order']));

        return response()->json($binder);
    }

    public function destroy(Binder $binder)
    {
        if (!auth()->user()->hasGlobalPermission('can_delete_document')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        $binder->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function changeCupboard(Request $request, Binder $binder)
    {
        if (!auth()->user()->hasGlobalPermission('can_edit_documents')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        $validated = $request->validate([
            'cupboard_id' => 'required|uuid|exists:cupboards,id',
        ]);

        // Update the cupboard_id and reset order for this binder
        $binder->update([
            'cupboard_id' => $validated['cupboard_id'],
            'order' => Binder::where('cupboard_id', $validated['cupboard_id'])->max('order') + 1,
        ]);

        return response()->json($binder);
    }

}
