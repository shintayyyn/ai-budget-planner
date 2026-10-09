<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'icon', 'color', 'kind', 'keywords'])]
class Category extends Model
{
    /** Starter categories every new account receives. */
    public const DEFAULTS = [
        ['name' => 'Housing', 'icon' => '🏠', 'color' => '#6366f1', 'kind' => 'need', 'keywords' => ['rent', 'mortgage', 'landlord', 'hoa']],
        ['name' => 'Utilities', 'icon' => '💡', 'color' => '#0ea5e9', 'kind' => 'need', 'keywords' => ['electric', 'water', 'gas bill', 'internet', 'wifi', 'phone bill', 'utility']],
        ['name' => 'Groceries', 'icon' => '🛒', 'color' => '#22c55e', 'kind' => 'need', 'keywords' => ['grocery', 'groceries', 'supermarket', 'walmart', 'costco', 'aldi', 'market', 'tesco', 'kroger', 'whole foods', 'trader joe', 'safeway', 'lidl']],
        ['name' => 'Transport', 'icon' => '🚌', 'color' => '#f59e0b', 'kind' => 'need', 'keywords' => ['uber', 'lyft', 'grab', 'taxi', 'fuel', 'gas station', 'petrol', 'shell', 'bus', 'train', 'metro', 'parking', 'toll']],
        ['name' => 'Health', 'icon' => '💊', 'color' => '#ef4444', 'kind' => 'need', 'keywords' => ['pharmacy', 'doctor', 'clinic', 'hospital', 'medicine', 'dentist', 'gym']],
        ['name' => 'Debt Payments', 'icon' => '💳', 'color' => '#a855f7', 'kind' => 'need', 'keywords' => ['loan', 'credit card', 'installment', 'debt']],
        ['name' => 'Dining Out', 'icon' => '🍔', 'color' => '#f97316', 'kind' => 'want', 'keywords' => ['restaurant', 'lunch', 'dinner', 'breakfast', 'coffee', 'cafe', 'starbucks', 'mcdonald', 'kfc', 'pizza', 'burger', 'food', 'takeout', 'jollibee']],
        ['name' => 'Shopping', 'icon' => '🛍️', 'color' => '#ec4899', 'kind' => 'want', 'keywords' => ['amazon', 'shopee', 'lazada', 'clothes', 'shoes', 'mall', 'store', 'target']],
        ['name' => 'Entertainment', 'icon' => '🎬', 'color' => '#14b8a6', 'kind' => 'want', 'keywords' => ['movie', 'cinema', 'netflix', 'spotify', 'game', 'concert', 'subscription', 'disney']],
        ['name' => 'Personal', 'icon' => '✨', 'color' => '#84cc16', 'kind' => 'want', 'keywords' => ['haircut', 'salon', 'gift', 'beauty']],
        ['name' => 'Other', 'icon' => '💸', 'color' => '#64748b', 'kind' => 'want', 'keywords' => []],
        ['name' => 'Savings', 'icon' => '🐷', 'color' => '#10b981', 'kind' => 'savings', 'keywords' => ['savings', 'deposit', 'emergency fund']],
        ['name' => 'Salary', 'icon' => '💰', 'color' => '#16a34a', 'kind' => 'income', 'keywords' => ['salary', 'payroll', 'paycheck', 'wage']],
        ['name' => 'Other Income', 'icon' => '🪙', 'color' => '#65a30d', 'kind' => 'income', 'keywords' => ['refund', 'freelance', 'bonus', 'side hustle', 'interest']],
    ];

    protected function casts(): array
    {
        return ['keywords' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public static function seedDefaultsFor(User $user): void
    {
        foreach (self::DEFAULTS as $category) {
            $user->categories()->firstOrCreate(['name' => $category['name']], $category);
        }
    }
}
