<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests;

use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiCore\DTOs\AiTextResult;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiPosts\Services\PostContentRewriteService;
use Core45\AiPosts\Tests\Fixtures\Post;
use Core45\AiPosts\Tests\Fixtures\PostTranslation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end proof for the translation capability shared from ai-core.
 *
 * A bare Testbench host boots only ai-core and ai-posts, persists an
 * Astrotomic-translatable host model, and drives the public post translation
 * entry point through ai-core's real prompt construction, AiClientFactory,
 * AiTextResult handling, rich/plain processing, and saveTranslation(). Only
 * the outermost RemoteAiClient is replaced.
 *
 * Migration compatibility is proved under sqlite only. sqlite does not enforce
 * cross-table foreign-key ordering at CREATE TABLE time, so this is not proof
 * that package migrations are correctly ordered for MySQL.
 *
 * Empty-response behaviour is intentionally pinned, not changed here: an empty
 * successful AI result currently overwrites live target content. Whether that
 * needs a rejection guard is the owner's handoff section 5 product decision.
 */
class HostAppTranslatesPostEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This is a real host adapter, not a fake: the post translation flow
        // requires locale-aware slug creation after translating the title.
        $this->app->bind(GeneratesSlugs::class, fn (): GeneratesSlugs => new class implements GeneratesSlugs
        {
            public function make(string $title): string
            {
                return str($title)->slug()->toString();
            }
        });
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.models', [
            'post' => Post::class,
            'post_translation' => PostTranslation::class,
        ]);
        $app['config']->set('translatable.locales', ['en', 'de']);
        $app['config']->set('laravellocalization.supportedLocales', [
            'en' => ['name' => 'English'],
            'de' => ['name' => 'German'],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
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

    #[Test]
    public function a_bare_app_translates_and_persists_host_content(): void
    {
        [$sourceLocale, $targetLocale] = config('translatable.locales');
        $post = Post::create();

        DB::table('post_translations')->insert([
            [
                'post_id' => $post->id,
                'locale' => $sourceLocale,
                'title' => 'A practical warehouse guide',
                'slug' => 'a-practical-warehouse-guide',
                'excerpt' => 'Reduce picking errors with a clearer workflow.',
            ],
            [
                'post_id' => $post->id,
                'locale' => $targetLocale,
                'title' => 'Praktischer Lagerleitfaden',
                'slug' => 'praktischer-lagerleitfaden',
                'excerpt' => null,
            ],
        ]);

        $client = $this->mock(RemoteAiClient::class, function (MockInterface $mock) use ($sourceLocale, $targetLocale): void {
            $mock->shouldReceive('completeText')
                ->once()
                ->withArgs(function (string $systemPrompt, string $userPrompt, array $options, AiSurface $surface) use ($sourceLocale, $targetLocale): bool {
                    $this->assertSame('You are a professional translator.', $systemPrompt);
                    $this->assertStringContainsString('Reduce picking errors', $userPrompt);
                    $this->assertStringContainsString("from {$sourceLocale} to {$targetLocale}", $userPrompt);
                    $this->assertSame(AiSurface::Translate, $surface);
                    $this->assertArrayHasKey('timeout', $options);
                    $this->assertSame([$sourceLocale, $targetLocale], config('translatable.locales'));

                    return true;
                })
                ->andReturn(AiTextResult::fromArray([
                    'text' => 'Reduzieren Sie Kommissionierfehler mit einem klareren Arbeitsablauf.',
                    'prompt_tokens' => 12,
                    'completion_tokens' => 6,
                ]));
        });
        $this->app->instance(RemoteAiClient::class, $client);

        $results = app(PostContentRewriteService::class)->translateToLocale(
            $post->fresh(),
            $sourceLocale,
            $targetLocale,
        );

        $this->assertSame([], $results['errors']);
        $this->assertContains("excerpt:{$targetLocale}", $results['processed_fields']);
        $this->assertDatabaseHas('post_translations', [
            'post_id' => $post->id,
            'locale' => $targetLocale,
            'title' => 'Praktischer Lagerleitfaden',
            'slug' => 'praktischer-lagerleitfaden',
            'excerpt' => 'Reduzieren Sie Kommissionierfehler mit einem klareren Arbeitsablauf.',
        ]);
        $this->assertTrue(Schema::hasTable('ai_prompt_overrides'));
        $this->assertTrue(Schema::hasTable('blog_topic_candidates'));
    }

    #[Test]
    public function an_empty_ai_result_currently_blanks_live_target_content_when_overwriting(): void
    {
        [$sourceLocale, $targetLocale] = config('translatable.locales');
        $post = Post::create();

        DB::table('post_translations')->insert([
            [
                'post_id' => $post->id,
                'locale' => $sourceLocale,
                'title' => 'Source title',
                'slug' => 'source-title',
            ],
            [
                'post_id' => $post->id,
                'locale' => $targetLocale,
                'title' => 'Live German title',
                'slug' => 'live-german-title',
            ],
        ]);

        $client = $this->mock(RemoteAiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('completeText')
                ->once()
                ->andReturn(AiTextResult::fromArray(['text' => '']));
        });
        $this->app->instance(RemoteAiClient::class, $client);

        $results = app(PostContentRewriteService::class)->translateToLocale(
            $post->fresh(),
            $sourceLocale,
            $targetLocale,
            overwrite: true,
        );

        $this->assertSame([], $results['errors']);
        $this->assertContains("title:{$targetLocale}", $results['processed_fields']);
        $this->assertDatabaseHas('post_translations', [
            'post_id' => $post->id,
            'locale' => $targetLocale,
            'title' => '',
            'slug' => 'live-german-title',
        ]);
    }
}
