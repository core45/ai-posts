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
        Schema::create('blog_external_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->nullable()->constrained('blog_topic_profiles')->nullOnDelete();
            $table->string('url', 2048);
            $table->string('url_hash', 64);
            $table->string('domain', 255);
            $table->string('label', 255);
            $table->string('status', 50)->default('active');
            $table->string('anchor_text_suggestion', 255)->nullable();
            $table->text('blocked_reason')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('blocked_at')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->unique(['url_hash', 'profile_id']);
            $table->index('status');
            $table->index('domain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_external_links');
    }
};
