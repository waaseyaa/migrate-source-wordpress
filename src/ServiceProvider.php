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

    /**
     * Default migrations are shipped as factory classes (`Migration\Wp*To*.php`)
     * because they require consumer-supplied {@see \Waaseyaa\Migration\Plugin\DestinationPluginInterface}
     * instances and a configured {@see Wxr\WxrReader} pointed at the export
     * file. Consumers compose them at application boot, e.g.:
     *
     *     $reader = new WxrReader('/path/to/export.xml');
     *     $migrations = [
     *         (new WpUsersToAccounts($reader, $accountDest))->definition(),
     *         ...
     *     ];
     */
    public function migrations(): iterable
    {
        return [];
    }

    /**
     * Source + process plugins ship as factory classes too: source plugins
     * need a configured {@see Wxr\WxrReader} and {@see Process\WordPressMediaRewriteUrl}
     * needs an operator-supplied url resolver closure. Consumers instantiate
     * them at composition time.
     */
    public function migrationPlugins(): iterable
    {
        return [];
    }
}
