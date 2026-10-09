<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['payday', 'next_payday', 'income', 'allocations', 'daily_allowance'])]
class PaydayPlan extends Model
{
    protected function casts(): array
    {
        return [
            'payday' => 'date:Y-m-d',
            'next_payday' => 'date:Y-m-d',
            'income' => 'float',
            'allocations' => 'array',
            'daily_allowance' => 'float',
        ];
    }
}
