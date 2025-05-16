<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CupboardUserPermission extends Model
{
    protected $table = 'cupboard_user_permissions';
    protected $primaryKey = null; // No single primary key, as it's a pivot table
    public $incrementing = false;
    public $timestamps = true;

    protected $fillable = [
        'cupboard_id',
        'user_id',
        'permission',
    ];

    // Define relationships if needed
    public function cupboard()
    {
        return $this->belongsTo(Cupboard::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}