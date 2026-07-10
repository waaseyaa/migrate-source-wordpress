<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Migration;

use Waaseyaa\Migrate\Source\WordPress\Source\WordPressUserSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\Process\DefaultValueProcessor;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;

/**
 * Default WordPress users → destination account migration factory.
 *
 * The package ships this as a starting point; consumers point it at their
 * own account/user entity destination via the `$destination` constructor
 * argument. The migration id `wp_users_to_accounts` is the canonical first
 * step in the M-005 dependency chain (FR-024: users → terms → media →
 * posts → comments).
 *
 * Password handling (research §1.7): we discard the WP password hash
 * entirely and emit `must_reset_password = true` so consumers force a
 * first-login reset. Destinations that prefer "use temporary password" or
 * "passwordless link" override the relevant fields downstream.
 *
 * A constructor-injected `$source` lets consumers swap in
 * {@see \Waaseyaa\Migrate\Source\WordPress\Source\WordPressDbUserSource}
 * (G-018) — or any other `SourcePluginInterface` emitting the same field
 * shape — without changing this factory. Left unset, the default remains
 * the WXR-backed `WordPressUserSource`, byte-identical to the pre-G-018
 * behavior. This factory does not hardcode any site-specific `metaFields`
 * mapping (e.g. a consent flag) — that composition lives on the caller's
 * `WordPressDbUserSource` instance and, if the destination needs it, an
 * additional `process` entry the caller adds via its own migration wiring;
 * see docs/migrating-from-wordpress.md "Migrating ALL users (database
 * source)".
 *
 * @api
 *
 * @spec FR-018 — default users migration
 * @spec FR-019 — password discard + reset gate
 * @spec G-018 — optional database-backed source seam
 */
final class WpUsersToAccounts
{
    public const string MIGRATION_ID = 'wp_users_to_accounts';

    public function __construct(
        private readonly WxrReader $reader,
        private readonly DestinationPluginInterface $destination,
        private readonly ?SourcePluginInterface $source = null,
    ) {
    }

    public function definition(): MigrationDefinition
    {
        return new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: $this->source ?? new WordPressUserSource($this->reader, self::MIGRATION_ID),
            process: [
                'username' => 'login',
                'email' => 'email',
                'display_name' => 'display_name',
                'first_name' => 'first_name',
                'last_name' => 'last_name',
                'created_at' => 'registered',
                'role' => 'role',
                'must_reset_password' => new DefaultValueProcessor(true),
                'password_hash' => new DefaultValueProcessor(null),
            ],
            destination: $this->destination,
            dependencies: [],
            description: 'Imports WordPress users as destination accounts. Passwords are discarded; first-login reset is required.',
        );
    }
}
