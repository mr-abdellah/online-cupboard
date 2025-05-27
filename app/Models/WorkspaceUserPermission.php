<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceUserPermission extends Model
{
    protected $table = 'workspace_user_permissions';
    protected $primaryKey = null; // No single primary key, as it's a pivot table
    public $incrementing = false;
    public $timestamps = true;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'permission',
    ];

    /**
     * Get the workspace that this permission belongs to.
     *
     * @return BelongsTo
     */
    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the user that this permission belongs to.
     *
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
