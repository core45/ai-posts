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
        Schema::table('blog_topic_profiles', function (Blueprint $table) {
            $table->json('config_json')->nullable()->after('constraints');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blog_topic_profiles', function (Blueprint $table) {
            $table->dropColumn('config_json');
        });
    }
};
