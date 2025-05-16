<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Auth;

class Document extends Model
{
    use HasUuid, HasFactory;
    public $incrementing = false;
    protected $keyType = 'string';

    protected static function newFactory()
    {
        return DocumentFactory::new();
    }

    protected static function boot()
    {
        parent::boot();

        static::bootHasUuid();

        static::creating(function ($model) {
            if (Auth::check()) {
                $model->user_id = Auth::id();
            }
        });
    }

    protected $fillable = [
        'title',
        'description',
        'type',
        'ocr',
        'tags',
        'binder_id',
        'path',
        'order',
        'is_searchable',
        'is_public',
        'user_id'
    ];

    protected $casts = [
        'tags' => 'array',
        'is_searchable' => 'boolean',
    ];

    public function binder()
    {
        return $this->belongsTo(Binder::class);
    }

    public function permissions()
    {
        return $this->hasMany(DocumentUserPermission::class);
    }
}
