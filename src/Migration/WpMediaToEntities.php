<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Migration;

use Waaseyaa\Migrate\Source\WordPress\Source\WordPressMediaSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;

/**
 * Default WordPress media → destination media entity migration factory.
 *
 * Yields source records with `file_path`, `original_url`, `mime_type`, etc.
 * Actual file copying is operator-controlled via
 * {@see \Waaseyaa\Migrate\Source\WordPress\Media\MediaCopier}: consumers
 * compose it into their destination plugin and pass `source.media_path`
 * (local fs or HTTP prefix) at the application layer.
 *
 * @api
 *
 * @spec FR-021 — default media migration
 */
final class WpMediaToEntities
{
    public const string MIGRATION_ID = 'wp_media_to_entities';

    public function __construct(
        private readonly WxrReader $reader,
        private readonly DestinationPluginInterface $destination,
    ) {
    }

    public function definition(): MigrationDefinition
    {
        return new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: new WordPressMediaSource($this->reader, self::MIGRATION_ID),
            process: [
                'file_path' => 'file_path',
                'original_url' => 'original_url',
                'mime_type' => 'mime_type',
                'alt_text' => 'alt_text',
                'caption' => 'caption',
                'description' => 'description',
                'parent_post_id' => 'parent_post_id',
                'size_bytes' => 'size_bytes',
            ],
            destination: $this->destination,
            dependencies: [WpTermsToTaxonomy::MIGRATION_ID],
            description: 'Imports WordPress attachments. The MediaCopier primitive handles local + HTTP file copy at the destination boundary.',
        );
    }
}
