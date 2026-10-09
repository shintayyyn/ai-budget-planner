<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Barkada space for each plan: chat room, shared notes and a calendar.
     */
    public function up(): void
    {
        Schema::create('plan_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['shared_plan_id', 'id']);
        });

        Schema::create('plan_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('body')->nullable();
            $table->boolean('pinned')->default(false);
            $table->timestamps();
        });

        Schema::create('plan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->date('date');
            $table->time('time')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['plan_events', 'plan_notes', 'plan_messages'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
