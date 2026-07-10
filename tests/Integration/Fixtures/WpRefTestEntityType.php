<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Integration\Fixtures;

use Waaseyaa\Entity\EntityType;

/**
 * Builds a fresh non-revisionable `EntityType` bound to
 * {@see WpRefTestEntity}, one per destination entity type id the reference
 * resolution end-to-end test needs (account / taxonomy_term / article).
 *
 * @internal Test fixture only.
 */
final class WpRefTestEntityType
{
    public static function make(string $id): EntityType
    {
        return new EntityType(
            id: $id,
            label: $id,
            class: WpRefTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
    }
}
