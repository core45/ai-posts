<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Host taxonomy values
    |--------------------------------------------------------------------------
    |
    | §3 splits enums by ownership. A blog-pipeline status has no meaning
    | outside this package, so it moved here as a real enum. These three are the
    | opposite: they are the HOST's content taxonomy, cast on the host's own
    | models, driving its public URLs and its admin filters. A host with posts
    | and no AI package still needs them, so the package must not own them —
    | and must not import them either.
    |
    | So they cross the seam as plain backing values. The defaults below are the
    | values this application already uses, which is what makes the swap a
    | no-op here; a host whose taxonomy differs overrides them and needs no code
    | change.
    |
    | These are written into, and queried against, host columns that cast to the
    | host's own enums. Eloquent accepts a backed enum's raw value on both
    | sides, so passing the scalar is equivalent to passing the case.
    |
    | Keep the type: `post_type` and `post_category` are int-backed, and
    | `faq_display_location` is string-backed. Writing '2' where 2 is expected
    | works until it reaches a strict comparison.
    */

    'taxonomy' => [
        // The post type meaning "a blog post" — what the pipeline publishes as.
        'blog_post_type' => env('AI_POSTS_BLOG_POST_TYPE', 2),

        // The category a generated post is filed under when nothing else applies.
        'default_post_category' => env('AI_POSTS_DEFAULT_POST_CATEGORY', 1),

        // Display location for FAQs the pipeline creates: they are attached to
        // the post, not surfaced on a standalone page.
        'faq_display_location' => env('AI_POSTS_FAQ_DISPLAY_LOCATION', 'none'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Host-owned jobs the pipeline hands off to
    |--------------------------------------------------------------------------
    |
    | Same seam ai-core already uses for `ai.finalize_job` and
    | `ai.analyze_feedback_job`: these jobs edit host records and use host
    | infrastructure, so naming them is the application's job, not the
    | package's.
    |
    | Every one is OPTIONAL and defaults to null, because a drop-in install
    | genuinely may not have them. Null is handled explicitly at each call site
    | — never by falling through to a class name the package guessed.
    |
    | Leaving one null disables that hand-off. That is a real reduction in what
    | the pipeline does, so each call site logs when it skips: a step that
    | silently does nothing is worse than one that fails loudly.
    */

    'jobs' => [
        // Queue middleware releasing a job back when the AI provider rate-limits.
        // Null means no middleware: jobs still run, they just retry on the
        // queue's own terms instead of the provider's.
        'rate_limit_middleware' => env('AI_POSTS_RATE_LIMIT_MIDDLEWARE'),

        // Chained after generation to run AI-detection and humanisation over the
        // fresh draft. Constructed with (Model $post, string $locale).
        'humanize' => env('AI_POSTS_HUMANIZE_JOB'),

        // Dispatched with (Model $seoIndexable) after link enrichment so the SEO
        // analysis re-runs against the enriched content.
        'analyze_seo' => env('AI_POSTS_ANALYZE_SEO_JOB'),
    ],

];
