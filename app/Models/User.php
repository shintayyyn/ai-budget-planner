<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'currency', 'monthly_income', 'pay_frequency',
    'next_payday', 'current_balance', 'alert_threshold', 'onboarded', 'payment_handle',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Mirror the database defaults so freshly created models are complete. */
    protected $attributes = [
        'currency' => 'USD',
        'monthly_income' => 0,
        'pay_frequency' => 'monthly',
        'current_balance' => 0,
        'alert_threshold' => 80,
        'onboarded' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'monthly_income' => 'float',
            'current_balance' => 'float',
            'alert_threshold' => 'float',
            'next_payday' => 'date:Y-m-d',
            'onboarded' => 'boolean',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function savingsGoals(): HasMany
    {
        return $this->hasMany(SavingsGoal::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function paydayPlans(): HasMany
    {
        return $this->hasMany(PaydayPlan::class);
    }

    public function sharedPlans(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(SharedPlan::class, 'shared_plan_members')->withPivot('role')->withTimestamps();
    }
}
