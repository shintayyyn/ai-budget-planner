<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'to_user_id', 'kind', 'amount', 'description', 'occurred_on', 'transaction_id'])]
class SharedPlanItem extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'float', 'occurred_on' => 'date:Y-m-d'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
