<?php

declare(strict_types=1);

namespace Core45\AiPosts\Exceptions;

use Core45\AiPosts\Services\BlogPipeline\ModulePayloadGuard;
use RuntimeException;

/**
 * A module payload was refused BEFORE it could be billed (plan 48-24, F4/F5).
 *
 * Raised by {@see ModulePayloadGuard} for the two
 * conditions that would otherwise reach the provider: a payload missing one of
 * the transition window's two complete placeholder shapes, and a payload over the
 * size intersection the dual write narrowed.
 *
 * DELIBERATELY NOT AN `AiClientException`. Nothing remote failed — this host
 * refused to make the call, and conflating the two would let a payload defect be
 * read as an AI-service outage in every log and alert that classifies by that
 * hierarchy. Retrying cannot help either: the payload is wrong, not unlucky.
 */
class UnbillableModulePayloadException extends RuntimeException {}
