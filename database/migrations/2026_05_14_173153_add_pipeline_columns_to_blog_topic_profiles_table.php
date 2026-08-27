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
            $table->json('modules_config')->nullable()->after('config_json');
            $table->string('primary_locale', 10)->default('pl')->after('modules_config');
            $table->string('citation_mode', 20)->default('strip')->after('primary_locale');
            $table->json('research_config')->nullable()->after('citation_mode');
            // Per-profile quality-gate overrides (quality_threshold,
            // max_retry_attempts). Provider/model selection is owned entirely
            // by phasea — no host-side LLM config column exists.
            $table->json('quality_config')->nullable()->after('research_config');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blog_topic_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'modules_config',
                'primary_locale',
                'citation_mode',
                'research_config',
                'quality_config',
            ]);
        });
    }
};
