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
        Schema::create('blog_profile_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('blog_topic_profiles')->cascadeOnDelete();
            $table->string('lesson_type', 50);
            $table->text('lesson_text');
            $table->json('source_feedback_ids');
            $table->decimal('confidence', 3, 2);
            $table->integer('times_applied')->default(0);
            $table->decimal('effectiveness_score', 3, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_profile_lessons');
    }
};
