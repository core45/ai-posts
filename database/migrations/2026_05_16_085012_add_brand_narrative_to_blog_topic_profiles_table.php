<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_topic_profiles', function (Blueprint $table) {
            $table->longText('brand_narrative')->nullable()->after('constraints');
        });
    }

    public function down(): void
    {
        Schema::table('blog_topic_profiles', function (Blueprint $table) {
            $table->dropColumn('brand_narrative');
        });
    }
};
