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
        Schema::create('blog_post_pipeline_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('blog_topic_candidates')->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('blog_topic_profiles')->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->string('status', 50)->default('researching');
            $table->string('current_step', 255)->nullable();
            $table->string('primary_locale', 10)->default('pl');
            $table->json('context_json')->nullable();
            $table->json('module_outputs')->nullable();
            $table->json('research_data')->nullable();
            $table->json('quality_log')->nullable();
            $table->json('config_snapshot')->nullable();
            $table->decimal('total_cost', 10, 4)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_post_pipeline_runs');
    }
};
