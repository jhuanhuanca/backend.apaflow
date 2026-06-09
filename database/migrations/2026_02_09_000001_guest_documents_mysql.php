<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitado: columna guest_fingerprint + user_id nullable (MySQL con SQL directo para evitar doctrine/dbal).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'guest_fingerprint')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->string('guest_fingerprint', 64)->nullable()->index();
            });
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable) {
                // Ya eliminada o nombre distinto
            }
        });

        DB::statement('ALTER TABLE documents MODIFY user_id BIGINT UNSIGNED NULL');

        Schema::table('documents', function (Blueprint $table) {
            try {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            } catch (\Throwable) {
                // FK ya existente
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'guest_fingerprint')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropColumn('guest_fingerprint');
            });
        }
    }
};
