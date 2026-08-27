<?php

declare(strict_types=1);

namespace Core45\AiPosts\DTOs\Seo;

final readonly class InternalLinkScanResult
{
    /**
     * @param  array<int, array{url: string, anchorText: string, isNofollow: bool}>  $internalLinks
     * @param  array<int, array{url: string, anchorText: string, isNofollow: bool}>  $externalLinks
     */
    public function __construct(
        public array $internalLinks = [],
        public array $externalLinks = [],
        public int $internalLinkCount = 0,
        public int $externalLinkCount = 0,
    ) {}
}
