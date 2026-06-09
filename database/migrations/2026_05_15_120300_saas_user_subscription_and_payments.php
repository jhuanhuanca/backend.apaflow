<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan', 16)->default('free')->after('password');
            $table->string('subscription_status', 24)->default('active')->after('plan');
            $table->timestamp('trial_ends_at')->nullable()->after('subscription_status');
            $table->boolean('is_blocked')->default(false)->after('trial_ends_at');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('USD');
            $table->string('status', 24)->default('pending');
            $table->string('provider', 32)->nullable();
            $table->string('external_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['plan', 'subscription_status', 'trial_ends_at', 'is_blocked']);
        });
    }
};
