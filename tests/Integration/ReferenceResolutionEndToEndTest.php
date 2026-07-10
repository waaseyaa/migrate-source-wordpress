<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Migrate\Source\WordPress\Migration\ReferenceResolutionOptions;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpMediaToEntities;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpTermsToTaxonomy;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpUsersToAccounts;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressTaxonomySource;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressUserSource;
use Waaseyaa\Migrate\Source\WordPress\Tests\Integration\Fixtures\AllowAllPolicy;
use Waaseyaa\Migrate\Source\WordPress\Tests\Integration\Fixtures\RefTestSystemAccount;
use Waaseyaa\Migrate\Source\WordPress\Tests\Integration\Fixtures\WpRefTestEntity;
use Waaseyaa\Migrate\Source\WordPress\Tests\Integration\Fixtures\WpRefTestEntityType;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\ProcessChainExecutor;
use Waaseyaa\Migration\Runner\RunOptions;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;

/**
 * G-019 end-to-end acceptance: drives real `WxrReader` source plugins, the
 * real `MigrationRunner` + `MigrationIdMap` (backed by an in-memory SQLite
 * database via `DBALDatabase::createSqlite()`), and real
 * `EntityRepository`-backed `EntityDestination` writes for THREE migrations
 * run in sequence (users, terms, posts) — proving that authorship, term
 * hierarchy, term membership, and page hierarchy are resolved through the
 * genuine id-map machinery, not a hand-rolled process-chain stand-in.
 *
 * Mirrors the structure of
 * `Waaseyaa\Migration\Tests\Integration\EndToEndCsvImportTest` (not
 * reachable from this package's autoload-dev; the whole rig is rebuilt
 * locally against a small purpose-built WXR fixture instead of the shared
 * `small-site.xml`, because the reference-resolution scenario needs a
 * document-ordered parent-before-child page pair that the shared fixture
 * does not have).
 *
 * @spec G-019 — id-map reference resolution
 */
#[CoversNothing]
final class ReferenceResolutionEndToEndTest extends TestCase
{
    private DBALDatabase $db;
    private EntityTypeManager $typeManager;
    private MigrationIdMap $idMap;
    private EventDispatcher $dispatcher;
    private EntityAccessGate $gate;
    private AccountInterface $systemAccount;

    /** @var array<string, EntityRepository> */
    private array $repositories = [];

    private string $fixturePath;

    protected function setUp(): void
    {
        $this->fixturePath = sys_get_temp_dir() . '/wp_ref_resolution_' . uniqid('', true) . '.xml';
        file_put_contents($this->fixturePath, self::fixtureXml());

        $this->db = DBALDatabase::createSqlite();
        $conn = $this->db->getConnection();

        foreach (['account', 'taxonomy_term', 'article'] as $entityTypeId) {
            $conn->executeStatement(
                'CREATE TABLE IF NOT EXISTS "' . $entityTypeId . '" ('
                . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
                . '"uuid" TEXT, '
                . '"title" TEXT, '
                . '"_data" TEXT DEFAULT \'{}\''
                . ')',
            );
        }

        $conn->executeStatement(MigrationIdMapSchema::createTableSql());
        foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
            $conn->executeStatement($sql);
        }

        $this->typeManager = new EntityTypeManager(new EventDispatcher());
        $this->dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->db);

        foreach (['account', 'taxonomy_term', 'article'] as $entityTypeId) {
            $entityType = WpRefTestEntityType::make($entityTypeId);
            $this->typeManager->registerEntityType($entityType);
            $driver = new SqlStorageDriver($resolver, 'id');
            $this->repositories[$entityTypeId] = new EntityRepository(
                entityType: $entityType,
                driver: $driver,
                eventDispatcher: $this->dispatcher,
            );
        }

        $this->idMap = new MigrationIdMap($this->db);
        $this->systemAccount = new RefTestSystemAccount();
        $this->gate = new EntityAccessGate(new EntityAccessHandler([
            new AllowAllPolicy('account'),
            new AllowAllPolicy('taxonomy_term'),
            new AllowAllPolicy('article'),
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);
    }

    #[Test]
    public function testAuthorTermHierarchyAndPageParentAreResolvedThroughTheRealIdMap(): void
    {
        // The registry validates every declared dependency edge across ALL
        // registered definitions at boot() time (MigrationDependencyMissingException
        // otherwise) — so users, terms, media, and posts are registered
        // together in one registry even though media is never run() in this
        // test (posts declares a dependency on it per the shipped factory).
        $usersDestination = $this->buildDestination('account', WpUsersToAccounts::MIGRATION_ID);
        $usersDefinition = (new WpUsersToAccounts(new WxrReader($this->fixturePath), $usersDestination))->definition();

        $slugToTermId = WordPressTaxonomySource::slugIndex(new WxrReader($this->fixturePath));
        $termsDestination = $this->buildDestination('taxonomy_term', WpTermsToTaxonomy::MIGRATION_ID);
        $termsDefinition = (new WpTermsToTaxonomy(
            new WxrReader($this->fixturePath),
            $termsDestination,
            references: new ReferenceResolutionOptions(
                slugToTermId: $slugToTermId,
                entityRefResolve: $this->entityRefResolveClosure(),
                termEntityType: 'taxonomy_term',
            ),
        ))->definition();

        $mediaDestination = $this->buildDestination('article', WpMediaToEntities::MIGRATION_ID);
        $mediaDefinition = (new WpMediaToEntities(new WxrReader($this->fixturePath), $mediaDestination))->definition();

        $loginToId = WordPressUserSource::loginIndex(new WxrReader($this->fixturePath));
        $postsDestination = $this->buildDestination('article', WpPostsToArticles::MIGRATION_ID);
        $postsDefinition = (new WpPostsToArticles(
            new WxrReader($this->fixturePath),
            $postsDestination,
            references: new ReferenceResolutionOptions(
                loginToId: $loginToId,
                slugToTermId: $slugToTermId,
                entityRefResolve: $this->entityRefResolveClosure(),
                resolveParent: true,
                authorEntityType: 'account',
                termEntityType: 'taxonomy_term',
                postEntityType: 'article',
            ),
        ))->definition();

        $runner = $this->buildRunner([$usersDefinition, $termsDefinition, $mediaDefinition, $postsDefinition]);

        // --- Leg 1: users -------------------------------------------------
        $usersReport = $runner->run(WpUsersToAccounts::MIGRATION_ID, new RunOptions());
        self::assertSame(2, $usersReport->imported, 'two WP users');
        self::assertFalse($usersReport->aborted);

        // --- Leg 2: terms (with parent_ref resolution) --------------------
        $termsReport = $runner->run(WpTermsToTaxonomy::MIGRATION_ID, new RunOptions());
        self::assertSame(2, $termsReport->imported, 'two terms: news (top-level) + announcements (child)');
        self::assertFalse($termsReport->aborted);

        $newsTerm = $this->findBySourceId('taxonomy_term', WpTermsToTaxonomy::MIGRATION_ID, 'wp_term', '10');
        $announcementsTerm = $this->findBySourceId('taxonomy_term', WpTermsToTaxonomy::MIGRATION_ID, 'wp_term', '11');
        self::assertInstanceOf(WpRefTestEntity::class, $newsTerm);
        self::assertInstanceOf(WpRefTestEntity::class, $announcementsTerm);

        // Top-level term: parent_ref stays null (not a miss).
        self::assertNull($newsTerm->get('parent_ref'));
        // Child term: parent_ref resolves to news's own storage id (an int).
        self::assertSame($newsTerm->get('id'), $announcementsTerm->get('parent_ref'));
        self::assertIsInt($announcementsTerm->get('parent_ref'));

        // --- Leg 3: media (no reference resolution needed for this test) --
        $runner->run(WpMediaToEntities::MIGRATION_ID, new RunOptions());

        // --- Leg 4: posts (with uid, parent_ref, term_refs resolution) ----
        $postsReport = $runner->run(WpPostsToArticles::MIGRATION_ID, new RunOptions());
        self::assertSame(3, $postsReport->imported, 'three posts: post 100, parent page 101, child page 102');
        self::assertFalse($postsReport->aborted);

        $adminAccount = $this->findBySourceId('account', WpUsersToAccounts::MIGRATION_ID, 'wp_user', '1');
        self::assertInstanceOf(WpRefTestEntity::class, $adminAccount);

        $post100 = $this->findBySourceId('article', WpPostsToArticles::MIGRATION_ID, 'wp_post', '100');
        $parentPage101 = $this->findBySourceId('article', WpPostsToArticles::MIGRATION_ID, 'wp_post', '101');
        $childPage102 = $this->findBySourceId('article', WpPostsToArticles::MIGRATION_ID, 'wp_post', '102');
        self::assertInstanceOf(WpRefTestEntity::class, $post100);
        self::assertInstanceOf(WpRefTestEntity::class, $parentPage101);
        self::assertInstanceOf(WpRefTestEntity::class, $childPage102);

        // G-019: authorship resolved to the admin account's real storage id.
        self::assertSame($adminAccount->get('id'), $post100->get('uid'));
        self::assertIsInt($post100->get('uid'));

        // G-019: page hierarchy resolved to the parent page's real storage id.
        self::assertNull($post100->get('parent_ref'), 'top-level post has no parent');
        self::assertNull($parentPage101->get('parent_ref'), 'top-level page has no parent');
        self::assertSame($parentPage101->get('id'), $childPage102->get('parent_ref'));
        self::assertIsInt($childPage102->get('parent_ref'));

        // G-019: term membership resolved to the news term's real storage id.
        self::assertSame([$newsTerm->get('id')], $post100->get('term_refs'));
        self::assertSame([$announcementsTerm->get('id')], $childPage102->get('term_refs'));
    }

    // =========================================================================
    // Rig helpers
    // =========================================================================

    private function buildDestination(string $entityTypeId, string $migrationId): EntityDestination
    {
        return new EntityDestination(
            destinationEntityTypeId: $entityTypeId,
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repositories[$entityTypeId],
            idMap: $this->idMap,
            gate: $this->gate,
            eventDispatcher: $this->dispatcher,
            migrationId: $migrationId,
            account: $this->systemAccount,
        );
    }

    /** @param list<MigrationDefinition> $definitions */
    private function buildRunner(array $definitions): MigrationRunner
    {
        $provider = new class($definitions) implements HasMigrationsInterface {
            /** @param list<MigrationDefinition> $defs */
            public function __construct(private readonly array $defs)
            {
            }

            public function migrations(): iterable
            {
                yield from $this->defs;
            }
        };

        $registry = new MigrationRegistry([$provider]);
        $registry->boot();

        return new MigrationRunner(
            registry: $registry,
            chain: new ProcessChainExecutor(),
            idMap: $this->idMap,
        );
    }

    /**
     * The application-side resolver closure `WordPressEntityRefResolve` and
     * friends call: resolve a destination uuid to its real storage id via
     * `EntityRepository::findBy(['uuid' => $uuid])` — the canonical lookup
     * documented in `docs/customization.md`.
     *
     * @return \Closure(string, string): (int|string|null)
     */
    private function entityRefResolveClosure(): \Closure
    {
        return function (string $entityType, string $uuid): int|string|null {
            $repository = $this->repositories[$entityType] ?? null;
            if ($repository === null) {
                return null;
            }
            $matches = $repository->findBy(['uuid' => $uuid]);
            $entity = $matches[0] ?? null;

            return $entity?->get('id');
        };
    }

    private function findBySourceId(string $entityTypeId, string $migrationId, string $sourceType, string $wpId): ?WpRefTestEntity
    {
        $sourceId = new \Waaseyaa\Migration\SourceId(sourceType: $sourceType, keys: ['id' => $wpId]);
        $writeResult = $this->idMap->lookupDestination($migrationId, $sourceId);
        if ($writeResult === null) {
            return null;
        }

        $matches = $this->repositories[$entityTypeId]->findBy(['uuid' => $writeResult->destinationUuid]);
        $entity = $matches[0] ?? null;

        return $entity instanceof WpRefTestEntity ? $entity : null;
    }

    /**
     * Purpose-built WXR fixture: 2 authors, 2 categories (news top-level,
     * announcements child-of-news), 3 posts — a top-level post (100), a
     * top-level page (101), and a child page (102, `post_parent = 101`,
     * document-ordered AFTER 101 so the same-migration self-lookup sees the
     * parent's id-map row already written).
     */
    private static function fixtureXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
     xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:wfw="http://wellformedweb.org/CommentAPI/"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <title>G-019 Fixture Site</title>
    <link>https://example.test</link>
    <description>Reference-resolution end-to-end fixture</description>
    <pubDate>Mon, 14 May 2026 12:00:00 +0000</pubDate>
    <language>en-US</language>
    <wp:wxr_version>1.2</wp:wxr_version>
    <wp:base_site_url>https://example.test</wp:base_site_url>
    <wp:base_blog_url>https://example.test</wp:base_blog_url>

    <wp:author>
        <wp:author_id>1</wp:author_id>
        <wp:author_login>admin</wp:author_login>
        <wp:author_email>admin@example.test</wp:author_email>
        <wp:author_display_name>Site Admin</wp:author_display_name>
        <wp:author_role>administrator</wp:author_role>
    </wp:author>
    <wp:author>
        <wp:author_id>2</wp:author_id>
        <wp:author_login>jane</wp:author_login>
        <wp:author_email>jane@example.test</wp:author_email>
        <wp:author_display_name>Jane Author</wp:author_display_name>
        <wp:author_role>author</wp:author_role>
    </wp:author>

    <wp:category>
        <wp:term_id>10</wp:term_id>
        <wp:category_nicename>news</wp:category_nicename>
        <wp:category_parent></wp:category_parent>
        <wp:cat_name>News</wp:cat_name>
    </wp:category>
    <wp:category>
        <wp:term_id>11</wp:term_id>
        <wp:category_nicename>announcements</wp:category_nicename>
        <wp:category_parent>news</wp:category_parent>
        <wp:cat_name>Announcements</wp:cat_name>
    </wp:category>

    <item>
        <title>First post</title>
        <link>https://example.test/2025/05/first-post/</link>
        <pubDate>Mon, 05 May 2025 09:00:00 +0000</pubDate>
        <dc:creator><![CDATA[admin]]></dc:creator>
        <guid isPermaLink="false">https://example.test/?p=100</guid>
        <description></description>
        <content:encoded><![CDATA[Welcome to the test site.]]></content:encoded>
        <excerpt:encoded><![CDATA[Welcome.]]></excerpt:encoded>
        <wp:post_id>100</wp:post_id>
        <wp:post_date>2025-05-05 09:00:00</wp:post_date>
        <wp:post_date_gmt>2025-05-05 09:00:00</wp:post_date_gmt>
        <wp:comment_status>open</wp:comment_status>
        <wp:ping_status>open</wp:ping_status>
        <wp:post_name>first-post</wp:post_name>
        <wp:status>publish</wp:status>
        <wp:post_parent>0</wp:post_parent>
        <wp:menu_order>0</wp:menu_order>
        <wp:post_type>post</wp:post_type>
        <wp:post_password></wp:post_password>
        <wp:is_sticky>0</wp:is_sticky>
        <category domain="category" nicename="news"><![CDATA[News]]></category>
    </item>

    <item>
        <title>About</title>
        <link>https://example.test/about/</link>
        <pubDate>Mon, 05 May 2025 09:05:00 +0000</pubDate>
        <dc:creator><![CDATA[admin]]></dc:creator>
        <guid isPermaLink="false">https://example.test/?page_id=101</guid>
        <description></description>
        <content:encoded><![CDATA[About us.]]></content:encoded>
        <excerpt:encoded><![CDATA[]]></excerpt:encoded>
        <wp:post_id>101</wp:post_id>
        <wp:post_date>2025-05-05 09:05:00</wp:post_date>
        <wp:post_date_gmt>2025-05-05 09:05:00</wp:post_date_gmt>
        <wp:comment_status>closed</wp:comment_status>
        <wp:ping_status>closed</wp:ping_status>
        <wp:post_name>about</wp:post_name>
        <wp:status>publish</wp:status>
        <wp:post_parent>0</wp:post_parent>
        <wp:menu_order>0</wp:menu_order>
        <wp:post_type>page</wp:post_type>
        <wp:post_password></wp:post_password>
        <wp:is_sticky>0</wp:is_sticky>
    </item>

    <item>
        <title>About / Team</title>
        <link>https://example.test/about/team/</link>
        <pubDate>Mon, 05 May 2025 09:10:00 +0000</pubDate>
        <dc:creator><![CDATA[jane]]></dc:creator>
        <guid isPermaLink="false">https://example.test/?page_id=102</guid>
        <description></description>
        <content:encoded><![CDATA[Meet the team.]]></content:encoded>
        <excerpt:encoded><![CDATA[]]></excerpt:encoded>
        <wp:post_id>102</wp:post_id>
        <wp:post_date>2025-05-05 09:10:00</wp:post_date>
        <wp:post_date_gmt>2025-05-05 09:10:00</wp:post_date_gmt>
        <wp:comment_status>closed</wp:comment_status>
        <wp:ping_status>closed</wp:ping_status>
        <wp:post_name>about-team</wp:post_name>
        <wp:status>publish</wp:status>
        <wp:post_parent>101</wp:post_parent>
        <wp:menu_order>0</wp:menu_order>
        <wp:post_type>page</wp:post_type>
        <wp:post_password></wp:post_password>
        <wp:is_sticky>0</wp:is_sticky>
        <category domain="category" nicename="announcements"><![CDATA[Announcements]]></category>
    </item>
</channel>
</rss>
XML;
    }
}
