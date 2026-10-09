<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['amount', 'contributed_on'])]
class GoalContribution extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'float', 'contributed_on' => 'date:Y-m-d'];
    }
}
