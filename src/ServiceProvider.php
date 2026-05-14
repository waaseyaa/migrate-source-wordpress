<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider as BaseServiceProvider;
use Waaseyaa\Migration\Discovery\HasMigrationPluginsInterface;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;

/**
 * @api Service provider that registers the WordPress source-reader plugins
 *      and default migrations with the Waaseyaa migration substrate.
 */
final class ServiceProvider extends BaseServiceProvider implements
    HasMigrationsInterface,
    HasMigrationPluginsInterface
{
    public function register(): void
    {
        // Service-container bindings (none yet — source/process plugins are
        // discovered via migrationPlugins(); migrations via migrations()).
    }

    public function migrations(): iterable
    {
        return []; // Populated by WP09 (default migration definitions).
    }

    public function migrationPlugins(): iterable
    {
        return []; // Source plugins added in WP03..WP07; process plugins in WP08.
    }
}
