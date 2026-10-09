<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['email', 'invited_by', 'status'])]
class SharedPlanInvite extends Model
{
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SharedPlan::class, 'shared_plan_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
