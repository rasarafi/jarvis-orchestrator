<?php // backend/app/Models/SessionRecap.php
namespace App\Models;

use App\Enums\RecapKind;
use App\Enums\RecapStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SessionRecap extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'kind' => RecapKind::class,
        'status' => RecapStatus::class,
        'bullets' => 'array',
        'decisions' => 'array',
        'facts' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'summarized_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(RecapThread::class, 'source_recap_id');
    }
}
