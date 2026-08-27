<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('content_modules', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->text('system_prompt_template');
            $table->text('user_prompt_template');
            $table->string('output_format', 20)->default('html');
            $table->json('quality_criteria')->nullable();
            $table->boolean('requires_research')->default(true);
            $table->boolean('requires_previous_modules')->default(true);
            $table->boolean('is_built_in')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_modules');
    }
};
