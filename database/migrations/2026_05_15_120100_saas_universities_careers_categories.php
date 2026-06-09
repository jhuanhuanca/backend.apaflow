<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('universities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country', 2)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('institution_type', 32)->default('public');
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('content_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained('universities')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('content_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['university_id', 'slug']);
        });

        Schema::create('careers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained('universities')->cascadeOnDelete();
            $table->foreignId('content_category_id')->nullable()->constrained('content_categories')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('careers');
        Schema::dropIfExists('content_categories');
        Schema::dropIfExists('universities');
    }
};
