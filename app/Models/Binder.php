<?php

namespace App\Models;

use App\Traits\HasUuid;
use Database\Factories\BinderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Binder extends Model
{
    use HasUuid, HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected static function newFactory()
    {
        return BinderFactory::new();
    }

    protected static function boot()
    {
        parent::boot();

        static::bootHasUuid();

        static::creating(function ($model) {
            $model->order = Binder::where('cupboard_id', $model->cupboard_id)->max('order') + 1;
            $model->user_id = Auth::id();
        });
    }

    protected $fillable = ['name', 'cupboard_id', 'order', 'user_id'];

    public function cupboard()
    {
        return $this->belongsTo(Cupboard::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }
}
