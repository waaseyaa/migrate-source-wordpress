<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Migration;

use Waaseyaa\Migrate\Source\WordPress\Migration\WpMenusToMenuLinks;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressMenuUrlResolve;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressMenuSource;
use Waaseyaa\Migrate\Source\WordPress\Testing\InMemoryDestination;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Runner\ProcessChainExecutor;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\SourceId;

function makeMenusToMenuLinksFactory(): WpMenusToMenuLinks
{
    $reader = new WxrReader(__DIR__ . '/../../../testing/Fixtures/menus.xml');

    return new WpMenusToMenuLinks($reader, new InMemoryDestination(), static fn (): null => null);
}

it('declares a migration definition with the expected id and source', function () {
    $definition = makeMenusToMenuLinksFactory()->definition();

    expect($definition)->toBeInstanceOf(MigrationDefinition::class);
    expect($definition->id)->toBe('wp_menus_to_menu_links');
    expect($definition->source)->toBeInstanceOf(WordPressMenuSource::class);
});

it('maps title, resolved url, menu_name, weight, and enabled through the process map', function () {
    $definition = makeMenusToMenuLinksFactory()->definition();

    expect($definition->processForField('title'))->toBe(['title']);
    expect($definition->processForField('url')[0])
        ->toBeInstanceOf(WordPressMenuUrlResolve::class);
    expect($definition->processForField('menu_name'))->toBe(['menu_name']);
    expect($definition->processForField('weight'))->toBe(['weight']);
    expect($definition->processForField('enabled'))->toBe(['enabled']);
});

it('does not map parent_id — parent resolution is app-side wiring', function () {
    $definition = makeMenusToMenuLinksFactory()->definition();

    expect(fn () => $definition->processForField('parent_id'))
        ->toThrow(\OutOfBoundsException::class);
});

it('depends on the posts migration before resolving object URLs', function () {
    expect(makeMenusToMenuLinksFactory()->definition()->dependencies)
        ->toBe([WpPostsToArticles::MIGRATION_ID]);
});

it('preserves custom menu URLs without consulting the id-map', function () {
    $definition = makeMenusToMenuLinksFactory()->definition();
    $customRecord = null;
    foreach ($definition->source->records() as $record) {
        if ($record->field('item_type') === 'custom') {
            $customRecord = $record;
            break;
        }
    }
    expect($customRecord)->not->toBeNull();

    $url = (new ProcessChainExecutor())->executeField(
        $definition,
        'url',
        $customRecord,
        static fn (): never => throw new \LogicException('Custom URLs must not use the id-map.'),
    );

    expect($url)->toBe($customRecord->field('url'));
    expect($url)->not->toBeEmpty();
});

it('resolves post object menu items through the real migration id-map without an empty url', function () {
    $database = DBALDatabase::createSqlite();
    $connection = $database->getConnection();
    $connection->executeStatement(MigrationIdMapSchema::createTableSql());
    foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
        $connection->executeStatement($sql);
    }

    $idMap = new MigrationIdMap($database);
    $sourceId = new SourceId('wp_post', ['id' => '76']);
    $idMap->upsert(
        migrationId: WpPostsToArticles::MIGRATION_ID,
        sourceId: $sourceId,
        destinationEntityType: 'node',
        destinationUuid: '019b0000-0000-7000-8000-000000000076',
        sourceRecordHash: 'source-hash-76',
        runId: '019b0000-0000-7000-8000-000000000001',
    );

    $factory = new WpMenusToMenuLinks(
        reader: new WxrReader(__DIR__ . '/../../../testing/Fixtures/menus.xml'),
        destination: new InMemoryDestination(),
        uuidToId: static fn (string $entityType, string $uuid): ?int =>
            $entityType === 'node' && $uuid === '019b0000-0000-7000-8000-000000000076' ? 42 : null,
    );
    $definition = $factory->definition();

    $postObjectRecord = null;
    foreach ($definition->source->records() as $record) {
        if ($record->field('object_id') === 76) {
            $postObjectRecord = $record;
            break;
        }
    }
    expect($postObjectRecord)->not->toBeNull();

    $url = (new ProcessChainExecutor())->executeField(
        definition: $definition,
        destinationField: 'url',
        record: $postObjectRecord,
        lookup: $idMap->lookupDestination(...),
    );

    expect($url)->toBe('/node/42');
    expect($url)->not->toBeEmpty();
});

it('fails a post object record instead of persisting an empty URL when its id-map row is missing', function () {
    $definition = makeMenusToMenuLinksFactory()->definition();
    $postObjectRecord = null;
    foreach ($definition->source->records() as $record) {
        if ($record->field('item_type') === 'post_type') {
            $postObjectRecord = $record;
            break;
        }
    }
    expect($postObjectRecord)->not->toBeNull();

    expect(fn () => (new ProcessChainExecutor())->executeField(
        $definition,
        'url',
        $postObjectRecord,
        static fn (): null => null,
    ))->toThrow(ProcessException::class, 'No posts id-map row');
});
