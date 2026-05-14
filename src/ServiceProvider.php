<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress;

use Waaseyaa\Foundation\ServiceProvider as BaseServiceProvider;
use Waaseyaa\Migration\Plugin\HasMigrationPluginsInterface;
use Waaseyaa\Migration\Plugin\HasMigrationsInterface;

/**
 * @api Service provider that registers the WordPress source-reader plugins
 *      and default migrations with the Waaseyaa migration substrate.
 */
final class ServiceProvider extends BaseServiceProvider implements
    HasMigrationsInterface,
    HasMigrationPluginsInterface
{
    public function migrations(): array
    {
        return []; // Populated by WP09 (default migration definitions).
    }

    public function migrationPlugins(): array
    {
        return []; // Source plugins added in WP03..WP07; process plugins in WP08.
    }
}
