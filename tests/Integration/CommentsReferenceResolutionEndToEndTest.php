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
use Waaseyaa\Migrate\Source\WordPress\Migration\WpCommentsToEngagement;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpMediaToEntities;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpTermsToTaxonomy;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpUsersToAccounts;
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
use Waaseyaa\Migration\SourceId;

/**
 * Regression coverage for the comment id-map lookup type mismatch flagged
 * by an adversarial verifier.
 *
 * {@see WordPressCommentSource::normalize()} emits `post_id` as `int` and
 * `parent_id` as `int|null` (verbatim from the WXR reader), while every
 * id-map `SourceId` in this package is keyed with a *string* id
 * ({@see WordPressPostSource::sourceIdFor()},
 * {@see \Waaseyaa\Migrate\Source\WordPress\Source\WordPressCommentSource::sourceIdFor()}
 * both cast `(string) $id`). `SourceId` hashing is type-sensitive, so
 * feeding the raw int straight into {@see \Waaseyaa\Migration\Plugin\Process\LookupProcessor}
 * (as {@see WpCommentsToEngagement} did before this fix) hash-misses on
 * every record — the `post_id` and `parent_id` destination fields always
 * resolved to `null`, even though the referenced posts/comments were
 * genuinely imported first.
 *
 * This test drives the real `MigrationRunner` + `MigrationIdMap` (backed by
 * an in-memory SQLite database) across three migrations run in sequence —
 * users, posts, comments — mirroring the rig in
 * {@see ReferenceResolutionEndToEndTest}, so the lookup goes through the
 * genuine id-map machinery rather than a hand-rolled stand-in that would
 * mask the type mismatch.
 *
 * Note: as of this fix, WordPress comment migration has never yet been run
 * against a production destination — G-019/G-01x's shipped examples cover
 * posts, terms, and users only. This test closes the gap before the
 * comments migration is exercised for real.
 *
 * @spec FR-025 — default comments migration
 */
#[CoversNothing]
final class CommentsReferenceResolutionEndToEndTest extends TestCase
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
        $this->fixturePath = sys_get_temp_dir() . '/wp_comment_ref_resolution_' . uniqid('', true) . '.xml';
        file_put_contents($this->fixturePath, self::fixtureXml());

        $this->db = DBALDatabase::createSqlite();
        $conn = $this->db->getConnection();

        foreach (['account', 'article', 'engagement'] as $entityTypeId) {
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

        foreach (['account', 'article', 'engagement'] as $entityTypeId) {
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
            new AllowAllPolicy('article'),
            new AllowAllPolicy('engagement'),
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);
    }

    #[Test]
    public function testCommentPostAndParentReferencesResolveThroughTheRealIdMap(): void
    {
        $usersDestination = $this->buildDestination('account', WpUsersToAccounts::MIGRATION_ID);
        $usersDefinition = (new WpUsersToAccounts(new WxrReader($this->fixturePath), $usersDestination))->definition();

        // wp_posts_to_articles declares dependencies on wp_terms_to_taxonomy
        // and wp_media_to_entities. MigrationRegistry::boot() validates
        // every declared dependency edge across ALL registered definitions
        // — these two must be registered even though this fixture has no
        // terms/media and neither migration is run() below (mirrors
        // ReferenceResolutionEndToEndTest's rig).
        $termsDestination = $this->buildDestination('article', WpTermsToTaxonomy::MIGRATION_ID);
        $termsDefinition = (new WpTermsToTaxonomy(new WxrReader($this->fixturePath), $termsDestination))->definition();

        $mediaDestination = $this->buildDestination('article', WpMediaToEntities::MIGRATION_ID);
        $mediaDefinition = (new WpMediaToEntities(new WxrReader($this->fixturePath), $mediaDestination))->definition();

        $postsDestination = $this->buildDestination('article', WpPostsToArticles::MIGRATION_ID);
        $postsDefinition = (new WpPostsToArticles(new WxrReader($this->fixturePath), $postsDestination))->definition();

        $commentsDestination = $this->buildDestination('engagement', WpCommentsToEngagement::MIGRATION_ID);
        $commentsDefinition = (new WpCommentsToEngagement(new WxrReader($this->fixturePath), $commentsDestination))->definition();

        $runner = $this->buildRunner([$usersDefinition, $termsDefinition, $mediaDefinition, $postsDefinition, $commentsDefinition]);

        $runner->run(WpUsersToAccounts::MIGRATION_ID, new RunOptions());
        $postsReport = $runner->run(WpPostsToArticles::MIGRATION_ID, new RunOptions());
        self::assertSame(1, $postsReport->imported, 'one WP post');
        self::assertFalse($postsReport->aborted);

        $commentsReport = $runner->run(WpCommentsToEngagement::MIGRATION_ID, new RunOptions());
        self::assertSame(2, $commentsReport->imported, 'two WP comments: a top-level comment and its reply');
        self::assertFalse($commentsReport->aborted);

        $post = $this->findBySourceId('article', WpPostsToArticles::MIGRATION_ID, 'wp_post', '100');
        self::assertInstanceOf(WpRefTestEntity::class, $post);

        $topLevelComment = $this->findBySourceId('engagement', WpCommentsToEngagement::MIGRATION_ID, 'wp_comment', '1');
        $replyComment = $this->findBySourceId('engagement', WpCommentsToEngagement::MIGRATION_ID, 'wp_comment', '2');
        self::assertInstanceOf(WpRefTestEntity::class, $topLevelComment);
        self::assertInstanceOf(WpRefTestEntity::class, $replyComment);

        // The bug: post_id was fed into LookupProcessor as a raw int while
        // WordPressPostSource::sourceIdFor() keys its SourceId with a
        // string id, so the lookup always hash-missed and post_id stayed
        // null. Fixed: both comments resolve to the post's real
        // destination uuid.
        self::assertNotNull($topLevelComment->get('post_id'), 'top-level comment post_id must resolve, not hash-miss');
        self::assertSame($post->get('uuid'), $topLevelComment->get('post_id'));
        self::assertNotNull($replyComment->get('post_id'), 'reply comment post_id must resolve, not hash-miss');
        self::assertSame($post->get('uuid'), $replyComment->get('post_id'));

        // Top-level comment has no parent: null passes through TypeCoerceProcessor unchanged.
        self::assertNull($topLevelComment->get('parent_id'));

        // Reply resolves against this migration's own (in-progress) id-map —
        // the same type mismatch made this hash-miss too.
        self::assertNotNull($replyComment->get('parent_id'), 'reply comment parent_id must resolve, not hash-miss');
        self::assertSame($topLevelComment->get('uuid'), $replyComment->get('parent_id'));
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

    private function findBySourceId(string $entityTypeId, string $migrationId, string $sourceType, string $wpId): ?WpRefTestEntity
    {
        $sourceId = new SourceId(sourceType: $sourceType, keys: ['id' => $wpId]);
        $writeResult = $this->idMap->lookupDestination($migrationId, $sourceId);
        if ($writeResult === null) {
            return null;
        }

        $matches = $this->repositories[$entityTypeId]->findBy(['uuid' => $writeResult->destinationUuid]);
        $entity = $matches[0] ?? null;

        return $entity instanceof WpRefTestEntity ? $entity : null;
    }

    /**
     * Purpose-built WXR fixture: 1 author, 1 post, 2 comments — a top-level
     * comment (id 1) and a reply to it (id 2, `comment_parent = 1`,
     * document-ordered after comment 1 so the same-migration self-lookup
     * sees the parent's id-map row already written).
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
    <title>Comments Fixture Site</title>
    <link>https://example.test</link>
    <description>Comment reference-resolution end-to-end fixture</description>
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
        <wp:comment>
            <wp:comment_id>1</wp:comment_id>
            <wp:comment_author><![CDATA[Alice]]></wp:comment_author>
            <wp:comment_author_email>alice@example.test</wp:comment_author_email>
            <wp:comment_author_url></wp:comment_author_url>
            <wp:comment_author_IP>203.0.113.1</wp:comment_author_IP>
            <wp:comment_date>2025-05-06 10:00:00</wp:comment_date>
            <wp:comment_date_gmt>2025-05-06 10:00:00</wp:comment_date_gmt>
            <wp:comment_content><![CDATA[Great post!]]></wp:comment_content>
            <wp:comment_approved>1</wp:comment_approved>
            <wp:comment_type></wp:comment_type>
            <wp:comment_parent>0</wp:comment_parent>
            <wp:comment_user_id>0</wp:comment_user_id>
        </wp:comment>
        <wp:comment>
            <wp:comment_id>2</wp:comment_id>
            <wp:comment_author><![CDATA[Bob]]></wp:comment_author>
            <wp:comment_author_email>bob@example.test</wp:comment_author_email>
            <wp:comment_author_url></wp:comment_author_url>
            <wp:comment_author_IP>203.0.113.2</wp:comment_author_IP>
            <wp:comment_date>2025-05-06 11:00:00</wp:comment_date>
            <wp:comment_date_gmt>2025-05-06 11:00:00</wp:comment_date_gmt>
            <wp:comment_content><![CDATA[Agreed with Alice.]]></wp:comment_content>
            <wp:comment_approved>1</wp:comment_approved>
            <wp:comment_type></wp:comment_type>
            <wp:comment_parent>1</wp:comment_parent>
            <wp:comment_user_id>0</wp:comment_user_id>
        </wp:comment>
    </item>
</channel>
</rss>
XML;
    }
}
