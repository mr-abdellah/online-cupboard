<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class Workspace extends Model
{
    use HasUuid, HasFactory, SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot()
    {
        parent::boot();

        static::bootHasUuid();

        static::creating(function ($model) {
            $model->order = static::max('order') + 1;
            if (Auth::check()) {
                $model->user_id = Auth::id();
            }
        });
    }

    protected $fillable = [
        'name',
        'description',
        'order',
        'user_id',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $dates = [
        'deleted_at',
    ];

    /**
     * Get the cupboards that belong to this workspace.
     *
     * @return HasMany
     */
    public function cupboards()
    {
        return $this->hasMany(Cupboard::class);
    }

    /**
     * Get the users that have permissions on this workspace.
     *
     * @return BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'workspace_user_permissions', 'workspace_id', 'user_id')
            ->withPivot('permission')
            ->withTimestamps();
    }

    /**
     * Get the user who created this workspace.
     *
     * @return BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get all permissions for this workspace.
     *
     * @return HasMany
     */
    public function permissions()
    {
        return $this->hasMany(WorkspaceUserPermission::class);
    }
}
