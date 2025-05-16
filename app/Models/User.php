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
     * @var list<string>
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
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the documents created by this user.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'created_by', 'id');
    }

    /**
     * Get the documents this user has permissions for.
     */
    public function permittedDocuments(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_user_permissions', 'user_id', 'document_id')
            ->withPivot('permission');
    }

    public function permittedCupboards(): BelongsToMany
    {
        return $this->belongsToMany(Cupboard::class, 'cupboard_user_permissions', 'user_id', 'cupboard_id')
            ->withPivot('permission');
    }

    /**
     * Get the binders created by this user.
     */
    public function binders(): HasMany
    {
        return $this->hasMany(Binder::class, 'created_by', 'id');
    }

    /**
     * Get the users created by this user.
     */
    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by', 'id');
    }

    /**
     * Get the users updated by this user.
     */
    public function updatedUsers(): HasMany
    {
        return $this->hasMany(User::class, 'updated_by', 'id');
    }

    /**
     * Get the user who created this user.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * Get the user who last updated this user.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'id');
    }

    public function hasGlobalPermission(string $permission)
    {
        return $this->hasPermissionTo($permission);
    }

    public function hasDocumentPermission(Document $document, string $permission)
    {
        return DocumentUserPermission::where('user_id', $this->id)
            ->where('document_id', $document->id)
            ->where('permission', $permission)
            ->exists();
    }


}