<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Migration;

use Waaseyaa\Migrate\Source\WordPress\Process\WordPressPostmetaExtract;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;

/**
 * The Events Calendar `tribe_venue` → destination nodes migration factory.
 *
 * Venue address details live as postmeta on the `tribe_venue` post type;
 * this factory narrows {@see WordPressPostSource} to that post type via
 * {@see WordPressPostSource}'s `postTypes` filter and extracts the address fields with
 * {@see WordPressPostmetaExtract} (G-016).
 *
 * A constructor-injected `$source` lets consumers supply a differently
 * composed source without changing this factory.
 *
 * @api
 *
 * @spec G-016 — postmeta passthrough + Events Calendar recipe
 */
final class WpVenuesToNodes
{
    public const string MIGRATION_ID = 'wp_venues_to_nodes';
    public const string POST_TYPE = 'tribe_venue';

    public function __construct(
        private readonly WxrReader $reader,
        private readonly DestinationPluginInterface $destination,
        private readonly ?SourcePluginInterface $source = null,
    ) {
    }

    public function definition(): MigrationDefinition
    {
        return new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: $this->source ?? new WordPressPostSource($this->reader, self::MIGRATION_ID, postTypes: [self::POST_TYPE]),
            process: [
                'title' => 'title',
                'slug' => 'slug',
                'status' => 'status',
                'address' => ['_extra', new WordPressPostmetaExtract('_VenueAddress')],
                'city' => ['_extra', new WordPressPostmetaExtract('_VenueCity')],
                'province' => ['_extra', new WordPressPostmetaExtract('_VenueProvince')],
                'country' => ['_extra', new WordPressPostmetaExtract('_VenueCountry')],
            ],
            destination: $this->destination,
            dependencies: [],
            description: 'The Events Calendar venues (tribe_venue) → destination nodes, with address fields extracted from postmeta (G-016).',
        );
    }
}
