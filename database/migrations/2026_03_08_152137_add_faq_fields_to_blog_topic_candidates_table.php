<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_topic_candidates', function (Blueprint $table) {
            $table->boolean('generate_faq')->default(false)->after('image_config');
            $table->json('faq_config')->nullable()->after('generate_faq');
        });
    }

    public function down(): void
    {
        Schema::table('blog_topic_candidates', function (Blueprint $table) {
            $table->dropColumn(['generate_faq', 'faq_config']);
        });
    }
};
