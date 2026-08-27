<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_topic_candidates', function (Blueprint $table) {
            $table->boolean('generate_images')->default(false)->after('risk_flags_json');
        });
    }

    public function down(): void
    {
        Schema::table('blog_topic_candidates', function (Blueprint $table) {
            $table->dropColumn('generate_images');
        });
    }
};
