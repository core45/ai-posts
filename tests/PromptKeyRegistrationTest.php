<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests;

use Core45\AiCore\Models\AIPromptOverride;
use Core45\AiCore\Support\PromptKeyRegistry;
use PHPUnit\Framework\Attributes\Test;

/**
 * This package registers its own prompt keys (plan §9 D14).
 *
 * `ai-core` used to enumerate them, which made the package everything else
 * depends on aware of the blog taxonomy. The harness here boots `ai-core` plus
 * this package, so it proves the two halves meet: `ai-core` supplies the
 * registry, this provider fills in the blog keys, and the admin dropdown ends
 * up with both.
 */
class PromptKeyRegistrationTest extends TestCase
{
    #[Test]
    public function the_blog_prompt_keys_are_registered(): void
    {
        $offered = AIPromptOverride::availablePromptKeys();

        $this->assertSame('Post Content Rewrite System Prompt', $offered['post_rewrite_system'] ?? null);
        $this->assertSame('Post SEO Generation System Prompt', $offered['post_seo_system'] ?? null);
    }

    /**
     * Registering does not displace what `ai-core` put there first — both
     * providers write into one registry rather than replacing each other's work.
     */
    #[Test]
    public function the_core_keys_survive_alongside_them(): void
    {
        $offered = AIPromptOverride::availablePromptKeys();

        $this->assertArrayHasKey('admin_system', $offered);
        $this->assertArrayHasKey('content_generation', $offered);
    }

    /**
     * Installing `ai-posts` must not conjure another domain's keys. Nine of the
     * nineteen belong to `ai-products` and `ai-webinars`; neither is installed
     * here, so neither may appear.
     */
    #[Test]
    public function no_other_domains_keys_appear(): void
    {
        $offered = array_keys(AIPromptOverride::availablePromptKeys());

        $this->assertNotContains('product_rewrite_system', $offered);
        $this->assertNotContains('webinar_seo_system', $offered);
    }

    /**
     * The template is what the admin form previews the base prompt from. Both
     * blog keys route through phasea's rewrite templates, matching
     * `PostContentRewriteService`'s two override keys.
     */
    #[Test]
    public function each_key_carries_the_template_its_overrides_ride(): void
    {
        $registry = app(PromptKeyRegistry::class);

        $this->assertSame('rewrite.field_rewrite', $registry->templateFor('post_rewrite_system'));
        $this->assertSame('rewrite.seo_field', $registry->templateFor('post_seo_system'));
    }
}
