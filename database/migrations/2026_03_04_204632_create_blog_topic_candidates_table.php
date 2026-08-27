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
        Schema::create('blog_topic_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('blog_topic_batches')->cascadeOnDelete();
            $table->string('title', 500);
            $table->string('slug', 500);
            $table->string('intent', 255)->nullable();
            $table->text('rationale')->nullable();
            $table->json('outline_json')->nullable();
            $table->json('evidence_json')->nullable();
            $table->json('citations_json')->nullable();
            $table->unsignedTinyInteger('rank')->default(0);
            $table->json('risk_flags_json')->nullable();
            $table->string('status', 50)->default('proposed');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_topic_candidates');
    }
};
