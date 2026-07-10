<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Integration\Fixtures;

use Waaseyaa\Access\AccountInterface;

/**
 * Minimal AccountInterface fixture standing in for a migration runner's
 * system account. Mirrors `Waaseyaa\Migration\Tests\Fixtures\MigrationSystemAccount`.
 *
 * @internal Test fixture only.
 */
final class RefTestSystemAccount implements AccountInterface
{
    public function id(): string
    {
        return 'system-migration';
    }

    public function hasPermission(string $permission): bool
    {
        return true;
    }

    /** @return string[] */
    public function getRoles(): array
    {
        return ['system'];
    }

    public function isAuthenticated(): bool
    {
        return true;
    }
}
