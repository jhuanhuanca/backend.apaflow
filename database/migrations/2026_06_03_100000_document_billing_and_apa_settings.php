<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'billing_status')) {
                $table->string('billing_status', 32)->default('pending_payment')->after('status');
            }
            if (! Schema::hasColumn('documents', 'payment_id')) {
                $table->foreignId('payment_id')->nullable()->after('billing_status')->constrained('payments')->nullOnDelete();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'apa_settings')) {
                $table->json('apa_settings')->nullable()->after('registration_checkout_completed_at');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'document_id')) {
                $table->foreignId('document_id')->nullable()->after('user_id')->constrained('documents')->nullOnDelete();
            }
        });

        if (Schema::hasColumn('documents', 'billing_status')) {
            \Illuminate\Support\Facades\DB::table('documents')->update(['billing_status' => 'paid']);
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'document_id')) {
                $table->dropConstrainedForeignId('document_id');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'apa_settings')) {
                $table->dropColumn('apa_settings');
            }
        });

        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'payment_id')) {
                $table->dropConstrainedForeignId('payment_id');
            }
            if (Schema::hasColumn('documents', 'billing_status')) {
                $table->dropColumn('billing_status');
            }
        });
    }
};
