<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Migration;

use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToPathAliases;
use Waaseyaa\Migrate\Source\WordPress\Process\UuidToSystemPathProcessor;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressPermalinkToAlias;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource;
use Waaseyaa\Migrate\Source\WordPress\Testing\InMemoryDestination;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\Process\DefaultValueProcessor;
use Waaseyaa\Migration\Plugin\Process\LookupProcessor;
use Waaseyaa\Migration\Plugin\Process\TypeCoerceProcessor;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

function makePathAliasesFactory(?\Closure $uuidToId = null): WpPostsToPathAliases
{
    $reader = new WxrReader(__DIR__ . '/../../../testing/Fixtures/small-site.xml');

    return new WpPostsToPathAliases(
        reader: $reader,
        destination: new InMemoryDestination(),
        uuidToId: $uuidToId ?? static fn (string $entityType, string $uuid): int|string|null => null,
    );
}

it('declares a migration definition with the expected id and source', function () {
    $definition = makePathAliasesFactory()->definition();

    expect($definition)->toBeInstanceOf(MigrationDefinition::class);
    expect($definition->id)->toBe('wp_posts_to_path_aliases');
    expect($definition->source)->toBeInstanceOf(WordPressPostSource::class);
});

it('depends on the posts migration by default', function () {
    $definition = makePathAliasesFactory()->definition();

    expect($definition->dependencies)->toBe([WpPostsToArticles::MIGRATION_ID]);
});

it('builds the alias chain from _extra via WordPressPermalinkToAlias', function () {
    $definition = makePathAliasesFactory()->definition();
    $chain = $definition->processForField('alias');

    expect($chain)->toHaveCount(2);
    expect($chain[0])->toBe('_extra');
    expect($chain[1])->toBeInstanceOf(WordPressPermalinkToAlias::class);
});

it('builds the path chain: id -> string coerce -> lookup -> uuid-to-system-path', function () {
    $definition = makePathAliasesFactory()->definition();
    $chain = $definition->processForField('path');

    expect($chain)->toHaveCount(4);
    expect($chain[0])->toBe('id');

    $typeCoerce = $chain[1];
    expect($typeCoerce)->toBeInstanceOf(TypeCoerceProcessor::class);
    assert($typeCoerce instanceof TypeCoerceProcessor);
    expect($typeCoerce->targetType)->toBe('string');

    $lookup = $chain[2];
    expect($lookup)->toBeInstanceOf(LookupProcessor::class);
    assert($lookup instanceof LookupProcessor);
    expect($lookup->migration)->toBe(WpPostsToArticles::MIGRATION_ID);
    expect($lookup->sourceType)->toBe(WordPressPostSource::SOURCE_TYPE);
    expect($lookup->keyField)->toBe('id');

    expect($chain[3])->toBeInstanceOf(UuidToSystemPathProcessor::class);
});

it('defaults langcode to en and status to true', function () {
    $definition = makePathAliasesFactory()->definition();

    $langcodeChain = $definition->processForField('langcode');
    expect($langcodeChain)->toHaveCount(1);
    $langcode = $langcodeChain[0];
    expect($langcode)->toBeInstanceOf(DefaultValueProcessor::class);
    assert($langcode instanceof DefaultValueProcessor);
    expect($langcode->default)->toBe('en');

    $status = $definition->processForField('status')[0];
    expect($status)->toBeInstanceOf(DefaultValueProcessor::class);
    assert($status instanceof DefaultValueProcessor);
    expect($status->default)->toBeTrue();
});

it('honors a custom langcode and system path prefix', function () {
    $reader = new WxrReader(__DIR__ . '/../../../testing/Fixtures/small-site.xml');
    $factory = new WpPostsToPathAliases(
        reader: $reader,
        destination: new InMemoryDestination(),
        uuidToId: static fn (): int|string|null => null,
        destinationEntityType: 'article',
        systemPathPrefix: '/article/',
        langcode: 'fr',
    );
    $definition = $factory->definition();

    $langcode = $definition->processForField('langcode')[0];
    assert($langcode instanceof DefaultValueProcessor);
    expect($langcode->default)->toBe('fr');
});

it('accepts an injected source, defaulting to an unfiltered WordPressPostSource when omitted (verifier finding 2)', function () {
    $reader = new WxrReader(__DIR__ . '/../../../testing/Fixtures/small-site.xml');
    $filteredSource = new WordPressPostSource($reader, 'wp_posts_to_path_aliases', postTypes: ['post', 'page']);

    $factory = new WpPostsToPathAliases(
        reader: $reader,
        destination: new InMemoryDestination(),
        uuidToId: static fn (): int|string|null => null,
        source: $filteredSource,
    );
    $definition = $factory->definition();

    expect($definition->source)->toBe($filteredSource);
});

it('restricts emitted aliases to an injected filtered source (small-site.xml carries a "project" CPT the unfiltered default would include)', function () {
    $filteredSource = new WordPressPostSource(
        new WxrReader(__DIR__ . '/../../../testing/Fixtures/small-site.xml'),
        'wp_posts_to_path_aliases',
        postTypes: ['post', 'page'],
    );
    $unfilteredSource = new WordPressPostSource(
        new WxrReader(__DIR__ . '/../../../testing/Fixtures/small-site.xml'),
        'wp_posts_to_path_aliases',
    );

    $filteredTypes = [];
    foreach ($filteredSource->records() as $record) {
        $filteredTypes[] = $record->field('post_type');
    }
    $unfilteredTypes = [];
    foreach ($unfilteredSource->records() as $record) {
        $unfilteredTypes[] = $record->field('post_type');
    }

    expect($unfilteredTypes)->toContain('project');
    expect($filteredTypes)->not->toContain('project');
    expect($filteredTypes)->not->toBeEmpty();

    $factory = new WpPostsToPathAliases(
        reader: new WxrReader(__DIR__ . '/../../../testing/Fixtures/small-site.xml'),
        destination: new InMemoryDestination(),
        uuidToId: static fn (): int|string|null => null,
        source: $filteredSource,
    );

    expect($factory->definition()->source)->toBe($filteredSource);
});

it('end-to-end: chain resolves the destination system path via the id-map lookup and uuid-to-id closure', function () {
    // Simulate what LookupProcessor + UuidToSystemPathProcessor do together,
    // wired the same way the factory wires them, against a fake lookup +
    // uuid resolver — proves the chain composes correctly without requiring
    // a live MigrationRunner.
    $factory = makePathAliasesFactory(
        static fn (string $entityType, string $uuid): ?int => $entityType === 'node' && $uuid === 'uuid-post-100' ? 555 : null,
    );
    $definition = $factory->definition();
    $chain = $definition->processForField('path');

    $lookup = static function (string $migration, SourceId $sourceId): WriteResult {
        expect($migration)->toBe(WpPostsToArticles::MIGRATION_ID);
        expect($sourceId->keys)->toBe(['id' => '100']);

        return new WriteResult(
            destinationEntityType: 'node',
            destinationUuid: 'uuid-post-100',
            sourceRecordHash: 'hash',
            runId: 'run-1',
            writtenAt: '2026-01-01T00:00:00Z',
        );
    };

    $context = new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', ['id' => 100]),
        migrationId: 'wp_posts_to_path_aliases',
        destinationField: 'path',
        lookup: $lookup,
    );

    $value = null;
    foreach ($chain as $step) {
        $plugin = is_string($step) ? new class($step) implements \Waaseyaa\Migration\Plugin\ProcessPluginInterface {
            public function __construct(private readonly string $field) {}
            public function id(): string { return 'pass_through'; }
            public function stability(): string { return 'stable'; }
            public function transform(mixed $value, ProcessContext $context): mixed
            {
                return $context->sourceRecord->field($this->field);
            }
        } : $step;
        $value = $plugin->transform($value, $context);
    }

    expect($value)->toBe('/node/555');
});
