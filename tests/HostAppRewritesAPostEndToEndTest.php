<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests;

use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiPosts\Services\PostContentRewriteService;
use Core45\AiPosts\Tests\Fixtures\FakeAiClient;
use Core45\AiPosts\Tests\Fixtures\Post;
use Core45\AiPosts\Tests\Fixtures\PostCategory;
use Core45\AiPosts\Tests\Fixtures\PostTranslation;
use Core45\AiPosts\Tests\Fixtures\PostType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * THE END-TO-END PROOF.
 *
 * A bare Laravel application — the Testbench harness boots `ai-core` and
 * `ai-posts` and nothing else — resolves `PostContentRewriteService` from the
 * container and rewrites a real Post, persisting the result to a real sqlite
 * database through real Eloquent models and the packages' own migrations.
 *
 * ONE thing is faked, and only one: the AI client, which would otherwise make
 * an HTTP call to the phasea service.
 *
 * WHAT A BARE HOST MUST SUPPLY, in the order the failures surfaced. Each line
 * is an error this test actually produced, not a prediction:
 *
 *  1. `config('ai.models')` with a `post` key, set in `getEnvironmentSetUp()`
 *     — `ModelRegistry` is `scoped()`. Otherwise all five fields fail with
 *     `No model registered for key "post". Add it to config('ai.models')`.
 *     `post_translation` is registered here too and IS required by the
 *     package (`getTranslationClass()`, `slugExistsForLocale()`), but this
 *     path never resolves it: removing the key was verified to still pass,
 *     because a post that already has a title and slug skips
 *     `handleNameSlug()`'s slug branch entirely.
 *  2. `config('translatable.locales')`. Otherwise Astrotomic throws
 *     `LocalesNotDefinedException`; `getLocales()`'s `['en']` default never
 *     applies, because Astrotomic's `Locales` throws first.
 *  3. A binding for the CONCRETE `RemoteAiClient`, not the `AiClient`
 *     contract — see `setUp()`. Otherwise five real HTTP requests go out.
 *  4. A `tags` relation on `Post` exposing `name`.
 *     Otherwise: `RelationNotFoundException: Call to undefined relationship
 *     [tags]` from `PostContentRewriteService.php:276`.
 *  5. A `productCategory` relation — same `load()` call, same exception shape.
 *     Its target must additionally be Astrotomic-translatable with a `name`
 *     attribute, because `buildModelContext()` calls
 *     `$productCategory->translate($locale)?->name` (PostContentRewriteService
 *     .php:305-311). The post below sets a non-null `product_category_id` so
 *     that branch is actually executed, not merely declared.
 *  6. `post_type_id`/`post_category_id` cast to backed enums exposing
 *     `label()`. A plain int gives `Error: Call to a member function label()
 *     on int` at `PostContentRewriteService.php:296` — UNCAUGHT, because
 *     `buildModelContext()` runs outside `processModel()`'s per-field try
 *     blocks, so it kills the whole run rather than one field.
 *  7. `posts` must carry `timestamps()`. Otherwise every field fails with
 *     `no such column: updated_at (SQL: update "posts" set "updated_at" …)`,
 *     because `saveTranslation()` calls `$model->touch()` for every class in
 *     `getTouchableModelClasses()` — for this service, the registered `post`
 *     class.
 *
 * Nothing else had to be bound. `GeneratesSlugs`, `StoresFocusKeyphrases`,
 * `ChecksContentSimilarity`, `ProvidesMandatoryWords` and `ObservesAiContracts`
 * all have `bindIf` defaults in `AiCoreServiceProvider`, and
 * `GeneratesContentMedia` is only resolved when media options are set — so the
 * README's claim that `PostContentRewriteService` requires the host to bind
 * `GeneratesContentMedia` does not hold for the plain rewrite path.
 *
 * The reverse is also true. The README's `post` obligations table lists
 * `blogTopicCandidate()` and the `author` column, and this fixture has
 * neither: `blogTopicCandidate()` is reached only by `translateToLocale()`
 * (PostContentRewriteService.php:113), and a missing `author` resolves to null
 * with no error.
 *
 * Of the seven, the README documents only 1, 4 and 5 ("Registered models" and
 * the `post` obligations table). Items 2, 3, 6 and 7 are undocumented: the
 * table lists `post_type_id`/`post_category_id` as plain COLUMNS without
 * saying they must be enum-cast objects with a `label()` method.
 */
class HostAppRewritesAPostEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiClient $aiClient;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.models', [
            'post' => Post::class,
            'post_translation' => PostTranslation::class,
        ]);

        // Astrotomic's own `Locales` throws `LocalesNotDefinedException` before
        // `getLocales()`'s `config('translatable.locales', ['en'])` default can
        // ever apply — verified by deleting this line.
        $app['config']->set('translatable.locales', ['en']);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_category_id')->nullable();
            $table->unsignedInteger('post_type_id')->nullable();
            $table->unsignedInteger('post_category_id')->nullable();
            // Not decoration: `saveTranslation()` calls `$model->touch()` for
            // every class in `getTouchableModelClasses()`, which for this
            // service is the registered `post` class. Without `updated_at`
            // every field fails with "no such column: updated_at".
            $table->timestamps();
        });

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('product_category_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_category_id');
            $table->string('locale')->index();
            $table->string('name')->nullable();
            $table->unique(['product_category_id', 'locale']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('post_tag', function (Blueprint $table): void {
            $table->foreignId('post_id');
            $table->foreignId('tag_id');
        });

        Schema::create('post_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id');
            $table->string('locale')->index();
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->text('excerpt')->nullable();
            $table->text('main_text')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('seo_keywords')->nullable();
            $table->unique(['post_id', 'locale']);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiClient = new FakeAiClient;

        // NOT `AiClient::class`. `AiClientFactory::for()` resolves the CONCRETE
        // `RemoteAiClient` (AiClientFactory.php:22), so overriding the documented
        // contract binding is INERT on this path — swapping this line for
        // `bind(AiClient::class, …)` was verified to leave the real client in
        // place and fire five actual HTTP requests at `ai.base_url`, each coming
        // back "AI service rejected the request (status: 401)".
        //
        // The real factory therefore stays in the loop; only the outermost
        // network client is replaced.
        $this->app->bind(RemoteAiClient::class, fn (): FakeAiClient => $this->aiClient);
    }

    /**
     * The package migrations are not published and not declared here — they
     * auto-load from the two service providers. If they did not run, the
     * rewrite below would still "work" without ever reading the tenant's
     * prompt overrides, so pin them.
     *
     * SCOPE, stated so this is not read as more than it is: proved for SQLITE
     * ONLY. sqlite does not enforce a foreign key against a table that does
     * not exist at `CREATE TABLE` time, so the trap the README documents under
     * "Foreign-key assumptions" — package migrations timestamped from
     * `2026_03_04` creating keys on literal `posts`/`users`, ordered by
     * basename across all paths — CANNOT fail in this harness. A MySQL host
     * whose `posts` or `users` is created later still hits it. Green here is
     * not evidence against that.
     */
    #[Test]
    public function the_package_migrations_run_in_the_bare_app(): void
    {
        $this->assertTrue(Schema::hasTable('ai_prompt_overrides'), 'ai-core migrations did not run.');
        $this->assertTrue(Schema::hasTable('blog_topic_candidates'), 'ai-posts migrations did not run.');
    }

    #[Test]
    public function a_bare_app_rewrites_and_persists_a_post(): void
    {
        $categoryId = DB::table('product_categories')->insertGetId([]);
        DB::table('product_category_translations')->insert([
            'product_category_id' => $categoryId,
            'locale' => 'en',
            'name' => 'Warehouse systems',
        ]);

        $post = Post::create([
            'post_type_id' => PostType::Article,
            'post_category_id' => PostCategory::News,
            // Non-null on purpose: a null FK would skip `buildModelContext()`'s
            // `$productCategory->translate($locale)?->name` branch entirely and
            // leave the fixture's translatability unexercised.
            'product_category_id' => $categoryId,
        ]);
        DB::table('post_translations')->insert([
            'post_id' => $post->id,
            'locale' => 'en',
            'title' => 'Original title',
            'slug' => 'original-title',
            'excerpt' => 'Original excerpt.',
            'main_text' => '<p>Original body.</p>',
        ]);

        $results = app(PostContentRewriteService::class)->processPost($post->fresh());

        $this->assertSame([], $results['errors'], 'Rewrite reported errors: '.json_encode($results['errors']));

        $row = DB::table('post_translations')->where('post_id', $post->id)->where('locale', 'en')->first();

        $this->assertStringContainsString('AI EXCERPT', (string) $row->excerpt);
        $this->assertStringContainsString('AI MAIN TEXT', (string) $row->main_text);
        $this->assertSame('AI SEO TITLE', $row->seo_title);
        $this->assertSame('AI SEO DESCRIPTION', $row->seo_description);
        $this->assertSame('ai, seo, keywords', $row->seo_keywords);

        $this->assertSame(
            [
                ['template' => 'rewrite.field_rewrite', 'field' => 'excerpt'],
                ['template' => 'rewrite.field_rewrite', 'field' => 'main_text'],
                ['template' => 'rewrite.seo_field', 'field' => 'seo_title'],
                ['template' => 'rewrite.seo_field', 'field' => 'seo_description'],
                ['template' => 'rewrite.seo_field', 'field' => 'seo_keywords'],
            ],
            $this->aiClient->calls,
        );

        // The host-model context really reached the wire — including the
        // translatable product category, whose branch a null FK would skip.
        $this->assertSame(
            ['en' => 'Warehouse systems'],
            $this->aiClient->parameterSets[0]['context']['product_category'],
        );
        $this->assertSame('Article', $this->aiClient->parameterSets[0]['context']['post_type']);
    }
}
