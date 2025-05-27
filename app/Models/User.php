<?php

namespace App\Models;

use App\Traits\HasUuid;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Notifiable, HasFactory, HasUuid, HasApiTokens, HasRoles;

    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot()
    {
        parent::boot();

        static::bootHasUuid();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'status',
        'last_login_at',
        'last_login_ip',
        'created_by',
        'updated_by'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Get the workspaces created by this user.
     *
     * @return HasMany
     */
    public function workspaces()
    {
        return $this->hasMany(Workspace::class, 'user_id');
    }

    /**
     * Get the workspaces this user has permissions for.
     *
     * @return BelongsToMany
     */
    public function permittedWorkspaces()
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user_permissions', 'user_id', 'workspace_id')
            ->withPivot('permission')
            ->withTimestamps();
    }

    /**
     * Get the cupboards this user has permissions for.
     *
     * @return BelongsToMany
     */
    public function permittedCupboards()
    {
        return $this->belongsToMany(Cupboard::class, 'cupboard_user_permissions', 'user_id', 'cupboard_id')
            ->withPivot('permission')
            ->withTimestamps();
    }

    /**
     * Get the documents created by this user.
     *
     * @return HasMany
     */
    public function documents()
    {
        return $this->hasMany(Document::class, 'user_id', 'id');
    }

    /**
     * Get the documents this user has permissions for.
     *
     * @return BelongsToMany
     */
    public function permittedDocuments()
    {
        return $this->belongsToMany(Document::class, 'document_user_permissions', 'user_id', 'document_id')
            ->withPivot('permission')
            ->withTimestamps();
    }

    /**
     * Get the binders created by this user.
     *
     * @return HasMany
     */
    public function binders()
    {
        return $this->hasMany(Binder::class, 'user_id', 'id');
    }

    /**
     * Get the users created by this user.
     *
     * @return HasMany
     */
    public function createdUsers()
    {
        return $this->hasMany(User::class, 'created_by', 'id');
    }

    /**
     * Get the users updated by this user.
     *
     * @return HasMany
     */
    public function updatedUsers()
    {
        return $this->hasMany(User::class, 'updated_by', 'id');
    }

    /**
     * Get the user who created this user.
     *
     * @return BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * Get the user who last updated this user.
     *
     * @return BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by', 'id');
    }

    /**
     * Check if user has global permission.
     *
     * @param string $permission
     * @return bool
     */
    public function hasGlobalPermission($permission)
    {
        return $this->hasPermissionTo($permission);
    }

    /**
     * Check if user has workspace permission.
     *
     * @param Workspace $workspace
     * @param string $permission
     * @return bool
     */
    public function hasWorkspacePermission(Workspace $workspace, $permission)
    {
        return WorkspaceUserPermission::where('user_id', $this->id)
            ->where('workspace_id', $workspace->id)
            ->where('permission', $permission)
            ->exists();
    }

    /**
     * Check if user has cupboard permission.
     *
     * @param Cupboard $cupboard
     * @param string $permission
     * @return bool
     */
    public function hasCupboardPermission(Cupboard $cupboard, $permission)
    {
        return CupboardUserPermission::where('user_id', $this->id)
            ->where('cupboard_id', $cupboard->id)
            ->where('permission', $permission)
            ->exists();
    }

    /**
     * Check if user has document permission.
     *
     * @param Document $document
     * @param string $permission
     * @return bool
     */
    public function hasDocumentPermission(Document $document, $permission)
    {
        return DocumentUserPermission::where('user_id', $this->id)
            ->where('document_id', $document->id)
            ->where('permission', $permission)
            ->exists();
    }
}
