<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personal, shareable code behind each user's QR (e.g. AMO-7KX3PQ).
        Schema::table('users', function (Blueprint $table) {
            $table->string('share_code', 12)->nullable()->unique();
        });
        DB::table('users')->whereNull('share_code')->pluck('id')->each(
            fn ($id) => DB::table('users')->where('id', $id)->update(['share_code' => User::newShareCode()])
        );

        // Buddies: people you connected with by scanning each other's QR.
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('friend_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'friend_id']);
        });

        // Fair-share contributions: a private monthly comfort amount and an anonymous pause.
        Schema::table('shared_plan_members', function (Blueprint $table) {
            $table->decimal('capacity', 12, 2)->nullable();
            $table->date('paused_until')->nullable();
        });

        // Offline outbox replay: a change synced twice is only applied once.
        Schema::create('sync_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->unsignedSmallInteger('status');
            $table->longText('body')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_receipts');
        Schema::table('shared_plan_members', fn (Blueprint $table) => $table->dropColumn(['capacity', 'paused_until']));
        Schema::dropIfExists('connections');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['share_code']);
            $table->dropColumn('share_code');
        });
    }
};
