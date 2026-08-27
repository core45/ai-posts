<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Database\Factories\BlogTopicCandidateFactory;
use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $title
 * @property string $slug
 * @property string|null $intent
 * @property string|null $rationale
 * @property BlogTopicCandidateStatus $status
 * @property array<mixed>|null $outline_json
 * @property array<mixed>|null $evidence_json
 * @property array<mixed>|null $citations_json
 * @property array<mixed>|null $risk_flags_json
 * @property bool $humanize_content
 * @property bool $generate_images
 * @property array{featured_image?: bool, gallery?: array{enabled: bool, count: int}, in_text?: array{enabled: bool, count: int}, style?: string|null, profile_id?: int|null}|null $image_config
 * @property bool $generate_faq
 * @property array{enabled: bool, count: int}|null $faq_config
 * @property bool $enable_citations
 * @property int|null $rank
 * @property Carbon|null $approved_at
 * @property Carbon|null $scheduled_publish_at
 * @property int|null $post_id
 * @property int|null $pipeline_run_id
 * @property int|null $batch_id
 */
class BlogTopicCandidate extends Model
{
    /**
     * Laravel maps a model to a factory by swapping its namespace for the
     * host's factory namespace.
     * A package namespace produces nonsense, so the factory is named
     * here; without this ::factory() fatals.
     */
    protected static function newFactory(): BlogTopicCandidateFactory
    {
        return BlogTopicCandidateFactory::new();
    }

    /** @use HasFactory<BlogTopicCandidateFactory> */
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'series_id',
        'title',
        'slug',
        'intent',
        'rationale',
        'outline_json',
        'evidence_json',
        'citations_json',
        'rank',
        'risk_flags_json',
        'generate_images',
        'humanize_content',
        'image_config',
        'generate_faq',
        'faq_config',
        'enable_citations',
        'status',
        'approved_by',
        'approved_at',
        'scheduled_publish_at',
        'post_id',
        'pipeline_run_id',
    ];

    protected function casts(): array
    {
        return [
            'outline_json' => 'array',
            'evidence_json' => 'array',
            'citations_json' => 'array',
            'risk_flags_json' => 'array',
            'generate_images' => 'boolean',
            'humanize_content' => 'boolean',
            'image_config' => 'array',
            'generate_faq' => 'boolean',
            'faq_config' => 'array',
            'enable_citations' => 'boolean',
            'rank' => 'integer',
            'status' => BlogTopicCandidateStatus::class,
            'approved_at' => 'datetime',
            'scheduled_publish_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BlogTopicBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(BlogTopicBatch::class, 'batch_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(app(ModelRegistry::class)->getModelClassOrFail('user'), 'approved_by');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(app(ModelRegistry::class)->getModelClassOrFail('post'), 'post_id');
    }

    /**
     * @return BelongsTo<BlogProductSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(BlogProductSeries::class, 'series_id');
    }

    /**
     * @return BelongsTo<BlogPostPipelineRun, $this>
     */
    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(BlogPostPipelineRun::class, 'pipeline_run_id');
    }

    /**
     * Whether discarding the current pipeline run and starting fresh is the
     * correct, safe operation:
     *  - no run yet → false (use the first-run "Generate" path instead);
     *  - the run failed terminally → true;
     *  - the run is stuck (silently stopped) BUT only before it produced a Post.
     *
     * The artifact gate checks BOTH the run's and the candidate's post_id.
     * AssemblePostJob writes run.post_id first, then candidate.post_id; gating
     * on either being set means a worker killed between those writes can never
     * trigger a restart that would orphan the already-created Post.
     */
    public function pipelineIsRestartable(): bool
    {
        $run = $this->pipelineRun;

        if ($run === null) {
            return false;
        }

        if ($run->status === BlogPostPipelineStatus::Failed) {
            return true;
        }

        return $this->post_id === null
            && $run->post_id === null
            && $run->isStale((int) config('blog-pipeline.stale_after_minutes', 30));
    }
}
