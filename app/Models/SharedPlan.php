<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A plan or goal that can be private (owner only) or managed by a group:
 * an outing, a trip, a household pot or any shared savings target.
 */
#[Fillable(['name', 'icon', 'type', 'visibility', 'description', 'currency', 'target_amount', 'target_date'])]
class SharedPlan extends Model
{
    protected $attributes = ['visibility' => 'group', 'type' => 'outing', 'icon' => '🎉'];

    protected function casts(): array
    {
        return ['target_amount' => 'float', 'target_date' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::creating(fn (SharedPlan $p) => $p->invite_code ??= self::newInviteCode());
    }

    /** Short, unambiguous code (no 0/O/1/I) that is easy to read out or type. */
    public static function newInviteCode(): string
    {
        do {
            $code = collect(range(1, 8))->map(fn () => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)])->implode('');
        } while (self::where('invite_code', $code)->exists());

        return $code;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shared_plan_members')->withPivot('role')->withTimestamps();
    }

    public function invites(): HasMany
    {
        return $this->hasMany(SharedPlanInvite::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SharedPlanItem::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(SharedPlanTask::class);
    }

    public function isMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function isOwner(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    public function joinUrl(): string
    {
        return url('/join/'.$this->invite_code);
    }

    public function regenerateCode(): void
    {
        $this->invite_code = self::newInviteCode();
        $this->save();
    }
}
