<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relax the NOT NULL constraint on the blog-pipeline `content_modules` prompt
 * template columns (v2.6 design §2.5, plan 48-15).
 *
 * WHY. The twenty developer-authored default module bodies moved to phasea in
 * plan 48-14. Plan 48-15 stops the seeder writing them, seeding the columns as
 * NULL — where NULL now means "use phasea's registered default for this slug".
 * Seeding null into a NOT NULL column would fail at install and break every fresh
 * environment, so the constraint must be relaxed first.
 *
 * CRITICAL — RELAX ONLY, NEVER DESTROY DATA. This migration ONLY drops the NOT
 * NULL constraint. It MUST NOT null out, overwrite, or otherwise modify any
 * existing row: those columns hold TENANT-AUTHORED template text (edited through
 * the admin UI) that is UNRECOVERABLE if destroyed. A tenant customisation is
 * preserved verbatim; only the ability to store NULL is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_modules', function (Blueprint $table): void {
            // Nullability change only — no data is touched.
            $table->text('system_prompt_template')->nullable()->change();
            $table->text('user_prompt_template')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally irreversible. Re-adding NOT NULL would fail on any row a
        // tenant legitimately left NULL ("use phasea's default"), and could not be
        // satisfied without inventing prompt text — the exact host-held instruction
        // this phase removed. The relaxed constraint is the correct steady state.
    }
};
