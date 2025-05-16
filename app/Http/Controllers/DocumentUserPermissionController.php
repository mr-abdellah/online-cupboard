<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentUserPermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class DocumentUserPermissionController extends Controller
{
    public function storeOrUpdate(Request $request, Document $document)
    {
        // Validate the request input
        $validated = $request->validate([
            'users' => 'nullable|array',
            'users.*.user_id' => 'required|exists:users,id',
            'users.*.permissions' => 'array',
            'users.*.permissions.*' => 'in:view,edit,delete,download',
            'is_public' => 'nullable|boolean',
        ]);

        // Log the entire request payload for debugging
        Log::info('storeOrUpdate request payload', [
            'document_id' => $document->id,
            'payload' => $request->all()
        ]);

        // Check if is_public is provided in the request and update the document
        if (isset($validated['is_public'])) {
            $document->is_public = $validated['is_public'];
            $document->save();

            // If is_public is true, clear permissions except for the owner
            if ($validated['is_public']) {
                DocumentUserPermission::where('document_id', $document->id)
                    ->where('user_id', '!=', $document->user_id)
                    ->delete();
                Log::info('Document set to public; non-owner permissions cleared', [
                    'document_id' => $document->id
                ]);
                return response()->json([
                    'message' => 'Document set to public; non-owner permissions have been cleared',
                    'users' => []
                ], 200);
            }
        }

        // If users data is not provided, return early
        if (!isset($validated['users'])) {
            Log::info('No users provided for permission updates', [
                'document_id' => $document->id
            ]);
            return response()->json([
                'message' => 'No users provided for permission updates',
                'users' => []
            ], 200);
        }

        $usersData = $validated['users'];

        // Start a transaction to ensure consistency
        DB::beginTransaction();
        try {
            foreach ($usersData as $userData) {
                $userId = $userData['user_id'];
                $permissions = $userData['permissions'] ?? [];

                // Log the input for debugging
                Log::info('Processing permissions for user', [
                    'user_id' => $userId,
                    'document_id' => $document->id,
                    'requested_permissions' => $permissions,
                    'is_owner' => $userId == $document->user_id
                ]);

                // Delete all existing permissions for this user (no owner protection)
                DocumentUserPermission::where('document_id', $document->id)
                    ->where('user_id', $userId)
                    ->delete();
                Log::info('Deleted existing permissions for user', [
                    'user_id' => $userId,
                    'document_id' => $document->id
                ]);

                // If no permissions provided, skip to next user
                if (empty($permissions)) {
                    Log::info('No permissions provided; skipping insert', [
                        'user_id' => $userId,
                        'document_id' => $document->id
                    ]);
                    continue;
                }

                // Prepare new permissions for insertion
                $updates = [];
                foreach ($permissions as $permission) {
                    $updates[] = [
                        'document_id' => $document->id,
                        'user_id' => $userId,
                        'permission' => $permission,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // Insert new permissions
                if (!empty($updates)) {
                    DocumentUserPermission::insert($updates);
                    Log::info('Inserted new permissions', [
                        'user_id' => $userId,
                        'document_id' => $document->id,
                        'permissions' => $permissions
                    ]);
                }
            }

            // Commit the transaction
            DB::commit();

            // Fetch updated permissions for all affected users
            $updatedPermissions = DocumentUserPermission::where('document_id', $document->id)
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
                'document_id' => $document->id,
                'permissions' => $updatedPermissions->toArray()
            ]);

            return response()->json([
                'message' => 'Permissions updated successfully',
                'users' => $updatedPermissions,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Permission update failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Failed to update permissions: ' . $e->getMessage()], 500);
        }
    }



    public function getDocumentWithUsersAndPermissions(Document $document)
    {
        $result = [
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'description' => $document->description,
                'type' => $document->type,
                'path' => $document->path,
                'is_searchable' => $document->is_searchable,
                'tags' => $document->tags,
                'binder_id' => $document->binder_id,
                'created_at' => $document->created_at,
                'updated_at' => $document->updated_at,
                'is_public' => $document->is_public,
            ],
        ];

        if (!$document->is_public) {
            $document->load([
                'permissions.user' => function ($query) {
                    $query->select('id', 'name', 'email', 'avatar');
                }
            ]);

            $result['users'] = $document->permissions->groupBy('user_id')->map(function ($group) {
                $user = $group->first()->user;
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar && Storage::disk('local')->exists($user->avatar)
                        ? URL::temporarySignedRoute('private-file', now()->addMinutes(1), ['path' => $user->avatar])
                        : null,
                    'permissions' => $group->pluck('permission')->toArray(),
                ];
            })->values();
        } else {
            // For public documents, include only the owner's permissions from the database
            $ownerId = $document->user_id; // Use user_id instead of created_by
            $owner = User::select('id', 'name', 'email', 'avatar')->find($ownerId);
            if ($owner) {
                $ownerPermissions = DocumentUserPermission::where('document_id', $document->id)
                    ->where('user_id', $ownerId)
                    ->pluck('permission')
                    ->toArray();
                $result['users'] = [
                    [
                        'id' => $owner->id,
                        'name' => $owner->name,
                        'email' => $owner->email,
                        'avatar' => $owner->avatar && Storage::disk('local')->exists($owner->avatar)
                            ? URL::temporarySignedRoute('private-file', now()->addMinutes(1), ['path' => $owner->avatar])
                            : null,
                        'permissions' => $ownerPermissions ?: ['view', 'edit', 'delete', 'download'],
                    ]
                ];
            } else {
                $result['users'] = [];
            }
        }

        return response()->json($result);
    }
}
