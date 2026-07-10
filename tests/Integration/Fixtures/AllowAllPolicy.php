<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Integration\Fixtures;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Allow-all access policy fixture, scoped to a single entity type id.
 *
 * Mirrors `Waaseyaa\Migration\Tests\Fixtures\AllowAllPolicy` (not reachable
 * from this package's `require-dev` autoload).
 *
 * @internal Test fixture only.
 */
final class AllowAllPolicy implements AccessPolicyInterface
{
    public function __construct(private readonly string $entityTypeId)
    {
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed();
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === $this->entityTypeId;
    }
}
