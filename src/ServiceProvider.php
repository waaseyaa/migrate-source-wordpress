<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider as BaseServiceProvider;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;

/**
 * @api Service provider that exposes consumer-composed default migrations to
 *      the Waaseyaa migration substrate.
 */
final class ServiceProvider extends BaseServiceProvider implements HasMigrationsInterface
{
    public function register(): void
    {
        // Service-container bindings (none yet). Source/process plugins are
        // consumer-composed factory classes; migrations use migrations().
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

}
