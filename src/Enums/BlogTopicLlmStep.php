<?php

declare(strict_types=1);

namespace Core45\AiPosts\Enums;

enum BlogTopicLlmStep: string
{
    case Profile = 'profile';
    case Research = 'research';
    case Synthesis = 'synthesis';
    case Writing = 'writing';
    case CitationGuard = 'citation_guard';
    case Translation = 'translation';
    case ImageAltText = 'image_alt_text';
    case UrlSynthesis = 'url_synthesis';
    case SeriesPlanning = 'series_planning';
    case SeriesReEvaluation = 'series_reevaluation';
    case ExternalLinkSafety = 'external_link_safety';
    case LinkEnrichment = 'link_enrichment';
    case Faq = 'faq';
    case QualityJudge = 'quality_judge';
    case FeedbackAnalysis = 'feedback_analysis';
    case ModuleImprovement = 'module_improvement';

    public function getLabel(): string
    {
        return match ($this) {
            self::Profile => __('Site Profile'),
            self::Research => __('Web Research'),
            self::Synthesis => __('Topic Synthesis'),
            self::Writing => __('Blog Writing'),
            self::CitationGuard => __('Citation Guard'),
            self::Translation => __('Translation'),
            self::ImageAltText => __('Image Alt Text'),
            self::UrlSynthesis => __('URL Synthesis'),
            self::SeriesPlanning => __('Series Planning'),
            self::SeriesReEvaluation => __('Series Re-evaluation'),
            self::ExternalLinkSafety => __('External Link Safety'),
            self::LinkEnrichment => __('Link Enrichment'),
            self::Faq => __('FAQ Generation'),
            self::QualityJudge => __('Quality Judge'),
            self::FeedbackAnalysis => __('Feedback Analysis'),
            self::ModuleImprovement => __('Module Improvement'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Profile => 'gray',
            self::Research => 'info',
            self::Synthesis => 'warning',
            self::Writing => 'success',
            self::CitationGuard => 'danger',
            self::Translation => 'primary',
            self::ImageAltText => 'info',
            self::UrlSynthesis => 'warning',
            self::SeriesPlanning => 'info',
            self::SeriesReEvaluation => 'warning',
            self::ExternalLinkSafety => 'danger',
            self::LinkEnrichment => 'success',
            self::Faq => 'info',
            self::QualityJudge => 'warning',
            self::FeedbackAnalysis => 'info',
            self::ModuleImprovement => 'success',
        };
    }
}
