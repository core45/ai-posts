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
        Schema::create('blog_external_link_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('blog_external_links')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->string('anchor_text', 255);
            $table->text('context_snippet')->nullable();
            $table->string('status', 50)->default('suggested');
            $table->string('placed_by', 50);
            $table->timestamp('suggested_at');
            $table->timestamp('accepted_at')->nullable();

            $table->index(['link_id', 'post_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_external_link_usages');
    }
};
