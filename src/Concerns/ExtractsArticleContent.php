<?php

declare(strict_types=1);

namespace Core45\AiPosts\Concerns;

trait ExtractsArticleContent
{
    /**
     * Ordered list of H2 headings (inner text), used to map in-text images to sections.
     *
     * @return array<int, string>
     */
    protected function extractHeadings(string $html): array
    {
        if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $html, $matches) === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $h): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($h))),
            $matches[1],
        ), static fn (string $h): bool => $h !== ''));
    }

    /**
     * Strip HTML to plain text and cap to $max characters (LLM input budget).
     */
    protected function stripAndCap(string $html, int $max = 6000): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));

        return mb_substr($text, 0, $max);
    }

    /**
     * Insert <figure><img data-id> tags into article HTML. Each image is placed
     * after its matched non-empty <h2> (1-based `section_index`), falling back to
     * even spacing across anchors when the index is absent or out of range.
     * Multiple images resolving to the same anchor accumulate (never overwrite).
     *
     * @param  array<int, array{uuid: string, alt: string, section_index?: int|null}>  $images
     */
    protected function embedFiguresIntoHtml(string $html, array $images): string
    {
        if ($images === []) {
            return $html;
        }

        // Non-empty <h2> anchors, aligned with extractHeadings() numbering.
        // Anchor = start of the closing </h2> so strpos($html,'>',$offset) lands on its '>'.
        $h2Positions = [];
        if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $html, $h2m, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($h2m[0] as $i => $fullMatch) {
                $innerText = trim((string) preg_replace('/\s+/', ' ', strip_tags($h2m[1][$i][0])));
                if ($innerText !== '') {
                    $h2Positions[] = $fullMatch[1] + strlen($fullMatch[0]) - 5;
                }
            }
        }

        preg_match_all('/<\/p>/i', $html, $p, PREG_OFFSET_CAPTURE);
        $pPositions = array_column($p[0], 1);

        $anchorPositions = $h2Positions !== [] ? $h2Positions : $pPositions;
        if ($anchorPositions === []) {
            return $html;
        }

        $headingCount = count($h2Positions);
        $imageCount = count($images);
        $evenPool = count($anchorPositions) > 1 ? array_slice($anchorPositions, 1) : $anchorPositions;
        $step = max(1, (int) floor(count($evenPool) / ($imageCount + 1)));

        $insertions = [];
        for ($i = 0; $i < $imageCount; $i++) {
            $image = $images[$i];
            $sectionIndex = $image['section_index'] ?? null;

            if ($sectionIndex !== null && $h2Positions !== [] && $sectionIndex >= 1 && $sectionIndex <= $headingCount) {
                $offset = $h2Positions[$sectionIndex - 1];
            } else {
                $posIndex = min(($i + 1) * $step - 1, count($evenPool) - 1);
                $offset = $evenPool[$posIndex];
            }

            $tagEnd = strpos($html, '>', (int) $offset);
            if ($tagEnd === false) {
                continue;
            }

            $altEscaped = htmlspecialchars($image['alt'], ENT_QUOTES, 'UTF-8');
            $figure = "\n<figure><img data-id=\"{$image['uuid']}\" alt=\"{$altEscaped}\"></figure>\n";
            $insertions[$tagEnd + 1] = ($insertions[$tagEnd + 1] ?? '').$figure;
        }

        krsort($insertions);
        foreach ($insertions as $offset => $imgTag) {
            $html = substr_replace($html, $imgTag, (int) $offset, 0);
        }

        return $html;
    }
}
