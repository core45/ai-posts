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
        Schema::create('blog_topic_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('blog_topic_profiles')->cascadeOnDelete();
            $table->string('status', 50)->default('draft');
            $table->json('config_json')->nullable();
            $table->json('queries_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_topic_batches');
    }
};
