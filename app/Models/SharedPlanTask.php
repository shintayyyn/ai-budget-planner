<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['created_by', 'assignee_id', 'title', 'estimated_cost', 'done'])]
class SharedPlanTask extends Model
{
    protected function casts(): array
    {
        return ['done' => 'boolean', 'estimated_cost' => 'float'];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
