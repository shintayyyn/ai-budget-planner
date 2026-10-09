<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    protected static function booted(): void
    {
        static::creating(fn (User $u) => $u->share_code ??= self::newShareCode());
    }

    /** Personal code behind the user's shareable QR, e.g. AMO-7KX3PQ (no 0/O/1/I). */
    public static function newShareCode(): string
    {
        do {
            $code = 'AMO-'.collect(range(1, 6))->map(fn () => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)])->implode('');
        } while (self::where('share_code', $code)->exists());

        return $code;
    }

    /** Accepts "amo7kx3pq", "AMO-7KX3PQ" or a full /u/AMO-7KX3PQ link. */
    public static function normalizeShareCode(string $code): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', preg_replace('#.*/u/#i', '', trim($code))));

        return str_starts_with($clean, 'AMO') ? 'AMO-'.substr($clean, 3) : 'AMO-'.$clean;
    }

    public function regenerateShareCode(): void
    {
        $this->forceFill(['share_code' => self::newShareCode()])->save();
    }

    public function buddies(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'connections', 'user_id', 'friend_id')->withTimestamps();
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

    public function sharedPlans(): BelongsToMany
    {
        return $this->belongsToMany(SharedPlan::class, 'shared_plan_members')->withPivot('role')->withTimestamps();
    }
}
