<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_placements', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location', 64)->index();
            $table->string('format', 32)->default('banner');
            $table->string('status', 16)->default('active')->index();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('audience', 32)->default('non_pro');
            $table->string('provider', 32)->default('placeholder');
            $table->string('slot_id')->nullable();
            $table->string('label')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_placements');
    }
};
