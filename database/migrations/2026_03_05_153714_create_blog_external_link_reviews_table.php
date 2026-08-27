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
        Schema::create('blog_external_link_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('blog_external_links')->cascadeOnDelete();
            $table->boolean('is_harmful');
            $table->text('reason');
            $table->text('checked_url_content')->nullable();
            $table->string('reviewed_by', 50);
            $table->timestamp('reviewed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_external_link_reviews');
    }
};
