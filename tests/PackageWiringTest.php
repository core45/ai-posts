<?php

declare(strict_types=1);

namespace Core45\AiPosts\Tests;

use Core45\AiPosts\AiPostsServiceProvider;
use Core45\AiPosts\Jobs\GeneratePostPhotosJob;
use Core45\AiPosts\Jobs\TranslatePostToLocale;
use Core45\AiPosts\Services\BlogPipeline\PipelineOrchestratorService;
use Core45\AiPosts\Services\BlogTopics\ResearchService;
use Core45\AiPosts\Services\PostContentRewriteService;
use PHPUnit\Framework\Attributes\Test;

class PackageWiringTest extends TestCase
{
    /**
     * ai-posts imports no host classes at all. It reaches the consuming app's
     * blog domain through `ModelRegistry` and the capability contracts in
     * `src/Contracts/`, which is what makes it installable in any host.
     *
     * This was a ratchet pinned at 72 while the coupling was being worked down.
     * The count reached zero in the drop-in refactor, at which point a ceiling
     * of 72 stopped being a ratchet and became a vacuous assertion: it passed
     * no matter what, and would have kept passing if all 72 imports came back.
     * A ratchet satisfied at zero is not a weaker gate, it is no gate.
     *
     * It is now an exact assertion. If a host import is genuinely needed, the
     * answer is a contract, not a raised ceiling.
     */
    private const MAX_HOST_IMPORTS = 0;

    #[Test]
    public function the_package_boots_standalone(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(AiPostsServiceProvider::class));
    }

    #[Test]
    public function every_shipped_entry_point_exists(): void
    {
        foreach ([
            PostContentRewriteService::class,
            PipelineOrchestratorService::class,
            ResearchService::class,
            TranslatePostToLocale::class,
            GeneratePostPhotosJob::class,
        ] as $class) {
            $this->assertTrue(class_exists($class), "{$class} is missing.");
        }
    }

    #[Test]
    public function host_coupling_does_not_grow(): void
    {
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../src')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/^use\s+(App\\\\[^\s;]+);/m', (string) file_get_contents($file->getPathname()), $matches);
            $found = array_merge($found, $matches[1]);
        }

        $distinct = count(array_unique($found));

        $this->assertLessThanOrEqual(
            self::MAX_HOST_IMPORTS,
            $distinct,
            "ai-posts imports {$distinct} host class(es); it must import none. "
            .'Route the dependency through ModelRegistry or a contract in src/Contracts/ '
            .'instead. Do not raise this ceiling: a package that imports App\\ classes '
            .'cannot be installed in any other application, which is the whole point of '
            .'extracting it.'
        );
    }
}
