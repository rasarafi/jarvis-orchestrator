<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TaskDependency extends Model
{
    use HasUuids;

    protected $fillable = ['task_id', 'depends_on_task_id'];
}
