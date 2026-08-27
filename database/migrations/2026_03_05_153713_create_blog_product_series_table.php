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
        Schema::create('blog_product_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('blog_topic_profiles')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('target_type', 50);
            $table->json('target_ids');
            $table->unsignedInteger('total_posts');
            $table->unsignedInteger('interval_days')->default(7);
            $table->boolean('auto_publish')->default(false);
            $table->string('status', 50)->default('planning');
            $table->json('topics_json')->nullable();
            $table->timestamp('next_publish_at')->nullable();
            $table->unsignedInteger('posts_generated')->default(0);
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_product_series');
    }
};
