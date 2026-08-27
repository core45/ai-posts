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
        Schema::create('blog_topic_llm_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->nullable()->constrained('blog_topic_batches')->nullOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained('blog_topic_candidates')->nullOnDelete();
            $table->string('step', 50);
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->text('prompt_text')->nullable();
            $table->text('response_text')->nullable();
            $table->string('prompt_hash', 64);
            $table->string('response_hash', 64);
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('batch_id');
            $table->index('step');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_topic_llm_runs');
    }
};
