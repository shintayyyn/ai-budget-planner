<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD');
            $table->decimal('monthly_income', 12, 2)->default(0);
            $table->enum('pay_frequency', ['weekly', 'biweekly', 'semimonthly', 'monthly'])->default('monthly');
            $table->date('next_payday')->nullable();
            $table->decimal('current_balance', 12, 2)->default(0);
            $table->decimal('alert_threshold', 5, 2)->default(80);
            $table->boolean('onboarded')->default(false);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon', 16)->default('💸');
            $table->string('color', 9)->default('#64748b');
            $table->enum('kind', ['need', 'want', 'savings', 'income'])->default('want');
            $table->json('keywords')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['expense', 'income'])->default('expense');
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            $table->string('merchant')->nullable();
            $table->date('occurred_on');
            $table->enum('source', ['manual', 'chat', 'receipt', 'bill'])->default('manual');
            $table->string('receipt_path')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'occurred_on']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->unique(['user_id', 'category_id', 'month']);
        });

        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->unsignedTinyInteger('due_day');
            $table->boolean('is_debt')->default(false);
            $table->decimal('debt_balance', 12, 2)->nullable();
            $table->decimal('interest_rate', 5, 2)->nullable();
            $table->date('last_paid_on')->nullable();
            $table->timestamps();
        });

        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon', 16)->default('🎯');
            $table->decimal('target_amount', 12, 2);
            $table->decimal('saved_amount', 12, 2)->default(0);
            $table->date('target_date')->nullable();
            $table->decimal('monthly_contribution', 12, 2)->nullable();
            $table->unsignedTinyInteger('priority')->default(2);
            $table->timestamps();
        });

        Schema::create('goal_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('savings_goal_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('contributed_on');
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->enum('level', ['info', 'warning', 'danger'])->default('info');
            $table->string('title');
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });

        Schema::create('payday_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('payday');
            $table->date('next_payday');
            $table->decimal('income', 12, 2);
            $table->json('allocations');
            $table->decimal('daily_allowance', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['payday_plans', 'alerts', 'goal_contributions', 'savings_goals', 'bills', 'budgets', 'transactions', 'categories'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['currency', 'monthly_income', 'pay_frequency', 'next_payday', 'current_balance', 'alert_threshold', 'onboarded']);
        });
    }
};
