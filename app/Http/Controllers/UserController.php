<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class UserController extends Controller
{
    public function getAll(Request $request)
    {
        $authUserId = auth()->id();

        $query = User::select('id', 'name', 'email', 'avatar')
            ->where('id', '!=', $authUserId);

        if ($request->has('query')) {
            $search = $request->input('query');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = $request->input('per_page', 10);
        $users = $query->paginate($perPage);

        $users->getCollection()->transform(function ($user) {
            $user->avatar = $user->avatar && Storage::disk('local')->exists($user->avatar)
                ? URL::temporarySignedRoute('private-file', now()->addMinutes(1), ['path' => $user->avatar])
                : null;
            return $user;
        });

        return response()->json($users);
    }


    public function index(Request $request)
    {
        if (!auth()->user()->hasGlobalPermission('can_view_users')) {
            return response()->json(['error' => "You don't have permission"], 403);
        }

        $query = User::select('id', 'name', 'email', 'avatar', 'status', 'last_login_at', 'last_login_ip')
            ->with([
                'creator' => function ($q) {
                    $q->select('id', 'name', 'email', 'avatar');
                },
                'updater' => function ($q) {
                    $q->select('id', 'name', 'email', 'avatar');
                }
            ]);

        if ($request->has('query')) {
            $search = $request->input('query');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = $request->input('per_page', 10);
        $users = $query->paginate($perPage);

        $users->getCollection()->transform(function ($user) {
            $user->avatar = $user->avatar && Storage::disk('local')->exists($user->avatar)
                ? URL::temporarySignedRoute('private-file', now()->addMinutes(1), ['path' => $user->avatar])
                : null;

            if ($user->creator) {
                $user->creator->avatar = $user->creator->avatar && Storage::disk('local')->exists($user->creator->avatar)
                    ? URL::temporarySignedRoute('private-file', now()->addMinutes(60), ['path' => $user->creator->avatar])
                    : null;
            }

            if ($user->updater) {
                $user->updater->avatar = $user->updater->avatar && Storage::disk('local')->exists($user->updater->avatar)
                    ? URL::temporarySignedRoute('private-file', now()->addMinutes(60), ['path' => $user->updater->avatar])
                    : null;
            }

            return $user;
        });

        return response()->json($users);
    }


    public function store(Request $request)
    {
        if (!auth()->user()->hasGlobalPermission('can_create_users')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'avatar' => 'nullable|image|max:2048',
            'status' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'status' => $validated['status'] ?? null,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ];

        if ($request->hasFile('avatar')) {
            $avatar = $request->file('avatar');
            $filename = uniqid() . '.' . $avatar->getClientOriginalExtension();
            $path = $avatar->storeAs('avatars', $filename, 'local');
            $data['avatar'] = $path;
        }

        $user = User::create($data);

        if (!empty($validated['permissions'])) {
            $user->syncPermissions($validated['permissions']);
        }

        return response()->json($user, 201);
    }


    public function show($id)
    {
        if (!auth()->user()->hasGlobalPermission('can_view_users')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        $user = User::select('id', 'name', 'email', 'avatar', 'status')
            ->with([
                'permissions' => function ($query) {
                    $query->select('id', 'name');
                }
            ])
            ->findOrFail($id);

        $user->avatar = $user->avatar && Storage::disk('local')->exists($user->avatar)
            ? URL::temporarySignedRoute('private-file', now()->addMinutes(60), ['path' => $user->avatar])
            : null;

        $user['permissions'] = $user->getAllPermissions()->pluck('name')->all();


        return response()->json($user);
    }

    public function update(Request $request, $id)
    {
        if (!auth()->user()->hasGlobalPermission('can_edit_users')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
            'avatar' => 'nullable|image|max:2048',
            'status' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'status' => $validated['status'] ?? $user->status,
            'updated_by' => auth()->id(),
        ];

        if (isset($validated['password']) && $validated['password']) {
            $data['password'] = bcrypt($validated['password']);
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::disk('local')->exists($user->avatar)) {
                Storage::disk('local')->delete($user->avatar);
            }

            $avatar = $request->file('avatar');
            $filename = uniqid() . '.' . $avatar->getClientOriginalExtension();
            $path = $avatar->storeAs('avatars', $filename, 'local');
            $data['avatar'] = $path;
        }

        $user->update($data);

        if (isset($validated['permissions'])) {
            $user->syncPermissions($validated['permissions']);
        }

        $user->avatar = $user->avatar && Storage::disk('local')->exists($user->avatar)
            ? URL::temporarySignedRoute('private-file', now()->addMinutes(60), ['path' => $user->avatar])
            : null;

        return response()->json($user);
    }
    public function deactivate(User $user)
    {
        if (!auth()->user()->hasGlobalPermission('can_edit_users')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        $user->update(['status' => 'inactive']);

        $user->avatar = $user->avatar && Storage::disk('local')->exists($user->avatar)
            ? URL::temporarySignedRoute('private-file', now()->addMinutes(60), ['path' => $user->avatar])
            : null;

        return response()->json($user);
    }

    public function activate(User $user)
    {
        if (!auth()->user()->hasGlobalPermission('can_edit_users')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        $user->update(['status' => 'active']);

        $user->avatar = $user->avatar && Storage::disk('local')->exists($user->avatar)
            ? URL::temporarySignedRoute('private-file', now()->addMinutes(60), ['path' => $user->avatar])
            : null;

        return response()->json($user);
    }

    public function destroy(User $user)
    {
        if (!auth()->user()->hasGlobalPermission('can_delete_users')) {
            return response()->json([
                'error' => "You don't have permission"
            ], 403);
        }
        // Delete avatar if exists
        if ($user->avatar && Storage::exists($user->avatar)) {
            Storage::delete($user->avatar);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }
}
