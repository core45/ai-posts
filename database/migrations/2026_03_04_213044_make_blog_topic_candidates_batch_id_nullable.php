<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_topic_candidates', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
        });

        Schema::table('blog_topic_candidates', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_id')->nullable()->change();
            $table->foreign('batch_id')
                ->references('id')
                ->on('blog_topic_batches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('blog_topic_candidates', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
        });

        Schema::table('blog_topic_candidates', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_id')->nullable(false)->change();
            $table->foreign('batch_id')
                ->references('id')
                ->on('blog_topic_batches')
                ->cascadeOnDelete();
        });
    }
};
