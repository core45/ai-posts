<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests\Unit;

use Core45\AiPosts\Concerns\ExtractsArticleContent;
use PHPUnit\Framework\TestCase;

class ExtractsArticleContentTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new class
        {
            use ExtractsArticleContent;

            /** @return array<int, string> */
            public function headings(string $html): array
            {
                return $this->extractHeadings($html);
            }

            public function plain(string $html, int $max): string
            {
                return $this->stripAndCap($html, $max);
            }
        };
    }

    public function test_extracts_h2_headings_in_order(): void
    {
        $html = '<h2>First</h2><p>x</p><h2>Second</h2>';
        $this->assertSame(['First', 'Second'], $this->subject->headings($html));
    }

    public function test_strips_tags_and_caps_length(): void
    {
        $html = '<p>'.str_repeat('a', 100).'</p>';
        $plain = $this->subject->plain($html, 20);

        $this->assertSame(20, mb_strlen($plain));
        $this->assertStringNotContainsString('<p>', $plain);
    }
}
