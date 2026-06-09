<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('subscription_started_at')->nullable()->after('subscription_status');
            $table->timestamp('subscription_expires_at')->nullable()->after('subscription_started_at');
            $table->json('subscription_notifications_sent')->nullable()->after('subscription_expires_at');
        });

        $defaultDays = (int) config('saas.subscription.pro_duration_days', 30);

        DB::table('users')
            ->where('plan', 'pro')
            ->whereNull('subscription_expires_at')
            ->update([
                'subscription_started_at' => now(),
                'subscription_expires_at' => now()->addDays($defaultDays),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_started_at',
                'subscription_expires_at',
                'subscription_notifications_sent',
            ]);
        });
    }
};
