<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentUserPermission extends Model
{
    protected $fillable = ['document_id', 'user_id', 'permission'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
