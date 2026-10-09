<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'level', 'title', 'message', 'read_at'])]
class Alert extends Model
{
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
