<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Migration;

use Waaseyaa\Migrate\Source\WordPress\Process\UuidToSystemPathProcessor;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressPermalinkToAlias;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\Process\DefaultValueProcessor;
use Waaseyaa\Migration\Plugin\Process\LookupProcessor;
use Waaseyaa\Migration\Plugin\Process\TypeCoerceProcessor;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;

/**
 * Default WordPress posts → `path_alias` migration factory (G-020).
 *
 * Targets `waaseyaa/path`'s `path_alias` entity type — see
 * `docs/migrating-from-wordpress.md` "URL preservation" for the full wiring
 * recipe. Emits one `path_alias` record per WordPress post/page whose
 * permalink (`<link>`, captured by {@see WxrReader} under `_extra['link']`
 * as of G-020) resolves to a real hierarchical path.
 *
 * ### Fields
 *
 * - `alias` — the normalized WordPress permalink path (e.g.
 *   `/members/rht`), via {@see WordPressPermalinkToAlias}. `null` for
 *   querystring-only ("plain" permalink) or homepage links — see that
 *   class's docblock for the exact contract. A `null` alias still produces
 *   a `path_alias` write with `alias: null`; whether your `path_alias`
 *   destination accepts that (skip vs. reject) is destination-side policy,
 *   since this package's process-plugin chain has no primitive for
 *   skipping a destination write mid-record (see
 *   {@see \Waaseyaa\Migration\Runner\ProcessChainExecutor}).
 * - `path` — the destination system path (e.g. `/node/42`), built by
 *   chaining: read `id` → coerce to `string` (matching
 *   {@see WordPressPostSource::sourceIdFor()}'s explicit string cast, so
 *   the {@see LookupProcessor}'s `SourceId` hash actually matches the row
 *   {@see WordPressPostSource} wrote — see `SourceId`'s "Type stability"
 *   docblock) → {@see LookupProcessor} against the posts migration's
 *   id-map (this yields the destination *uuid*, not an id) →
 *   {@see UuidToSystemPathProcessor} to bridge that uuid to the
 *   destination entity's serial/system id via the operator-supplied
 *   `$uuidToId` closure, prefixed by `$systemPathPrefix`.
 * - `langcode` — constant `$langcode` (default `'en'`).
 * - `status` — always `true` (published).
 *
 * ### Run order
 *
 * This migration **must run after** the posts migration
 * (`$postsMigrationId`, defaulting to {@see WpPostsToArticles::MIGRATION_ID})
 * — the `path` field's lookup depends on that migration's id-map rows
 * already existing. Declared via `dependencies`.
 *
 * ### Source seam
 *
 * A constructor-injected `$source` lets consumers supply a differently
 * composed source (e.g. `WordPressPostSource` filtered by `postTypes`)
 * without changing this factory — mirroring {@see WpEventsToNodes}'s seam.
 * Left unset, this defaults to an unfiltered `WordPressPostSource`, which
 * emits an alias attempt for every WordPress post type the posts migration
 * itself may not have covered (`nav_menu_item`, `custom_css`,
 * `wp_global_styles`, `wp_navigation`, and any other custom post type). This
 * migration's coverage must match whatever the posts migration actually
 * wrote — pass the SAME filtered source (or the same `postTypes` allowlist)
 * you used for your posts migration.
 *
 * @api
 *
 * @spec G-020 — path-alias emission from WordPress permalinks
 */
final class WpPostsToPathAliases
{
    public const string MIGRATION_ID = 'wp_posts_to_path_aliases';

    /**
     * @param \Closure(string $entityType, string $uuid): (int|string|null) $uuidToId Resolves a destination
     *     entity's uuid (as returned by the posts migration's id-map) to its serial/system id.
     *     See {@see UuidToSystemPathProcessor}.
     * @param string $postsMigrationId Id of the sibling posts migration whose id-map is consulted for `path`.
     * @param string $destinationEntityType Destination entity type passed to `$uuidToId` (e.g. `node`, `article`).
     * @param string $systemPathPrefix System-path prefix the resolved id is appended to (e.g. `/node/`).
     * @param string $langcode ISO 639-1 language code stamped on every emitted alias.
     * @param ?SourcePluginInterface $source Overrides the default source. This migration's alias
     *     coverage must match the posts migration's coverage exactly — the `path` field's
     *     {@see LookupProcessor} only finds an id-map row for records the posts migration actually
     *     wrote. Pass the SAME filtered source (or the same `postTypes` allowlist) you used to build
     *     your posts migration; otherwise this defaults to an unfiltered {@see WordPressPostSource}
     *     covering every WordPress post type, including non-content records like `nav_menu_item`,
     *     `custom_css`, `wp_global_styles`, and `wp_navigation` — see
     *     `docs/migrating-from-wordpress.md` "URL preservation".
     */
    public function __construct(
        private readonly WxrReader $reader,
        private readonly DestinationPluginInterface $destination,
        private readonly \Closure $uuidToId,
        private readonly string $postsMigrationId = WpPostsToArticles::MIGRATION_ID,
        private readonly string $destinationEntityType = 'node',
        private readonly string $systemPathPrefix = '/node/',
        private readonly string $langcode = 'en',
        private readonly ?SourcePluginInterface $source = null,
    ) {
    }

    public function definition(): MigrationDefinition
    {
        return new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: $this->source ?? new WordPressPostSource($this->reader, self::MIGRATION_ID),
            process: [
                'alias' => ['_extra', new WordPressPermalinkToAlias()],
                'path' => [
                    'id',
                    new TypeCoerceProcessor('string'),
                    new LookupProcessor(
                        sourceField: 'id',
                        migration: $this->postsMigrationId,
                        sourceType: WordPressPostSource::SOURCE_TYPE,
                        keyField: 'id',
                    ),
                    new UuidToSystemPathProcessor(
                        $this->uuidToId,
                        $this->destinationEntityType,
                        $this->systemPathPrefix,
                    ),
                ],
                'langcode' => new DefaultValueProcessor($this->langcode),
                'status' => new DefaultValueProcessor(true),
            ],
            destination: $this->destination,
            dependencies: [$this->postsMigrationId],
            description: 'Example WordPress posts → path_alias migration (G-020). '
                . 'Run after the posts migration; wire $uuidToId to your destination\'s uuid→id lookup — see docs/migrating-from-wordpress.md "URL preservation".',
        );
    }
}
