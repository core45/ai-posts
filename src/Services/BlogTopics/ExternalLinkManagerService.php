<?php

declare(strict_types=1);

namespace Core45\AiPosts\Services\BlogTopics;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiPosts\Enums\BlogExternalLinkStatus;
use Core45\AiPosts\Enums\BlogExternalLinkUsageStatus;
use Core45\AiPosts\Enums\BlogLinkPlacedBy;
use Core45\AiPosts\Models\BlogExternalLink;
use Core45\AiPosts\Models\BlogExternalLinkReview;
use Core45\AiPosts\Models\BlogExternalLinkUsage;
use Core45\AiPosts\Models\BlogTopicProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ExternalLinkManagerService
{
    /**
     * Get all available external links for a profile.
     * Returns only Active status links (global + profile-specific).
     * Explicitly excludes blocked AND proposed_by_ai.
     *
     * @return Collection<int, BlogExternalLink>
     */
    public function getAvailableLinks(BlogTopicProfile $profile): Collection
    {
        return BlogExternalLink::query()
            ->where('status', BlogExternalLinkStatus::Active)
            ->where(function ($query) use ($profile): void {
                $query->whereNull('profile_id')
                    ->orWhere('profile_id', $profile->id);
            })
            ->orderByDesc('usage_count')
            ->get();
    }

    /**
     * Suggest a link placement for a post. Does NOT modify HTML.
     * Creates a usage record with status=suggested.
     */
    public function suggestPlacement(
        BlogExternalLink $link,
        Model $post,
        string $anchorText,
        string $contextSnippet,
        BlogLinkPlacedBy $placedBy,
    ): BlogExternalLinkUsage {
        return BlogExternalLinkUsage::create([
            'link_id' => $link->id,
            'post_id' => $post->id, // @phpstan-ignore property.notFound
            'anchor_text' => $anchorText,
            'context_snippet' => $contextSnippet,
            'status' => BlogExternalLinkUsageStatus::Suggested,
            'placed_by' => $placedBy,
            'suggested_at' => now(),
        ]);
    }

    /**
     * Admin accepts a suggested placement: injects <a> tag into post HTML,
     * updates usage status, increments usage_count.
     */
    public function acceptPlacement(BlogExternalLinkUsage $usage): void
    {
        $post = $usage->post;
        $link = $usage->link;
        $locale = app()->getLocale();

        $translation = $post instanceof Translatable ? $post->translate($locale) : null;
        if ($translation !== null && !empty($translation->main_text)) {
            $anchorText = $usage->anchor_text;
            $url = htmlspecialchars($link->url, ENT_QUOTES, 'UTF-8');
            $replacement = '<a href="'.$url.'" target="_blank" rel="noopener">'.$anchorText.'</a>';

            // Find and replace first occurrence of anchor text that isn't already linked
            $html = $translation->main_text;
            $pos = stripos($html, $anchorText);
            if ($pos !== false) {
                $html = substr_replace($html, $replacement, $pos, strlen($anchorText));
                $translation->main_text = $html;
                $post->save();
            }
        }

        $usage->update([
            'status' => BlogExternalLinkUsageStatus::Accepted,
            'accepted_at' => now(),
        ]);

        $link->increment('usage_count');
    }

    /**
     * Admin rejects a suggested placement.
     */
    public function rejectPlacement(BlogExternalLinkUsage $usage): void
    {
        $usage->update([
            'status' => BlogExternalLinkUsageStatus::Rejected,
        ]);
    }

    /**
     * Record a safety review for a link. Auto-blocks if harmful.
     */
    public function reviewLink(
        BlogExternalLink $link,
        bool $isHarmful,
        string $reason,
        string $reviewedBy,
    ): BlogExternalLinkReview {
        $review = BlogExternalLinkReview::create([
            'link_id' => $link->id,
            'is_harmful' => $isHarmful,
            'reason' => $reason,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
        ]);

        if ($isHarmful) {
            $link->update([
                'status' => BlogExternalLinkStatus::Blocked,
                'blocked_reason' => $reason,
                'blocked_at' => now(),
            ]);
        }

        return $review;
    }
}
