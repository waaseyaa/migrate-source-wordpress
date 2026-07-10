<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpUsersToAccounts;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressDbUserSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\ProcessChainExecutor;
use Waaseyaa\Migration\Runner\RunOptions;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\SourceId;

/**
 * End-to-end proof of G-018's id-map continuity claim, driven through the
 * real {@see MigrationRunner} + {@see MigrationIdMap} (not the package's
 * usual `driveMigration()` stand-in — see {@see EndToEndImportTest}'s scope
 * note) so the "same wp id upserts, does not duplicate" contract is
 * verified against the actual `(migration_id, source_id_hash)` upsert
 * logic, not a hand-rolled approximation of it.
 *
 * Scenario: run `wp_users_to_accounts` once against the small-site WXR
 * fixture (source: author accounts only, ids 1 and 2), then run it again
 * against a WordPress database fixture that has those same two ids PLUS a
 * third, non-author member account (id 3) that WXR could never see. The
 * second run must UPDATE the id-map rows for ids 1/2 (same destination
 * uuid) and CREATE exactly one new row for id 3.
 *
 * @internal
 */
#[CoversNothing]
final class DbUserSourceEndToEndTest extends TestCase
{
    private const string WXR_FIXTURE = __DIR__ . '/../../testing/Fixtures/small-site.xml';

    public function test_db_source_run_updates_wxr_authors_and_adds_non_author_members(): void
    {
        $idMapDb = DBALDatabase::createSqlite();
        $this->createIdMapSchema($idMapDb);
        $idMap = new MigrationIdMap($idMapDb);

        $destination = new IdMapBackedDestination($idMap, 'account');

        // --- Run 1: WXR source (author accounts only — ids 1, 2) ---
        $wxrDefinition = (new WpUsersToAccounts(new WxrReader(self::WXR_FIXTURE), $destination))->definition();
        $this->runMigration($wxrDefinition, $idMap);

        self::assertCount(2, $destination->writes, 'WXR run imports the 2 author accounts (ids 1, 2)');
        $firstRunUuids = $this->uuidsByHash($destination);

        // --- Run 2: DB source against the WordPress database (ids 1, 2, 3) ---
        $wpDb = $this->buildWordPressFixtureDb();
        $dbSource = new WordPressDbUserSource($wpDb, WpUsersToAccounts::MIGRATION_ID);
        $dbDefinition = (new WpUsersToAccounts(new WxrReader(self::WXR_FIXTURE), $destination, $dbSource))->definition();

        self::assertSame(WpUsersToAccounts::MIGRATION_ID, $dbDefinition->id, 'the source seam does not change the migration id');

        $this->runMigration($dbDefinition, $idMap);

        // Same 2 authors updated (not duplicated) + 1 brand-new non-author member = 3 total.
        self::assertCount(3, $destination->writes, 'DB-source run upserts ids 1/2 and creates a new row for the non-author member (id 3)');

        $secondRunUuids = $this->uuidsByHash($destination);
        self::assertSame(
            $firstRunUuids,
            \array_intersect_key($secondRunUuids, $firstRunUuids),
            'ids 1/2 keep the same destination uuid across the WXR -> DB-source re-run — no duplicate accounts',
        );

        // The id-map itself, not just the stand-in destination, reflects the same continuity.
        $idMapRows = \iterator_to_array($idMapDb->select('migration_id_map')->execute(), false);
        self::assertCount(3, $idMapRows, 'exactly 3 id-map rows exist for wp_users_to_accounts after both runs (no duplicates for ids 1/2)');

        $nonAuthorRecord = null;
        foreach ($destination->log as $entry) {
            if ($entry['record']->values['username'] === 'member1') {
                $nonAuthorRecord = $entry['record'];
                break;
            }
        }
        self::assertNotNull($nonAuthorRecord, 'the non-author member (WXR-invisible) landed in the destination via the DB source');
        self::assertTrue($nonAuthorRecord->values['must_reset_password']);
        self::assertNull($nonAuthorRecord->values['password_hash']);
    }

    private function buildWordPressFixtureDb(): DatabaseInterface
    {
        $db = DBALDatabase::createSqlite();

        $db->query('CREATE TABLE wp_users (
            ID INTEGER PRIMARY KEY,
            user_login TEXT NOT NULL,
            user_pass TEXT NOT NULL DEFAULT \'\',
            user_email TEXT NOT NULL,
            user_registered TEXT NOT NULL,
            user_status INTEGER NOT NULL DEFAULT 0,
            display_name TEXT NOT NULL DEFAULT \'\'
        )');
        $db->query('CREATE TABLE wp_usermeta (
            umeta_id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            meta_key TEXT,
            meta_value TEXT
        )');

        // Same ids/logins as the WXR fixture's two <wp:author> entries.
        $db->insert('wp_users')->values([
            'ID' => 1, 'user_login' => 'admin', 'user_pass' => '', 'user_email' => 'admin@example.test',
            'user_registered' => '2020-01-15 10:30:00', 'user_status' => 0, 'display_name' => 'Site Admin',
        ])->execute();
        $db->insert('wp_usermeta')->values(['user_id' => 1, 'meta_key' => 'wp_capabilities', 'meta_value' => 'a:1:{s:13:"administrator";b:1;}'])->execute();

        $db->insert('wp_users')->values([
            'ID' => 2, 'user_login' => 'jane', 'user_pass' => '', 'user_email' => 'jane@example.test',
            'user_registered' => '2020-01-15 10:30:00', 'user_status' => 0, 'display_name' => 'Jane Author',
        ])->execute();
        $db->insert('wp_usermeta')->values(['user_id' => 2, 'meta_key' => 'wp_capabilities', 'meta_value' => 'a:1:{s:6:"author";b:1;}'])->execute();

        // Non-author member — WXR structurally cannot see this account (G-018).
        $db->insert('wp_users')->values([
            'ID' => 3, 'user_login' => 'member1', 'user_pass' => '', 'user_email' => 'member1@example.test',
            'user_registered' => '2021-06-01 09:00:00', 'user_status' => 0, 'display_name' => 'Test Member One',
        ])->execute();
        $db->insert('wp_usermeta')->values(['user_id' => 3, 'meta_key' => 'wp_capabilities', 'meta_value' => 'a:1:{s:10:"subscriber";b:1;}'])->execute();

        return $db;
    }

    private function createIdMapSchema(DatabaseInterface $db): void
    {
        $db->query(MigrationIdMapSchema::createTableSql());
        foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
            $db->query($sql);
        }
    }

    private function runMigration(MigrationDefinition $definition, MigrationIdMap $idMap): void
    {
        $provider = new class($definition) implements HasMigrationsInterface {
            public function __construct(private readonly MigrationDefinition $definition)
            {
            }

            public function migrations(): iterable
            {
                yield $this->definition;
            }
        };

        $registry = new MigrationRegistry([$provider]);
        // Explicit boot keeps this helper version-agnostic: lazy boot-on-first-query
        // only exists as of waaseyaa/migration alpha.259 (G-024).
        $registry->boot();
        $runner = new MigrationRunner($registry, new ProcessChainExecutor(), $idMap);
        $runner->run($definition->id, new RunOptions());
    }

    /**
     * @return array<string, string> source-id hash => destination uuid
     */
    private function uuidsByHash(IdMapBackedDestination $destination): array
    {
        $map = [];
        foreach ($destination->writes as $hash => $writeResult) {
            $map[$hash] = $writeResult->destinationUuid;
        }

        return $map;
    }
}

/**
 * Minimal test-only destination that, unlike the package's
 * `Testing\InMemoryDestination` stand-in, actually performs the id-map
 * upsert `EntityDestination` normally owns — so this test exercises the
 * real `(migration_id, source_id_hash)` upsert continuity contract instead
 * of a hand-rolled approximation of it. Deliberately not put under
 * `testing/` — it is a single-test fixture, not package-wide test surface.
 *
 * @internal
 */
final class IdMapBackedDestination implements DestinationPluginInterface
{
    /** @var array<string, WriteResult> */
    public array $writes = [];

    /** @var list<array{record: DestinationRecord, hash: string}> */
    public array $log = [];

    public function __construct(
        private readonly MigrationIdMap $idMap,
        private readonly string $destinationEntityType,
    ) {
    }

    public function id(): string
    {
        return 'id_map_backed_test_destination';
    }

    public function stability(): string
    {
        return 'stable';
    }

    public function write(DestinationRecord $record): WriteResult
    {
        $hash = $record->sourceId->hash();
        $uuid = 'uuid-' . \substr($hash, 0, 12);
        $sourceRecordHash = \hash('sha256', \json_encode($record->values, \JSON_THROW_ON_ERROR));

        $writeResult = $this->idMap->upsert(
            migrationId: $record->migrationId,
            sourceId: $record->sourceId,
            destinationEntityType: $this->destinationEntityType,
            destinationUuid: $uuid,
            sourceRecordHash: $sourceRecordHash,
            runId: '019683d3-' . \substr($hash, 0, 4) . '-7000-8000-' . \substr($hash, 0, 12),
        );

        $this->writes[$hash] = $writeResult;
        $this->log[] = ['record' => $record, 'hash' => $hash];

        return $writeResult;
    }

    public function rollback(WriteResult $result): void
    {
        unset($result);
    }

    public function lookup(SourceId $sourceId): ?WriteResult
    {
        return $this->writes[$sourceId->hash()] ?? null;
    }
}
