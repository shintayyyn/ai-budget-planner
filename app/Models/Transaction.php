<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['category_id', 'type', 'amount', 'description', 'merchant', 'occurred_on', 'source', 'receipt_path'])]
class Transaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'occurred_on' => 'date:Y-m-d',
        ];
    }

    /** Keep the user's running balance in sync with every money movement. */
    protected static function booted(): void
    {
        static::created(fn (Transaction $t) => $t->adjustBalance($t->signedAmount()));
        static::deleted(fn (Transaction $t) => $t->adjustBalance(-$t->signedAmount()));
        static::updated(function (Transaction $t) {
            $old = ($t->getOriginal('type') === 'income' ? 1 : -1) * (float) $t->getOriginal('amount');
            $t->adjustBalance($t->signedAmount() - $old);
        });
    }

    public function signedAmount(): float
    {
        return $this->type === 'income' ? $this->amount : -$this->amount;
    }

    protected function adjustBalance(float $delta): void
    {
        if ($delta != 0.0) {
            User::whereKey($this->user_id)->increment('current_balance', $delta);
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
