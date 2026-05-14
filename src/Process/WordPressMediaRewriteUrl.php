<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;

/**
 * Process plugin that rewrites `wp-content/uploads/<path>` URLs in post
 * content to their destination equivalents.
 *
 * The plugin scans any string value for `wp-content/uploads/<path>` URLs
 * matching the canonical WP host or one of the operator-supplied
 * {@see $cdnHosts}; the relative path (everything from `/wp-content/...`
 * onward) is then handed to the injected resolver closure. A resolver
 * return of null is treated as "no destination mapping yet" — the URL is
 * left untouched and a warning is logged (FR-046 per-record behavior).
 *
 * The resolver is the operator's plug into the migration id-map: typical
 * implementations call M-002's {@see \Waaseyaa\Migration\Plugin\Process\LookupProcessor}
 * with the WordPressMediaSource id derived from the relative path, then
 * resolve the destination uuid through {@see ProcessContext::$lookup}. The
 * mapping from path → wp_attachment_id is operator-specific (typically a
 * pre-built path cache or DB lookup), so the plugin exposes the seam as a
 * closure rather than wiring a particular strategy.
 *
 * Host matching is case-insensitive; relative-URL references (e.g.
 * `<img src="/wp-content/uploads/...">`) are also rewritten.
 *
 * @api
 *
 * @spec FR-013 — rewrite media URLs
 * @spec FR-017 — `wordpress_*` plugin id naming
 * @spec FR-034 FR-035 — CDN host allowlist
 */
final class WordPressMediaRewriteUrl implements ProcessPluginInterface
{
    public const string PLUGIN_ID = 'wordpress_media_rewrite_url';

    /**
     * @param \Closure(string $relativePath): ?string $urlResolver Resolves a
     *     `wp-content/uploads/...` relative path to its destination URL.
     *     Return null to indicate "no mapping" — the URL is left untouched
     *     and a per-record warning is logged.
     * @param list<string> $cdnHosts Lowercase host names treated equivalently
     *     to the canonical WP host (e.g. `['cdn.example.com', 'media.example.com']`).
     *     Empty list = rewrite any wp-content/uploads/ URL regardless of host.
     */
    public function __construct(
        private readonly \Closure $urlResolver,
        private readonly array $cdnHosts = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function id(): string
    {
        return self::PLUGIN_ID;
    }

    public function stability(): string
    {
        return 'stable';
    }

    public function transform(mixed $value, ProcessContext $context): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $pattern = '#(?:https?:)?//([^/\s"\'<>]+)(/wp-content/uploads/[^\s"\'<>]+)#i';
        $migrationId = $context->migrationId;

        $result = preg_replace_callback(
            $pattern,
            function (array $match) use ($migrationId): string {
                if ($this->cdnHosts !== [] && !in_array(strtolower($match[1]), $this->cdnHosts, true)) {
                    return $match[0];
                }
                return $this->resolveOrLog($match[0], $match[2], $migrationId);
            },
            $value,
        );

        if (!is_string($result)) {
            return $value;
        }

        // Also handle host-less references like `/wp-content/uploads/...`.
        $bareResult = preg_replace_callback(
            '#(?<![A-Za-z0-9/:])(/wp-content/uploads/[^\s"\'<>]+)#',
            fn (array $match) => $this->resolveOrLog($match[1], $match[1], $migrationId),
            $result,
        );

        return is_string($bareResult) ? $bareResult : $result;
    }

    private function resolveOrLog(string $original, string $relativePath, string $migrationId): string
    {
        $resolved = ($this->urlResolver)($relativePath);
        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }

        $this->logger->warning('No destination mapping for media URL; leaving untouched.', [
            'url' => $original,
            'relative_path' => $relativePath,
            'migration_id' => $migrationId,
        ]);
        return $original;
    }
}
