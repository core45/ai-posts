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
        Schema::create('blog_topic_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('blog_topic_batches')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('url_hash', 64);
            $table->string('domain', 255);
            $table->string('title', 500)->nullable();
            $table->text('snippet');
            $table->string('language', 10)->nullable();
            $table->date('published_at')->nullable();
            $table->decimal('reliability_score', 3, 2)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['batch_id', 'url_hash']);
            $table->index('domain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_topic_sources');
    }
};
