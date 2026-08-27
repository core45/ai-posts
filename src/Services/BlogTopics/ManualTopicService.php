<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiPosts\Enums\BlogTopicBatchStatus;
use Core45\AiPosts\Enums\BlogTopicCandidateStatus;
use Core45\AiPosts\Models\BlogTopicBatch;
use Core45\AiPosts\Models\BlogTopicCandidate;
use Core45\AiPosts\Models\BlogTopicProfile;
use Core45\AiPosts\Models\BlogTopicSource;

class ManualTopicService
{
    /**
     * Create a manual topic candidate with sources.
     *
     * @param  array<array{url: string, title?: string, snippet: string}>  $sources
     * @param  array<string, mixed>  $outline
     */
    public function createManualTopic(
        BlogTopicProfile $profile,
        string $title,
        string $intent,
        string $rationale,
        array $sources,
        array $outline = [],
        string $batchType = 'manual',
    ): BlogTopicCandidate {
        $batch = BlogTopicBatch::create([
            'profile_id' => $profile->id,
            'status' => BlogTopicBatchStatus::Manual,
            'config_json' => ['type' => $batchType],
        ]);

        $sourceIds = [];
        foreach ($sources as $sourceData) {
            $url = $sourceData['url'];
            $source = BlogTopicSource::create([
                'batch_id' => $batch->id,
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'domain' => parse_url($url, PHP_URL_HOST) ?: 'unknown',
                'title' => $sourceData['title'] ?? null,
                'snippet' => $sourceData['snippet'],
            ]);
            $sourceIds[] = $source->id;
        }

        $evidence = array_map(fn (int $id, int $idx) => [
            'source_id' => $id,
            'evidence_summary' => $sources[$idx]['snippet'],
            'relevance' => 'manual',
        ], $sourceIds, array_keys($sourceIds));

        return BlogTopicCandidate::create([
            'batch_id' => $batch->id,
            'title' => $title,
            'slug' => app(GeneratesSlugs::class)->make($title),
            'intent' => $intent,
            'rationale' => $rationale,
            'outline_json' => $outline ?: null,
            'evidence_json' => $evidence,
            'rank' => 1,
            'status' => BlogTopicCandidateStatus::Proposed,
        ]);
    }
}
