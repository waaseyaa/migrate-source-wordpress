<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;

/**
 * Normalizes a WordPress permalink (the WXR `<link>` element, captured by
 * {@see \Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader} under
 * `_extra['link']` as of G-020) into a destination-side path-alias string.
 *
 * ### Normalization contract
 *
 * 1. Domain is stripped — only the URL path component survives
 *    (`parse_url($permalink, PHP_URL_PATH)`); any query string is dropped
 *    entirely, it never contributes to the alias.
 * 2. The path is percent-decoded (`rawurldecode()`) so non-ASCII slugs
 *    round-trip as readable UTF-8 rather than escape sequences.
 * 3. A trailing slash is stripped (WordPress's default pretty-permalink
 *    trailing slash is not carried over — `/members/rht/` becomes
 *    `/members/rht`); a path with no trailing slash is left as-is.
 * 4. The result always starts with a leading `/`.
 * 5. Returns `null` — "no aliasable path" — for two degenerate cases:
 *    - **Querystring-only ("plain") permalinks**, e.g. `/?p=100` or
 *      `/?project=104`. WordPress's "Plain" permalink structure (or an
 *      unrecognised custom-post-type rewrite) carries no path segment at
 *      all — there is nothing hierarchical to preserve, and aliasing the
 *      bare `/` root to a single post would collide across every such
 *      post.
 *    - **The bare homepage link** (path `''` or `/`) — aliasing the site
 *      root to one specific post/page is a front-page decision, not a
 *      per-record one; leave it to app-side wiring.
 *
 * ### No per-record skip for "alias equals destination's own default slug"
 *
 * The migration platform's process-plugin chain has no primitive for
 * skipping a destination write mid-chain (see
 * {@see \Waaseyaa\Migration\Runner\MigrationRunner} — the only skip paths
 * are dry-run and idempotent hash-match, both runner-level, not
 * process-plugin-level). Rather than bolt on an app-specific "does this
 * equal the destination's auto-slug" heuristic that the plugin cannot
 * actually verify (it has no visibility into the destination's slug
 * algorithm), this plugin always emits the normalized alias for any
 * permalink with a real path. Writing an alias that happens to duplicate
 * the destination's own default slug is harmless — {@see
 * \Waaseyaa\Migration\Plugin\Destination\EntityDestinationInterface}-style
 * idempotency via the id-map means re-running the migration does not
 * duplicate the row, and an extra `path_alias` row that matches the
 * default is inert, not wrong.
 *
 * Accepts either the raw permalink string, or the whole `_extra` array
 * (from a `['_extra', new WordPressPermalinkToAlias()]` process chain) —
 * the latter is the intended usage since `_extra` is where the reader
 * stashes `link`.
 *
 * @api
 *
 * @spec G-020 — path-alias emission from the WordPress permalink
 */
final class WordPressPermalinkToAlias implements ProcessPluginInterface
{
    public const string PLUGIN_ID = 'wordpress_permalink_to_alias';

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
        unset($context);

        $link = match (true) {
            is_string($value) => $value,
            is_array($value) && is_string($value['link'] ?? null) => $value['link'],
            default => null,
        };

        if ($link === null || $link === '') {
            return null;
        }

        return self::normalize($link);
    }

    /**
     * Pure normalization function — exposed as a static so callers can reuse
     * the contract outside a process chain (e.g. to pre-validate a fixture).
     */
    public static function normalize(string $permalink): ?string
    {
        $path = parse_url($permalink, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $decoded = rawurldecode($path);
        $trimmed = rtrim($decoded, '/');

        if ($trimmed === '') {
            return null;
        }

        return str_starts_with($trimmed, '/') ? $trimmed : '/' . $trimmed;
    }
}
