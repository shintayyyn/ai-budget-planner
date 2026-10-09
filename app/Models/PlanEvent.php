<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'title', 'date', 'time', 'note'])]
class PlanEvent extends Model
{
    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
