<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Migration;

use Waaseyaa\Migrate\Source\WordPress\Source\WordPressTaxonomySource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;

/**
 * Default WordPress terms → destination taxonomy migration factory.
 *
 * Hierarchical terms reference their parent by slug (`parent_slug`) — the
 * package emits this as a plain string field. Consumers that need
 * destination-uuid parent references resolve them in their own process
 * chain or downstream; M-002's substrate keys `migration_id_map` by the
 * source-side `wp_term` id, and a slug → id lookup would need a
 * package-specific extension.
 *
 * @api
 *
 * @spec FR-020 — default taxonomy migration
 */
final class WpTermsToTaxonomy
{
    public const string MIGRATION_ID = 'wp_terms_to_taxonomy';

    public function __construct(
        private readonly WxrReader $reader,
        private readonly DestinationPluginInterface $destination,
    ) {
    }

    public function definition(): MigrationDefinition
    {
        return new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: new WordPressTaxonomySource($this->reader, self::MIGRATION_ID),
            process: [
                'name' => 'name',
                'slug' => 'slug',
                'taxonomy' => 'taxonomy_name',
                'description' => 'description',
                'parent_slug' => 'parent_slug',
            ],
            destination: $this->destination,
            dependencies: [],
            description: 'Imports WordPress categories, tags, and custom taxonomy terms.',
        );
    }
}
