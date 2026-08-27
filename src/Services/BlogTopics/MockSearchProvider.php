<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Core45\AiCore\DTOs\SearchResult;
use Core45\AiPosts\Contracts\BlogTopics\SearchResearchProvider;
use Core45\AiPosts\DTOs\BlogTopics\SearchResults;

class MockSearchProvider implements SearchResearchProvider
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function search(string $query, array $options = []): SearchResults
    {
        return new SearchResults(
            query: $query,
            results: [
                new SearchResult(
                    url: 'https://www.electricalengineering.com/articles/industrial-trends-2025',
                    domain: 'electricalengineering.com',
                    title: 'Top Industrial Electrical Trends for 2025',
                    snippet: 'The industrial electrical equipment market is projected to reach $200 billion by 2027, driven by automation and energy efficiency demands.',
                    language: 'en',
                    publishedAt: '2025-01-15',
                    reliabilityScore: 0.85,
                ),
                new SearchResult(
                    url: 'https://www.manufacturing-today.com/circuit-breaker-innovations',
                    domain: 'manufacturing-today.com',
                    title: 'Circuit Breaker Innovations Reshaping Manufacturing',
                    snippet: 'Smart circuit breakers with IoT connectivity are reducing downtime by up to 40% in manufacturing facilities across Europe.',
                    language: 'en',
                    publishedAt: '2025-02-20',
                    reliabilityScore: 0.78,
                ),
                new SearchResult(
                    url: 'https://www.iec-standards.org/updates/iec-60947-revision',
                    domain: 'iec-standards.org',
                    title: 'IEC 60947 Revision: What Manufacturers Need to Know',
                    snippet: 'The latest revision of IEC 60947 introduces new requirements for low-voltage switchgear testing and certification.',
                    language: 'en',
                    publishedAt: '2025-03-01',
                    reliabilityScore: 0.92,
                ),
                new SearchResult(
                    url: 'https://www.energy-efficiency-hub.eu/industrial-automation',
                    domain: 'energy-efficiency-hub.eu',
                    title: 'Energy Efficiency in Industrial Automation',
                    snippet: 'European manufacturers are investing heavily in variable frequency drives and power management systems to meet EU energy targets.',
                    language: 'en',
                    publishedAt: '2024-11-10',
                    reliabilityScore: 0.80,
                ),
            ],
            provider: 'mock',
        );
    }

    public function getProviderName(): string
    {
        return 'mock';
    }
}
