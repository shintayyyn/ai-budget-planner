<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('icon', 16)->default('🎉');
            $table->enum('type', ['outing', 'goal', 'household', 'trip', 'other'])->default('outing');
            $table->enum('visibility', ['private', 'group'])->default('group');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('target_amount', 12, 2)->nullable();
            $table->date('target_date')->nullable();
            $table->string('invite_code', 12)->unique();
            $table->timestamps();
        });

        Schema::create('shared_plan_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'member'])->default('member');
            $table->timestamps();
            $table->unique(['shared_plan_id', 'user_id']);
        });

        Schema::create('shared_plan_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->enum('status', ['pending', 'accepted', 'declined'])->default('pending');
            $table->timestamps();
            $table->unique(['shared_plan_id', 'email']);
            $table->index('email');
        });

        Schema::create('shared_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['contribution', 'expense']);
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            $table->date('occurred_on');
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shared_plan_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->boolean('done')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['shared_plan_tasks', 'shared_plan_items', 'shared_plan_invites', 'shared_plan_members', 'shared_plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
