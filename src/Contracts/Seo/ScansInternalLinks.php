<?php

declare(strict_types=1);

namespace Core45\AiPosts\Contracts\Seo;

use Core45\AiPosts\DTOs\Seo\InternalLinkScanResult;

interface ScansInternalLinks
{
    /**
     * Scan HTML content and extract all internal and external links.
     */
    public function scanContent(string $htmlContent, ?string $baseUrl = null): InternalLinkScanResult;
}
