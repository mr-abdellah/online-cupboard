<?php

namespace App\Models;

use App\Traits\HasUuid;
use Database\Factories\CupboardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Cupboard extends Model
{
    use HasUuid, HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected static function newFactory()
    {
        return CupboardFactory::new();
    }

    protected static function boot()
    {
        parent::boot();

        static::bootHasUuid();

        static::creating(function ($model) {
            $model->order = Cupboard::max('order') + 1;
            $model->user_id = Auth::id();
        });
    }
    protected $fillable = ['name', 'order', 'user_id'];

    public function binders()
    {
        return $this->hasMany(Binder::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cupboard_user_permissions', 'cupboard_id', 'user_id')
            ->withPivot('permission');
    }
}
