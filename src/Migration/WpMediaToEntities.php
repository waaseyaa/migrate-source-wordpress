<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Migration;

use Waaseyaa\Migrate\Source\WordPress\Process\WordPressEntityRefResolve;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressMediaSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\Process\LookupProcessor;
use Waaseyaa\Migration\Plugin\Process\TypeCoerceProcessor;

/**
 * Default WordPress media → destination media entity migration factory.
 *
 * Yields source records with `file_path`, `original_url`, `mime_type`, etc.
 * Actual file copying is operator-controlled via
 * {@see \Waaseyaa\Migrate\Source\WordPress\Media\MediaCopier}: consumers
 * compose it into their destination plugin and pass `source.media_path`
 * (local fs or HTTP prefix) at the application layer.
 *
 * ## Reference resolution (G-019)
 *
 * Passing `$references` (a
 * {@see \Waaseyaa\Migrate\Source\WordPress\Migration\ReferenceResolutionOptions}
 * with `$resolveParent` set) adds an *additional* `parent_ref` destination
 * field — the attached post's resolved destination reference — alongside
 * the raw `parent_post_id`.
 *
 * **Ordering caveat:** this migration's `$dependencies` (below) place it
 * BEFORE `WpPostsToArticles` — media is unconditionally imported first
 * because posts reference media (not the other way around). `parent_ref`
 * therefore resolves to `null` on the FIRST run (the referenced post does
 * not exist in the id-map yet); it resolves correctly on a SECOND run
 * performed after `WpPostsToArticles` has completed — the resolved value
 * differs from the stored one, so `EntityDestination`'s change-detection
 * (FR-031) updates the row rather than skipping it. Operators who need
 * first-run resolution must run posts before media in a custom pipeline.
 *
 * @api
 *
 * @spec FR-021 — default media migration
 * @spec G-019 — id-map reference resolution
 */
final class WpMediaToEntities
{
    public const string MIGRATION_ID = 'wp_media_to_entities';

    public function __construct(
        private readonly WxrReader $reader,
        private readonly DestinationPluginInterface $destination,
        private readonly ?ReferenceResolutionOptions $references = null,
    ) {
    }

    public function definition(): MigrationDefinition
    {
        $process = [
            'file_path' => 'file_path',
            'original_url' => 'original_url',
            'mime_type' => 'mime_type',
            'alt_text' => 'alt_text',
            'caption' => 'caption',
            'description' => 'description',
            'parent_post_id' => 'parent_post_id',
            'size_bytes' => 'size_bytes',
        ];

        $refs = $this->references;
        if ($refs !== null && $refs->resolveParent) {
            $process['parent_ref'] = [
                'parent_post_id',
                // See the matching comment in WpPostsToArticles::definition()
                // — WordPressMediaSource emits parent_post_id as an int (or
                // null); coerce to string so the hash matches
                // WordPressPostSource::sourceIdFor()'s string-keyed SourceId.
                new TypeCoerceProcessor('string'),
                new LookupProcessor(
                    sourceField: 'parent_post_id',
                    migration: WpPostsToArticles::MIGRATION_ID,
                    sourceType: 'wp_post',
                    keyField: 'id',
                    onMiss: $refs->onMiss,
                ),
                ...($refs->entityRefResolve !== null
                    ? [new WordPressEntityRefResolve($refs->entityRefResolve, $refs->postEntityType, $refs->onMiss)]
                    : []),
            ];
        }

        return new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: new WordPressMediaSource($this->reader, self::MIGRATION_ID),
            process: $process,
            destination: $this->destination,
            dependencies: [WpTermsToTaxonomy::MIGRATION_ID],
            description: 'Imports WordPress attachments. The MediaCopier primitive handles local + HTTP file copy at the destination boundary.',
        );
    }
}
