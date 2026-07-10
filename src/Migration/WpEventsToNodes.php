<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Migration;

use Waaseyaa\Migrate\Source\WordPress\Process\WordPressPostmetaExtract;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressShortcodeStrip;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;

/**
 * The Events Calendar `tribe_events` → destination nodes migration factory.
 *
 * WordPress's Events Calendar plugin stores event scheduling as postmeta
 * (`_EventStartDate`, `_EventEndDate`) rather than typed columns, and
 * {@see WordPressPostSource} yields every non-attachment post type without
 * discriminating by default — so this factory narrows {@see WordPressPostSource}
 * to `tribe_events` via its `postTypes` filter (G-027) and pulls
 * the two date fields out of the passthrough `_extra` postmeta map via
 * {@see WordPressPostmetaExtract} (G-016).
 *
 * `_EventOrganizerID`/`_EventVenueID` are WordPress post ids referencing
 * {@see WpOrganizersToNodes}/{@see WpVenuesToNodes} records; resolving them
 * into destination-side relationships is left to the consumer's process
 * chain (via the runner's cross-migration `lookup` closure) since it depends
 * on how the destination models organizer/venue relationships.
 *
 * A constructor-injected `$source` lets consumers supply a differently
 * composed source without changing this factory.
 *
 * @api
 *
 * @spec G-016 — postmeta passthrough + Events Calendar recipe
 */
final class WpEventsToNodes
{
    public const string MIGRATION_ID = 'wp_events_to_nodes';
    public const string POST_TYPE = 'tribe_events';

    public function __construct(
        private readonly WxrReader $reader,
        private readonly DestinationPluginInterface $destination,
        private readonly ?SourcePluginInterface $source = null,
        private readonly WordPressShortcodeStrip $shortcodeStrip = new WordPressShortcodeStrip(),
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
                'content' => ['content', $this->shortcodeStrip],
                'excerpt' => 'excerpt',
                'status' => 'status',
                'published_at' => 'published_at',
                'modified_at' => 'modified_at',
                'event_start' => ['_extra', new WordPressPostmetaExtract('_EventStartDate')],
                'event_end' => ['_extra', new WordPressPostmetaExtract('_EventEndDate')],
                'event_organizer_source_id' => ['_extra', new WordPressPostmetaExtract('_EventOrganizerID')],
                'event_venue_source_id' => ['_extra', new WordPressPostmetaExtract('_EventVenueID')],
            ],
            destination: $this->destination,
            dependencies: [
                WpUsersToAccounts::MIGRATION_ID,
                WpTermsToTaxonomy::MIGRATION_ID,
                WpMediaToEntities::MIGRATION_ID,
            ],
            description: 'The Events Calendar events (tribe_events) → destination nodes, with _EventStartDate/_EventEndDate extracted from postmeta (G-016).',
        );
    }
}
