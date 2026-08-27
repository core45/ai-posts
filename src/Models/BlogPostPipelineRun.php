<?php

declare(strict_types=1);

namespace Core45\AiPosts\Models;

use Core45\AiCore\ModelRegistry;
use Core45\AiPosts\Database\Factories\BlogPostPipelineRunFactory;
use Core45\AiPosts\Enums\BlogPostPipelineStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property BlogPostPipelineStatus $status
 * @property int|null $post_id
 * @property string|null $current_step
 * @property array<mixed>|null $context_json
 * @property array<mixed>|null $module_outputs
 * @property array<mixed>|null $research_data
 * @property array<mixed>|null $quality_log
 * @property array<mixed>|null $config_snapshot
 * @property Carbon|null $started_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property float $total_cost
 */
class BlogPostPipelineRun extends Model
{
    /**
     * Laravel maps a model to a factory by swapping its namespace for the
     * host's factory namespace.
     * A package namespace produces nonsense, so the factory is named
     * here; without this ::factory() fatals.
     */
    protected static function newFactory(): BlogPostPipelineRunFactory
    {
        return BlogPostPipelineRunFactory::new();
    }

    /** @use HasFactory<BlogPostPipelineRunFactory> */
    use HasFactory;

    protected $fillable = [
        'candidate_id',
        'profile_id',
        'post_id',
        'status',
        'current_step',
        'primary_locale',
        'context_json',
        'module_outputs',
        'research_data',
        'quality_log',
        'config_snapshot',
        'total_cost',
        'started_at',
        'paused_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BlogPostPipelineStatus::class,
            'context_json' => 'array',
            'module_outputs' => 'array',
            'research_data' => 'array',
            'quality_log' => 'array',
            'config_snapshot' => 'array',
            'total_cost' => 'decimal:4',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BlogTopicCandidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(BlogTopicCandidate::class, 'candidate_id');
    }

    /**
     * @return BelongsTo<BlogTopicProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(BlogTopicProfile::class, 'profile_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(app(ModelRegistry::class)->getModelClassOrFail('post'), 'post_id');
    }

    /**
     * Atomically merge a research sub-key into the research_data JSON column.
     *
     * Uses JSON_SET to avoid race conditions when parallel research jobs
     * write different keys concurrently.
     *
     * @param  array<string, mixed>  $data
     */
    public function storeResearchData(string $key, array $data): void
    {
        $driver = DB::connection()->getDriverName();
        $jsonValue = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $pdo = DB::connection()->getPdo();
        $quotedPath = $pdo->quote('$.'.$key);
        $quotedValue = $pdo->quote($jsonValue);

        $base = "CASE WHEN research_data IS NULL OR research_data = '[]' OR research_data = '' THEN '{}' ELSE research_data END";

        $expression = match ($driver) {
            'mysql', 'mariadb' => "JSON_SET({$base}, {$quotedPath}, CAST({$quotedValue} AS JSON))",
            default => "json_set({$base}, {$quotedPath}, json({$quotedValue}))",
        };

        DB::table('blog_post_pipeline_runs')
            ->where('id', $this->id)
            ->update([
                // $expression is built from literal per-driver SQL templates with
                // PDO-quoted path/value, so it is injection-safe despite not being
                // a compile-time literal string.
                // @phpstan-ignore argument.type
                'research_data' => DB::raw($expression),
                // This query-builder write bypasses Eloquent timestamps; set
                // updated_at explicitly so storing research counts as a
                // heartbeat and a working run is not flagged as stale.
                'updated_at' => now(),
            ]);

        $this->refresh();
    }

    /**
     * Whether this run appears to have silently stopped: non-terminal, not
     * legitimately waiting on a human (Paused), and untouched past the
     * threshold. Only meaningful in the pre-artifact phases — once the run has
     * produced a Post, its updated_at freezes while downstream jobs work on the
     * Post, so callers must additionally gate on the artifact not existing.
     */
    public function isStale(int $minutes): bool
    {
        if ($this->status->isTerminal() || $this->status === BlogPostPipelineStatus::Paused) {
            return false;
        }

        return $this->updated_at === null
            || $this->updated_at->lt(now()->subMinutes($minutes));
    }

    /**
     * Merge a module's output into the module_outputs JSON column.
     *
     * @param  array<string, mixed>  $output
     */
    public function storeModuleOutput(string $moduleSlug, array $output): void
    {
        $outputs = $this->module_outputs ?? [];
        $outputs[$moduleSlug] = $output;
        $this->module_outputs = $outputs;
        $this->save();
    }

    /**
     * Append an entry to the quality_log JSON array.
     *
     * @param  array<string, mixed>  $entry
     */
    public function appendQualityLog(array $entry): void
    {
        $log = $this->quality_log ?? [];
        $log[] = $entry;
        $this->quality_log = $log;
        $this->save();
    }

    /**
     * Increment total_cost by the given amount.
     */
    public function addCost(float $cost): void
    {
        $this->total_cost = (float) $this->total_cost + $cost;
        $this->save();
    }

    /**
     * Set a value in the context_json column using dot notation.
     */
    public function setContextValue(string $key, mixed $value): void
    {
        $context = $this->context_json ?? [];
        data_set($context, $key, $value);
        $this->context_json = $context;
        $this->save();
    }

    /**
     * Return [slug => summary] from each module output.
     *
     * @return array<string, string|null>
     */
    public function getModuleSummaries(): array
    {
        $outputs = $this->module_outputs ?? [];
        $summaries = [];

        foreach ($outputs as $slug => $output) {
            $summaries[$slug] = $output['summary'] ?? null;
        }

        return $summaries;
    }

    /**
     * Return the content from the last module output, or null if empty.
     */
    public function getLastModuleContent(): ?string
    {
        $outputs = $this->module_outputs ?? [];

        if ($outputs === []) {
            return null;
        }

        $lastOutput = end($outputs);

        return $lastOutput['content'] ?? null;
    }

    /**
     * Return [slug => content] for the N most recent module outputs.
     *
     * @return array<string, string>
     */
    public function getRecentModuleContents(int $count = 3): array
    {
        $outputs = $this->module_outputs ?? [];

        if ($outputs === []) {
            return [];
        }

        $recent = array_slice($outputs, -$count, $count, true);
        $result = [];

        foreach ($recent as $slug => $output) {
            $content = $output['content'] ?? '';
            if ($content !== '') {
                $result[$slug] = $content;
            }
        }

        return $result;
    }
}
