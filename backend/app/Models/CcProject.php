<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CcProject extends Model
{
    // Schema-qualified to avoid collision with public.projects
    protected $table = 'sessions.projects';

    // Read-only: JARVIS should never write to CC Session Manager tables
    protected $guarded = ['*'];

    // CC Session Manager uses serial (integer) PKs, not UUIDs
    public $incrementing = true;
    protected $keyType = 'int';

    protected $casts = [
        'is_pinned' => 'boolean',
        'is_archived' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(CcSession::class, 'project_id');
    }
}
