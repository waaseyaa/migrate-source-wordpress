<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Migration;

use Waaseyaa\Migrate\Source\WordPress\Process\WordPressBuilderContentDecode;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressAuthorIdResolve;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressEntityRefResolve;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressMediaRewriteUrl;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressOembedExpand;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressShortcodeStrip;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressTermsResolve;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\Process\LookupProcessor;
use Waaseyaa\Migration\Plugin\Process\TypeCoerceProcessor;

/**
 * Default WordPress posts → destination article migration factory.
 *
 * Named "to_articles" intentionally per FR-022 — this is an EXAMPLE the
 * package ships as a starting point. Consumers running a different
 * destination shape (blog post, teaching, news item) clone this class and
 * adjust the migration id + process map; the rename path is documented in
 * the package README.
 *
 * The `content` field is processed through the standard WordPress content
 * chain: decode page-builder content (Elementor `_elementor_data` → semantic
 * HTML, plus Gutenberg block-comment stripping — G-013/G-029), strip
 * shortcodes, expand oEmbed-capable URLs (no-op by default), rewrite media
 * URLs. The builder-decode step runs first and is not constructor-injected
 * (it has no operator-tunable state); the remaining stages are still
 * constructor-configurable. Operators tune behavior by passing different
 * process plugin instances into the constructor.
 *
 * ## Reference resolution (G-019)
 *
 * By default `author_login`, `parent_id`, and `terms` are emitted verbatim
 * (raw WordPress values) — exactly as before this option existed. Passing
 * `$references` (a {@see ReferenceResolutionOptions}) adds three *additional*
 * destination fields, none of which replace the raw ones:
 *
 * - `uid` — the author's resolved destination reference (requires `$references->loginToId`).
 * - `parent_ref` — the parent post/page's resolved destination reference (requires `$references->resolveParent`).
 * - `term_refs` — a `list<int|string>` of resolved term references (requires `$references->slugToTermId`).
 *
 * See `docs/customization.md` "Resolving references through the id-map" for
 * a complete wiring example.
 *
 * @api
 *
 * @spec FR-022 — default posts migration (named as example)
 * @spec FR-023 — content processing chain
 * @spec G-013 — decode Elementor `_elementor_data` into semantic HTML
 * @spec G-029 — strip Gutenberg block-delimiter comments
 * @spec G-019 — id-map reference resolution
 */
final class WpPostsToArticles
{
    public const string MIGRATION_ID = 'wp_posts_to_articles';

    public function __construct(
        private readonly WxrReader $reader,
        private readonly DestinationPluginInterface $destination,
        private readonly WordPressShortcodeStrip $shortcodeStrip = new WordPressShortcodeStrip(),
        private readonly WordPressOembedExpand $oembedExpand = new WordPressOembedExpand(),
        private readonly ?WordPressMediaRewriteUrl $mediaRewrite = null,
        private readonly ?ReferenceResolutionOptions $references = null,
    ) {
    }

    public function definition(): MigrationDefinition
    {
        $contentChain = [
            'content',
            new WordPressBuilderContentDecode(),
            $this->shortcodeStrip,
            $this->oembedExpand,
        ];
        if ($this->mediaRewrite !== null) {
            $contentChain[] = $this->mediaRewrite;
        }

        $process = [
            'title' => 'title',
            'slug' => 'slug',
            'content' => $contentChain,
            'excerpt' => 'excerpt',
            'post_type' => 'post_type',
            'status' => 'status',
            'published_at' => 'published_at',
            'modified_at' => 'modified_at',
            'author_login' => 'author_login',
            'parent_id' => 'parent_id',
            'terms' => 'terms',
            'password' => 'password',
        ];

        $refs = $this->references;
        if ($refs !== null && $refs->loginToId !== null) {
            $process['uid'] = [
                'author_login',
                new WordPressAuthorIdResolve(loginToId: $refs->loginToId, onMiss: $refs->onMiss),
                new LookupProcessor(
                    sourceField: 'author_login',
                    migration: WpUsersToAccounts::MIGRATION_ID,
                    sourceType: 'wp_user',
                    keyField: 'id',
                    onMiss: $refs->onMiss,
                ),
                ...($refs->entityRefResolve !== null
                    ? [new WordPressEntityRefResolve($refs->entityRefResolve, $refs->authorEntityType, $refs->onMiss)]
                    : []),
            ];
        }

        if ($refs !== null && $refs->resolveParent) {
            $process['parent_ref'] = [
                'parent_id',
                // WordPressPostSource emits parent_id as an int (or null);
                // LookupProcessor does not normalize types and
                // WordPressPostSource::sourceIdFor() keys its SourceId with
                // a string id — coerce first so the hash matches. `null`
                // passes through TypeCoerceProcessor unchanged (top-level
                // posts correctly resolve to null, not a miss).
                new TypeCoerceProcessor('string'),
                new LookupProcessor(
                    sourceField: 'parent_id',
                    migration: self::MIGRATION_ID,
                    sourceType: 'wp_post',
                    keyField: 'id',
                    onMiss: $refs->onMiss,
                ),
                ...($refs->entityRefResolve !== null
                    ? [new WordPressEntityRefResolve($refs->entityRefResolve, $refs->postEntityType, $refs->onMiss)]
                    : []),
            ];
        }

        if ($refs !== null && $refs->slugToTermId !== null) {
            $process['term_refs'] = [
                'terms',
                new WordPressTermsResolve(
                    slugToTermId: $refs->slugToTermId,
                    refResolve: $refs->entityRefResolve,
                    destinationEntityType: $refs->termEntityType,
                    onMiss: $refs->onMiss,
                ),
            ];
        }

        return new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: new WordPressPostSource($this->reader, self::MIGRATION_ID),
            process: $process,
            destination: $this->destination,
            dependencies: [
                WpUsersToAccounts::MIGRATION_ID,
                WpTermsToTaxonomy::MIGRATION_ID,
                WpMediaToEntities::MIGRATION_ID,
            ],
            description: 'Example WordPress posts → articles migration. Rename to match your destination entity (FR-022).',
        );
    }
}
