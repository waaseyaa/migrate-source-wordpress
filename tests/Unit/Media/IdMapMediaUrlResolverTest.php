<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Media;

use Psr\Log\AbstractLogger;
use Stringable;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Migrate\Source\WordPress\Media\IdMapMediaUrlResolver;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressMediaRewriteUrl;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressMediaSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * @internal
 */
final class MediaResolverCapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

/**
 * @internal
 *
 * @return array{0: DBALDatabase, 1: MigrationIdMap}
 */
function freshIdMap(): array
{
    $db = DBALDatabase::createSqlite();

    $migrationFile = \dirname(__DIR__, 3) . '/vendor/waaseyaa/migration/migrations/2026_05_13_000001_create_migration_id_map.php';
    $migration = require $migrationFile;
    \assert($migration instanceof Migration);

    $schema = new SchemaBuilder($db->getConnection());
    $migration->up($schema);

    return [$db, new MigrationIdMap($db)];
}

it('resolves a bare uploads-relative path to a destination URL end-to-end', function () {
    [, $idMap] = freshIdMap();

    $idMap->upsert(
        migrationId: 'wp_media_to_entities',
        sourceId: new SourceId(WordPressMediaSource::SOURCE_TYPE, ['id' => '200']),
        destinationEntityType: 'media',
        destinationUuid: 'uuid-media-200',
        sourceRecordHash: 'hash',
        runId: 'run-1',
    );

    $resolver = new IdMapMediaUrlResolver(
        idMap: $idMap,
        mediaMigrationId: 'wp_media_to_entities',
        pathToAttachmentId: ['2025/05/logo.png' => 200],
        uuidToUrl: static fn (string $entityType, string $uuid): ?string => $entityType === 'media' && $uuid === 'uuid-media-200'
            ? '/media/uuid-media-200/file'
            : null,
    );

    expect(($resolver)('/wp-content/uploads/2025/05/logo.png'))->toBe('/media/uuid-media-200/file');
});

it('is invokable and exposes resolver() as an equivalent closure', function () {
    [, $idMap] = freshIdMap();

    $idMap->upsert(
        migrationId: 'wp_media_to_entities',
        sourceId: new SourceId(WordPressMediaSource::SOURCE_TYPE, ['id' => '200']),
        destinationEntityType: 'media',
        destinationUuid: 'uuid-media-200',
        sourceRecordHash: 'hash',
        runId: 'run-1',
    );

    $resolver = new IdMapMediaUrlResolver(
        idMap: $idMap,
        mediaMigrationId: 'wp_media_to_entities',
        pathToAttachmentId: ['2025/05/logo.png' => 200],
        uuidToUrl: static fn (): string => '/media/logo',
    );

    $closure = $resolver->resolver();
    expect($closure)->toBeInstanceOf(\Closure::class);
    expect($closure('/wp-content/uploads/2025/05/logo.png'))->toBe('/media/logo');
});

it('returns null and logs when the relative path is not in the attachment index', function () {
    [, $idMap] = freshIdMap();
    $logger = new MediaResolverCapturingLogger();

    $resolver = new IdMapMediaUrlResolver(
        idMap: $idMap,
        mediaMigrationId: 'wp_media_to_entities',
        pathToAttachmentId: [],
        uuidToUrl: static fn (): string => '/should-not-be-called',
        logger: $logger,
    );

    expect(($resolver)('/wp-content/uploads/2025/05/unknown.png'))->toBeNull();
    expect($logger->records)->not->toBeEmpty();
    expect($logger->records[0]['level'])->toBe('warning');
});

it('returns null and logs when the attachment has no id-map row yet', function () {
    [, $idMap] = freshIdMap();
    $logger = new MediaResolverCapturingLogger();

    $resolver = new IdMapMediaUrlResolver(
        idMap: $idMap,
        mediaMigrationId: 'wp_media_to_entities',
        pathToAttachmentId: ['2025/05/logo.png' => 200],
        uuidToUrl: static fn (): string => '/should-not-be-called',
        logger: $logger,
    );

    expect(($resolver)('/wp-content/uploads/2025/05/logo.png'))->toBeNull();
    expect($logger->records)->not->toBeEmpty();
});

it('returns null and logs when the uuid-to-url closure misses', function () {
    [, $idMap] = freshIdMap();
    $logger = new MediaResolverCapturingLogger();

    $idMap->upsert(
        migrationId: 'wp_media_to_entities',
        sourceId: new SourceId(WordPressMediaSource::SOURCE_TYPE, ['id' => '200']),
        destinationEntityType: 'media',
        destinationUuid: 'uuid-media-200',
        sourceRecordHash: 'hash',
        runId: 'run-1',
    );

    $resolver = new IdMapMediaUrlResolver(
        idMap: $idMap,
        mediaMigrationId: 'wp_media_to_entities',
        pathToAttachmentId: ['2025/05/logo.png' => 200],
        uuidToUrl: static fn (): ?string => null,
        logger: $logger,
    );

    expect(($resolver)('/wp-content/uploads/2025/05/logo.png'))->toBeNull();
    expect($logger->records)->not->toBeEmpty();
});

it('builds a path -> attachment-id index straight from the WXR via indexFromSource()', function () {
    $reader = new WxrReader(__DIR__ . '/../../../testing/Fixtures/small-site.xml');
    $source = new WordPressMediaSource($reader);

    $index = IdMapMediaUrlResolver::indexFromSource($source);

    expect($index)->toBe([
        '2025/05/logo.png' => 200,
        '2025/05/banner.jpg' => 201,
        '2025/05/guide.pdf' => 202,
    ]);
});

it('composes end-to-end with the real WordPressMediaRewriteUrl: body HTML in, rewritten HTML out', function () {
    [, $idMap] = freshIdMap();

    $idMap->upsert(
        migrationId: 'wp_media_to_entities',
        sourceId: new SourceId(WordPressMediaSource::SOURCE_TYPE, ['id' => '200']),
        destinationEntityType: 'media',
        destinationUuid: 'uuid-media-200',
        sourceRecordHash: 'hash',
        runId: 'run-1',
    );

    $reader = new WxrReader(__DIR__ . '/../../../testing/Fixtures/small-site.xml');
    $index = IdMapMediaUrlResolver::indexFromSource(new WordPressMediaSource($reader));

    $resolver = new IdMapMediaUrlResolver(
        idMap: $idMap,
        mediaMigrationId: 'wp_media_to_entities',
        pathToAttachmentId: $index,
        uuidToUrl: static fn (string $entityType, string $uuid): ?string => $entityType === 'media'
            ? '/media/' . $uuid . '/file'
            : null,
    );

    $rewriter = new WordPressMediaRewriteUrl($resolver->resolver());

    $context = new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', ['id' => 1]),
        migrationId: 'wp_posts_to_articles',
        destinationField: 'content',
        lookup: static fn (string $m, $id) => null,
    );

    $body = '<p>See our logo: <img src="https://example.test/wp-content/uploads/2025/05/logo.png"></p>';
    $out = $rewriter->transform($body, $context);

    expect($out)->toBe('<p>See our logo: <img src="/media/uuid-media-200/file"></p>');
});

it('composes end-to-end with the real WordPressMediaRewriteUrl and warns for an unmigrated attachment', function () {
    [, $idMap] = freshIdMap();
    $logger = new MediaResolverCapturingLogger();

    $reader = new WxrReader(__DIR__ . '/../../../testing/Fixtures/small-site.xml');
    $index = IdMapMediaUrlResolver::indexFromSource(new WordPressMediaSource($reader));

    $resolver = new IdMapMediaUrlResolver(
        idMap: $idMap,
        mediaMigrationId: 'wp_media_to_entities',
        pathToAttachmentId: $index,
        uuidToUrl: static fn (): string => '/should-not-be-reached',
        logger: $logger,
    );

    $rewriter = new WordPressMediaRewriteUrl($resolver->resolver());

    $context = new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', ['id' => 1]),
        migrationId: 'wp_posts_to_articles',
        destinationField: 'content',
        lookup: static fn (string $m, $id) => null,
    );

    $body = '<img src="https://example.test/wp-content/uploads/2025/05/banner.jpg">';
    $out = $rewriter->transform($body, $context);

    // banner.jpg is indexed but has no id-map row (never migrated) -> resolver
    // returns null -> WordPressMediaRewriteUrl leaves the URL untouched + warns.
    expect($out)->toBe($body);
    expect($logger->records)->not->toBeEmpty();
});
