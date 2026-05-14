<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpCommentsToEngagement;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpMediaToEntities;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpTermsToTaxonomy;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpUsersToAccounts;
use Waaseyaa\Migrate\Source\WordPress\Testing\InMemoryDestination;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\SourceRecord;

/**
 * End-to-end import test against the small-site fixture.
 *
 * Scope note: a true kernel-boot e2e (Waaseyaa kernel + entity types +
 * MigrationRunner + sqlite id-map) is out of this package's test scope — it
 * crosses into consumer-side wiring (`waaseyaa/kernel`, `EntityRepository`).
 * Instead this test exercises the package's contribution: each migration
 * factory yields a valid {@see MigrationDefinition}, the source plugins
 * yield the expected record counts, and writing to a stand-in destination
 * is idempotent across repeat runs (FR-030, FR-031). Consumers that wire
 * the full kernel pipeline will inherit those guarantees through M-002's
 * runner.
 *
 * @internal
 */
#[CoversNothing]
final class EndToEndImportTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../testing/Fixtures/small-site.xml';

    public function test_users_migration_definition_is_well_formed(): void
    {
        $def = (new WpUsersToAccounts(new WxrReader(self::FIXTURE), new InMemoryDestination()))->definition();
        self::assertSame('wp_users_to_accounts', $def->id);
        self::assertSame([], $def->dependencies);
        self::assertArrayHasKey('username', $def->process);
        self::assertArrayHasKey('must_reset_password', $def->process);
        self::assertArrayHasKey('password_hash', $def->process);
    }

    public function test_dependency_chain_matches_canonical_order(): void
    {
        $reader = new WxrReader(self::FIXTURE);

        $users = (new WpUsersToAccounts($reader, new InMemoryDestination()))->definition();
        $terms = (new WpTermsToTaxonomy($reader, new InMemoryDestination()))->definition();
        $media = (new WpMediaToEntities($reader, new InMemoryDestination()))->definition();
        $posts = (new WpPostsToArticles($reader, new InMemoryDestination()))->definition();
        $comments = (new WpCommentsToEngagement($reader, new InMemoryDestination()))->definition();

        self::assertSame([], $users->dependencies);
        self::assertSame([], $terms->dependencies);
        self::assertSame(['wp_terms_to_taxonomy'], $media->dependencies);
        self::assertSame(
            ['wp_users_to_accounts', 'wp_terms_to_taxonomy', 'wp_media_to_entities'],
            $posts->dependencies,
        );
        self::assertSame(
            ['wp_users_to_accounts', 'wp_posts_to_articles'],
            $comments->dependencies,
        );
    }

    public function test_full_small_site_import_produces_expected_entity_counts(): void
    {
        $reader = fn () => new WxrReader(self::FIXTURE);

        $usersDest = new InMemoryDestination();
        $termsDest = new InMemoryDestination();
        $mediaDest = new InMemoryDestination();
        $postsDest = new InMemoryDestination();
        $commentsDest = new InMemoryDestination();

        $this->driveMigration((new WpUsersToAccounts($reader(), $usersDest))->definition(), $usersDest);
        $this->driveMigration((new WpTermsToTaxonomy($reader(), $termsDest))->definition(), $termsDest);
        $this->driveMigration((new WpMediaToEntities($reader(), $mediaDest))->definition(), $mediaDest);
        $this->driveMigration((new WpPostsToArticles($reader(), $postsDest))->definition(), $postsDest);
        $this->driveMigration((new WpCommentsToEngagement($reader(), $commentsDest))->definition(), $commentsDest);

        self::assertCount(2, $usersDest->writes, 'small-site fixture has 2 WP users');
        self::assertCount(6, $termsDest->writes, 'small-site fixture has 6 terms (4 categories + 2 tags)');
        self::assertCount(3, $mediaDest->writes, 'small-site fixture has 3 attachments');
        self::assertCount(5, $postsDest->writes, 'small-site fixture has 5 posts (including a CPT)');
        self::assertCount(4, $commentsDest->writes, 'small-site fixture has 4 comments');
    }

    public function test_re_running_import_is_idempotent(): void
    {
        $usersDest = new InMemoryDestination();

        $reader1 = new WxrReader(self::FIXTURE);
        $this->driveMigration((new WpUsersToAccounts($reader1, $usersDest))->definition(), $usersDest);
        $firstCount = count($usersDest->writes);
        $firstHashes = array_keys($usersDest->writes);

        $reader2 = new WxrReader(self::FIXTURE);
        $this->driveMigration((new WpUsersToAccounts($reader2, $usersDest))->definition(), $usersDest);

        self::assertCount($firstCount, $usersDest->writes, 'second run must not introduce new entities');
        self::assertSame($firstHashes, array_keys($usersDest->writes), 'second run must operate on the same source-id hashes');
    }

    public function test_known_user_field_values_round_trip(): void
    {
        $usersDest = new InMemoryDestination();
        $reader = new WxrReader(self::FIXTURE);
        $this->driveMigration((new WpUsersToAccounts($reader, $usersDest))->definition(), $usersDest);

        $entries = array_map(static fn ($e) => $e['record'], $usersDest->log);
        $admin = null;
        foreach ($entries as $record) {
            if (($record->values['username'] ?? null) === 'admin') {
                $admin = $record;
                break;
            }
        }

        self::assertNotNull($admin);
        self::assertSame('admin@example.test', $admin->values['email']);
        self::assertSame('Site Admin', $admin->values['display_name']);
        self::assertSame('administrator', $admin->values['role']);
        self::assertTrue($admin->values['must_reset_password']);
        self::assertNull($admin->values['password_hash']);
    }

    /**
     * Apply the migration's process map manually for the small-site fixture
     * and write the resulting DestinationRecords. This sidesteps wiring
     * MigrationRunner (which needs MigrationIdMap + a real DatabaseInterface)
     * while still exercising the source → process → destination contract.
     */
    private function driveMigration(MigrationDefinition $def, InMemoryDestination $dest): void
    {
        foreach ($def->source->records() as $sourceRecord) {
            self::assertInstanceOf(SourceRecord::class, $sourceRecord);
            $sourceId = $def->source->sourceIdFor($sourceRecord);

            $values = [];
            foreach ($def->process as $destinationField => $entry) {
                $values[$destinationField] = $this->resolveField(
                    $entry,
                    $sourceRecord,
                    $def->id,
                    $destinationField,
                );
            }

            $dest->write(new DestinationRecord(
                migrationId: $def->id,
                sourceId: $sourceId,
                values: $values,
            ));
        }
    }

    private function resolveField(
        mixed $entry,
        SourceRecord $record,
        string $migrationId,
        string $destinationField,
    ): mixed {
        if (is_string($entry)) {
            return $record->field($entry);
        }

        if ($entry instanceof ProcessPluginInterface) {
            return $entry->transform(null, $this->buildContext($record, $migrationId, $destinationField));
        }

        if (is_array($entry)) {
            $value = null;
            $context = $this->buildContext($record, $migrationId, $destinationField);
            foreach ($entry as $step) {
                if (is_string($step)) {
                    $value = $record->field($step);
                    continue;
                }
                if ($step instanceof ProcessPluginInterface) {
                    $value = $step->transform($value, $context);
                }
            }
            return $value;
        }

        return null;
    }

    private function buildContext(SourceRecord $record, string $migrationId, string $destinationField): ProcessContext
    {
        return new ProcessContext(
            sourceRecord: $record,
            migrationId: $migrationId,
            destinationField: $destinationField,
            lookup: static fn (string $m, $id) => null,
        );
    }
}
