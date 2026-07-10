<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Integration\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Generic non-revisionable content entity fixture used by
 * {@see \Waaseyaa\Migrate\Source\WordPress\Tests\Integration\ReferenceResolutionEndToEndTest}
 * to stand in for three distinct destination entity types (account,
 * taxonomy_term, article) via three separately-registered `EntityType`s
 * pointed at the same class — mirrors
 * `Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidget`.
 *
 * `id` / `uuid` / `title` are real columns; every other destination field
 * (e.g. `uid`, `parent_ref`, `term_refs`) rides the `_data` JSON blob.
 *
 * @internal Test fixture — NOT a public extension point; do not depend on this.
 */
#[ContentEntityType(id: 'wp_ref_test_entity')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
class WpRefTestEntity extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
