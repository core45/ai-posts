<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests;

use Core45\AiCore\Models\SeoIndexable;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Enums\CitationMode;
use Core45\AiPosts\Enums\WorkflowMode;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Models\BlogTopicProfile;
use Core45\AiPosts\Services\BlogPipeline\PipelineOrchestratorService;
use Core45\AiPosts\Tests\Fixtures\FakeAiClient;
use Core45\AiPosts\Tests\Fixtures\Post;
use Core45\AiPosts\Tests\Fixtures\PostTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Minimal host user model required by the pipeline's operator and enrichment
 * seams. This is a real Eloquent host adapter, not a mocked collaborator.
 */
class BlogPipelineUser extends Model
{
    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = [];

    /**
     * The bare host has no roles package. Returning an empty query accurately
     * represents a host with no administrative report recipients.
     *
     * @param  Builder<BlogPipelineUser>  $query
     * @return Builder<BlogPipelineUser>
     */
    public function scopeRole(Builder $query, array|string $roles): Builder
    {
        return $query->whereRaw('1 = 0');
    }
}

/**
 * End-to-end proof that a bare Testbench host booting only ai-core and
 * ai-posts can execute the blog RESEARCH half of PipelineOrchestratorService:
 * keyword research, user-intent research, and research synthesis, all the way
 * to a persisted pipeline run with real research data.
 *
 * The pipeline is run in StepByStep workflow mode so it PAUSES after research.
 * The writing/assembly half (module execution, internal-link enrichment, post
 * assembly) intentionally stops here: it depends on host seams every real host
 * supplies — a `category` model exposing `getUrl()`, an `internal_link_clicks`
 * analytics table, and category translation tables. Those are host obligations,
 * not package defects, so they are exercised by a host-integrated test, not this
 * bare-harness one. The research half is fully real and fakes only the remote
 * AI call.
 *
 * Migration compatibility is proved under sqlite only. SQLite does not enforce
 * cross-table foreign-key ordering at CREATE TABLE time, so this is not proof
 * that the combined package and host migrations are correctly ordered for
 * MySQL or MariaDB.
 */
class HostAppRunsBlogPipelineEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiClient $aiClient;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $locales = ['en'];

        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.batching.database', 'testing');

        $app['config']->set('ai.models', [
            'post' => Post::class,
            'post_translation' => PostTranslation::class,
            'seo_indexable' => SeoIndexable::class,
            'user' => BlogPipelineUser::class,
        ]);

        $app['config']->set('ai-posts.taxonomy.blog_post_type', 1);
        $app['config']->set('ai-posts.taxonomy.default_post_category', 1);

        $app['config']->set('translatable.locales', $locales);
        $app['config']->set('laravellocalization.supportedLocales', [
            $locales[0] => ['name' => 'English'],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->boolean('receive_system_emails')->default(false);
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_category_id')->nullable();
            $table->unsignedInteger('post_type_id')->nullable();
            $table->unsignedInteger('post_category_id')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamp('publish_at')->nullable();
            $table->string('author')->nullable();
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

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->text('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiClient = new FakeAiClient;

        $this->app->bind(
            RemoteAiClient::class,
            fn (): FakeAiClient => $this->aiClient,
        );
    }

    #[Test]
    public function a_bare_app_researches_a_topic_and_pauses_for_human_review(): void
    {
        $locale = (string) config('translatable.locales.0');

        $profile = BlogTopicProfile::create([
            'name' => 'Warehouse automation',
            'primary_locale' => $locale,
            'citation_mode' => CitationMode::Strip,
            'brand_narrative' => 'Practical guidance for warehouse operators.',
            'research_config' => [
                'enable_serp_analysis' => false,
                'enable_competitor_scraping' => false,
                'enable_gsc' => false,
                'enable_bing' => false,
                'enable_wincher' => false,
            ],
            'modules_config' => [
                'workflow_mode' => WorkflowMode::StepByStep->value,
                'checkpoint_after_research' => false,
                'modules' => [],
            ],
            'quality_config' => [],
        ]);

        $candidate = BlogTopicCandidate::create([
            'batch_id' => null,
            'title' => 'A practical warehouse automation guide',
            'slug' => 'a-practical-warehouse-automation-guide',
            'intent' => 'informational',
            'rationale' => 'Operators need a concrete implementation guide.',
            'outline_json' => [
                ['heading' => 'Introduction'],
                ['heading' => 'Implementation'],
            ],
            'evidence_json' => [],
            'rank' => 1,
            'generate_images' => false,
            'humanize_content' => false,
            'generate_faq' => false,
            'enable_citations' => false,
            'status' => BlogTopicCandidateStatus::Approved,
            'approved_by' => null,
            'approved_at' => now(),
        ]);

        $run = app(PipelineOrchestratorService::class)->start($candidate, $profile);
        $run->refresh();
        $candidate->refresh();

        $this->assertSame(BlogPostPipelineStatus::Paused, $run->status);
        $this->assertSame('checkpoint:after-research', $run->current_step);
        $this->assertNull($run->post_id);
        $this->assertSame($run->candidate_id, $candidate->id);

        $research = $run->research_data;

        $this->assertArrayHasKey('keywords', $research);
        $this->assertSame(
            'A practical warehouse automation guide',
            $research['keywords']['primary_keyword'],
        );

        $this->assertArrayHasKey('user_intent', $research);
        $this->assertArrayHasKey('intent_distribution', $research['user_intent']);

        $this->assertArrayHasKey('synthesis', $research);
        $this->assertArrayHasKey('writing_brief', $research['synthesis']);

        $this->assertSame([], $run->module_outputs);

        $this->assertSame([
            ['template' => 'blog_pipeline.research_synthesis', 'field' => null],
        ], $this->aiClient->calls);

        $this->assertTrue(Schema::hasTable('ai_prompt_overrides'));
        $this->assertTrue(Schema::hasTable('seo_indexables'));
        $this->assertTrue(Schema::hasTable('blog_post_pipeline_runs'));
    }
}
