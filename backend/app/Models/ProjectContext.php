<?php

namespace App\Models;

use App\Enums\ContextSection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectContext extends Model
{
    use HasUuids;

    protected $table = 'project_context';

    protected $fillable = [
        'project_id',
        'section',
        'content',
    ];

    protected $casts = [
        'section' => ContextSection::class,
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
