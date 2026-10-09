<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'icon', 'target_amount', 'saved_amount', 'target_date', 'monthly_contribution', 'priority'])]
class SavingsGoal extends Model
{
    protected function casts(): array
    {
        return [
            'target_amount' => 'float',
            'saved_amount' => 'float',
            'monthly_contribution' => 'float',
            'target_date' => 'date:Y-m-d',
            'priority' => 'integer',
        ];
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(GoalContribution::class);
    }
}
