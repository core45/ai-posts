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
        Schema::table('blog_topic_candidates', function (Blueprint $table) {
            $table->foreignId('series_id')->nullable()->after('batch_id')
                ->constrained('blog_product_series')->nullOnDelete();
            $table->timestamp('scheduled_publish_at')->nullable()->after('approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blog_topic_candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('series_id');
            $table->dropColumn('scheduled_publish_at');
        });
    }
};
