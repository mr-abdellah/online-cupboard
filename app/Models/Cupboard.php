<?php

namespace App\Models;

use App\Traits\HasUuid;
use Database\Factories\CupboardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Cupboard extends Model
{
    use HasUuid, HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot()
    {
        parent::boot();

        static::bootHasUuid();

        static::creating(function ($model) {
            // Order within workspace, fallback to global ordering if no workspace
            if ($model->workspace_id) {
                $model->order = static::where('workspace_id', $model->workspace_id)->max('order') + 1;
            } else {
                $model->order = static::max('order') + 1;
            }

            if (Auth::check()) {
                $model->user_id = Auth::id();
            }
        });
    }

    protected $fillable = [
        'name',
        'workspace_id',
        'order',
        'user_id'
    ];

    /**
     * Get the workspace that this cupboard belongs to.
     *
     * @return BelongsTo
     */
    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the binders that belong to this cupboard.
     *
     * @return HasMany
     */
    public function binders()
    {
        return $this->hasMany(Binder::class);
    }

    /**
     * Get the users that have permissions on this cupboard.
     *
     * @return BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'cupboard_user_permissions', 'cupboard_id', 'user_id')
            ->withPivot('permission')
            ->withTimestamps();
    }

    /**
     * Get the user who created this cupboard.
     *
     * @return BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get all permissions for this cupboard.
     *
     * @return HasMany
     */
    public function permissions()
    {
        return $this->hasMany(CupboardUserPermission::class);
    }
}
