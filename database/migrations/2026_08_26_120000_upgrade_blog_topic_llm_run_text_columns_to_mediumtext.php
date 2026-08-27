<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `blog_topic_llm_runs.prompt_text` and `.response_text` to MEDIUMTEXT.
 *
 * MySQL TEXT tops out at 64 KB. Research and synthesis prompts and their
 * responses routinely exceed that, and the failure lands as
 * "SQLSTATE[22001] Data too long" mid-pipeline, AFTER the API call has been
 * paid for — so this is a data-loss-shaped defect, not a cosmetic one.
 *
 * Why this migration exists separately: the host already carried
 * `2026_05_21_191822_upgrade_text_columns_to_mediumtext`, which widened these
 * two columns AND `notifications.data`. `notifications` is a host table, so
 * that migration could not move here wholesale, and a package cannot depend on
 * a host migration existing. This is the package's half.
 *
 * It therefore takes a NEW basename rather than the original — the one case
 * §6.1 permits, because the original still exists in the host tree and two
 * files may not share a basename. Re-running is harmless: widening a column
 * that is already MEDIUMTEXT is a no-op, so an install that already ran the
 * host migration simply applies the same shape again.
 *
 * SQLite has no distinct MEDIUMTEXT and no practical TEXT ceiling, so the
 * change is skipped there rather than emitting a pointless table rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blog_topic_llm_runs')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('blog_topic_llm_runs', function (Blueprint $table): void {
            $table->mediumText('prompt_text')->nullable()->change();
            $table->mediumText('response_text')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not reversed. Narrowing back to TEXT would truncate any
        // row that has since grown past 64 KB — a silent, unrecoverable loss.
    }
};
