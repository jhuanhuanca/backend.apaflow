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
            $table->timestamp('registration_checkout_completed_at')->nullable()->after('trial_ends_at');
        });

        // Cuentas ya existentes: considerar checkout hecho para no bloquear acceso.
        DB::table('users')->whereNull('registration_checkout_completed_at')->update([
            'registration_checkout_completed_at' => DB::raw('COALESCE(created_at, NOW())'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('registration_checkout_completed_at');
        });
    }
};
