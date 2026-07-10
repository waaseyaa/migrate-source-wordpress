<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressDbUserSource;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\Testing\SourceConformanceTestCase;

/**
 * @internal
 */
#[CoversNothing]
final class DbUserSourceConformanceTest extends SourceConformanceTestCase
{
    private const string TABLE_PREFIX = 'wp_';

    private ?DatabaseInterface $smallDb = null;

    private ?DatabaseInterface $largeDb = null;

    protected function buildPluginUnderTest(): SourcePluginInterface
    {
        return new WordPressDbUserSource($this->smallFixtureDb());
    }

    protected function buildSmallFixturePath(): string
    {
        // The base class contract is path-shaped, but this source is
        // database-backed. Fixtures are built in-memory per fixture kind
        // instead; the "path" is a stable label distinguishing them.
        return 'small';
    }

    protected function buildLargeFixturePath(): string
    {
        return 'large';
    }

    protected function buildPluginForFixture(string $fixturePath): SourcePluginInterface
    {
        return new WordPressDbUserSource(
            $fixturePath === 'large' ? $this->largeFixtureDb() : $this->smallFixtureDb(),
        );
    }

    protected function buildPluginPointingAtMissingSource(): SourcePluginInterface
    {
        // A database with no wp_users table at all — the DB-backed analogue
        // of "file does not exist" for the WXR sources.
        return new WordPressDbUserSource(DBALDatabase::createSqlite(), migrationId: 'conformance_missing');
    }

    private function smallFixtureDb(): DatabaseInterface
    {
        if ($this->smallDb !== null) {
            return $this->smallDb;
        }

        $db = DBALDatabase::createSqlite();
        $this->createSchema($db);

        for ($i = 1; $i <= 5; $i++) {
            $this->seedUser($db, $i, \sprintf('testuser%d', $i), \sprintf('user%d@example.test', $i));
        }

        return $this->smallDb = $db;
    }

    private function largeFixtureDb(): DatabaseInterface
    {
        if ($this->largeDb !== null) {
            return $this->largeDb;
        }

        $db = DBALDatabase::createSqlite();
        $this->createSchema($db);

        for ($i = 1; $i <= 5000; $i++) {
            $this->seedUser($db, $i, \sprintf('testuser%d', $i), \sprintf('user%d@example.test', $i));
        }

        return $this->largeDb = $db;
    }

    private function createSchema(DatabaseInterface $db): void
    {
        $db->query(\sprintf(
            'CREATE TABLE %1$susers (
                ID INTEGER PRIMARY KEY,
                user_login TEXT NOT NULL,
                user_pass TEXT NOT NULL DEFAULT \'\',
                user_email TEXT NOT NULL,
                user_registered TEXT NOT NULL,
                user_status INTEGER NOT NULL DEFAULT 0,
                display_name TEXT NOT NULL DEFAULT \'\'
            )',
            self::TABLE_PREFIX,
        ));

        $db->query(\sprintf(
            'CREATE TABLE %1$susermeta (
                umeta_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )',
            self::TABLE_PREFIX,
        ));
    }

    private function seedUser(DatabaseInterface $db, int $id, string $login, string $email): void
    {
        $db->insert(self::TABLE_PREFIX . 'users')->values([
            'ID' => $id,
            'user_login' => $login,
            'user_pass' => '',
            'user_email' => $email,
            'user_registered' => '2020-01-15 10:30:00',
            'user_status' => 0,
            'display_name' => '',
        ])->execute();

        $db->insert(self::TABLE_PREFIX . 'usermeta')->values([
            'user_id' => $id,
            'meta_key' => 'wp_capabilities',
            'meta_value' => 'a:1:{s:10:"subscriber";b:1;}',
        ])->execute();
    }
}
