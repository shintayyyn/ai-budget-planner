<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A settlement records "user_id paid to_user_id" outside the app (cash, GCash, Maya, bank…).
        DB::statement("ALTER TABLE shared_plan_items MODIFY kind ENUM('contribution', 'expense', 'settlement') NOT NULL");
        Schema::table('shared_plan_items', function (Blueprint $table) {
            $table->foreignId('to_user_id')->nullable()->after('user_id')->constrained('users')->cascadeOnDelete();
        });
        // Shown to group members on the settle-up screen so they know where to send money. Display only.
        Schema::table('users', function (Blueprint $table) {
            $table->string('payment_handle', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('payment_handle'));
        Schema::table('shared_plan_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('to_user_id');
        });
        DB::statement("DELETE FROM shared_plan_items WHERE kind = 'settlement'");
        DB::statement("ALTER TABLE shared_plan_items MODIFY kind ENUM('contribution', 'expense') NOT NULL");
    }
};
