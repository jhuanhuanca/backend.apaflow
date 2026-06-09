<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('universities', function (Blueprint $table) {
            $table->string('plan_access', 16)->default('both')->after('status');
        });

        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('plan_access', 16)->default('both')->after('status');
        });

        Schema::table('careers', function (Blueprint $table) {
            $table->foreignId('document_template_id')->nullable()->after('content_category_id')->constrained('document_templates')->nullOnDelete();
            $table->string('category', 64)->nullable()->after('name');
            $table->string('logo_path')->nullable()->after('category');
            $table->string('plan_access', 16)->default('both')->after('status');
        });

        if (Schema::hasColumn('careers', 'image_path')) {
            foreach (DB::table('careers')->whereNotNull('image_path')->cursor() as $row) {
                DB::table('careers')->where('id', $row->id)->update(['logo_path' => $row->image_path]);
            }
            Schema::table('careers', function (Blueprint $table) {
                $table->dropColumn('image_path');
            });
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('career_id')->nullable()->after('user_id')->constrained('careers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('career_id');
        });

        Schema::table('careers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_template_id');
            $table->dropColumn(['category', 'logo_path', 'plan_access']);
            $table->string('image_path')->nullable();
        });

        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('plan_access');
        });

        Schema::table('universities', function (Blueprint $table) {
            $table->dropColumn('plan_access');
        });
    }
};
