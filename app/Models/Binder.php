<?php

namespace App\Models;

use App\Traits\HasUuid;
use Database\Factories\BinderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Binder extends Model
{
    use HasUuid, HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot()
    {
        parent::boot();

        static::bootHasUuid();

        static::creating(function ($model) {
            $model->order = static::where('cupboard_id', $model->cupboard_id)->max('order') + 1;
            if (Auth::check()) {
                $model->user_id = Auth::id();
            }
        });
    }

    protected $fillable = ['name', 'cupboard_id', 'order', 'user_id'];

    /**
     * Get the cupboard that this binder belongs to.
     *
     * @return BelongsTo
     */
    public function cupboard()
    {
        return $this->belongsTo(Cupboard::class);
    }

    /**
     * Get the documents that belong to this binder.
     *
     * @return HasMany
     */
    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Get the workspace through the cupboard relationship.
     *
     * @return Workspace|null
     */
    public function getWorkspaceAttribute()
    {
        return $this->cupboard ? $this->cupboard->workspace : null;
    }

    /**
     * Get the user who created this binder.
     *
     * @return BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
