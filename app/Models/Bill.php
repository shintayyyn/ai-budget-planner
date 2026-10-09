<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['category_id', 'name', 'amount', 'due_day', 'is_debt', 'debt_balance', 'interest_rate', 'last_paid_on'])]
class Bill extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'is_debt' => 'boolean',
            'debt_balance' => 'float',
            'interest_rate' => 'float',
            'due_day' => 'integer',
            'last_paid_on' => 'date:Y-m-d',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** The next date (on or after $from) this bill falls due. */
    public function nextDueDate(Carbon $from): Carbon
    {
        $candidate = $from->copy()->startOfMonth();
        $candidate->day(min($this->due_day, $candidate->daysInMonth));
        if ($candidate->lt($from->copy()->startOfDay())) {
            $candidate = $from->copy()->startOfMonth()->addMonthNoOverflow();
            $candidate->day(min($this->due_day, $candidate->daysInMonth));
        }

        return $candidate;
    }

    /** All due dates falling in [$start, $end). */
    public function dueDatesBetween(Carbon $start, Carbon $end): array
    {
        $dates = [];
        $due = $this->nextDueDate($start);
        while ($due->lt($end)) {
            $dates[] = $due->copy();
            $next = $due->copy()->startOfMonth()->addMonthNoOverflow();
            $due = $next->day(min($this->due_day, $next->daysInMonth));
        }

        return $dates;
    }

    /** A due date counts as paid when the bill was paid within the 25 days before it (or later). */
    public function isPaidFor(Carbon $due): bool
    {
        return $this->last_paid_on !== null && $this->last_paid_on->gte($due->copy()->subDays(25));
    }
}
