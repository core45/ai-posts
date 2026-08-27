<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_topic_candidates', function (Blueprint $table) {
            $table->json('image_config')->nullable()->after('generate_images');
        });
    }

    public function down(): void
    {
        Schema::table('blog_topic_candidates', function (Blueprint $table) {
            $table->dropColumn('image_config');
        });
    }
};
