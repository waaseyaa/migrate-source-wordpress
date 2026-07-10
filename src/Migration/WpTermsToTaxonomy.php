<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Migration;

use Waaseyaa\Migrate\Source\WordPress\Process\WordPressTermParentResolve;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressTaxonomySource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;

/**
 * Default WordPress terms → destination taxonomy migration factory.
 *
 * Hierarchical terms reference their parent by slug (`parent_slug`) — the
 * package always emits this as a plain string field (unchanged, for
 * backward compatibility). `slug` is also always emitted as a destination
 * field named `slug`; on framework versions carrying a first-class
 * `Term.slug` field (post-alpha.258) it lands there directly, on older
 * versions it rides the `_data` blob like any other unmapped value.
 *
 * ## Reference resolution (G-019)
 *
 * Passing `$references` (a
 * {@see \Waaseyaa\Migrate\Source\WordPress\Migration\ReferenceResolutionOptions}
 * with `$slugToTermId` set) adds an *additional* `parent_ref` destination
 * field carrying the parent term's resolved destination reference (`null`
 * for top-level terms). See `docs/customization.md` "Resolving references
 * through the id-map".
 *
 * @api
 *
 * @spec FR-020 — default taxonomy migration
 * @spec G-019 — id-map reference resolution
 */
final class WpTermsToTaxonomy
{
    public const string MIGRATION_ID = 'wp_terms_to_taxonomy';

    public function __construct(
        private readonly WxrReader $reader,
        private readonly DestinationPluginInterface $destination,
        private readonly ?ReferenceResolutionOptions $references = null,
    ) {
    }

    public function definition(): MigrationDefinition
    {
        $process = [
            'name' => 'name',
            'slug' => 'slug',
            'taxonomy' => 'taxonomy_name',
            'description' => 'description',
            'parent_slug' => 'parent_slug',
        ];

        $refs = $this->references;
        if ($refs !== null && $refs->slugToTermId !== null) {
            $process['parent_ref'] = [
                'parent_slug',
                new WordPressTermParentResolve(
                    slugToTermId: $refs->slugToTermId,
                    refResolve: $refs->entityRefResolve,
                    destinationEntityType: $refs->termEntityType,
                    onMiss: $refs->onMiss,
                ),
            ];
        }

        return new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: new WordPressTaxonomySource($this->reader, self::MIGRATION_ID),
            process: $process,
            destination: $this->destination,
            dependencies: [],
            description: 'Imports WordPress categories, tags, and custom taxonomy terms.',
        );
    }
}
