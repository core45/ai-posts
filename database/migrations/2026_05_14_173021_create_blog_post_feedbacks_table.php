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
        Schema::create('blog_post_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_run_id')->constrained('blog_post_pipeline_runs')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module_slug', 100)->nullable();
            $table->tinyInteger('rating');
            $table->string('feedback_type', 50);
            $table->text('comment');
            $table->string('action_requested', 50)->default('none');
            $table->json('ai_analysis')->nullable();
            $table->boolean('applied_to_profile')->default(false);
            $table->boolean('applied_to_knowledge')->default(false);
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_post_feedbacks');
    }
};
