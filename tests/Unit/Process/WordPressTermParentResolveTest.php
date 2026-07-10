<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Process;

use Waaseyaa\Migrate\Source\WordPress\Process\WordPressTermParentResolve;
use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

/**
 * @internal
 *
 * @param array<string, WriteResult> $idMap Keyed by `SourceId::hash()`.
 */
function termParentContext(array $fields, array $idMap = []): ProcessContext
{
    return new ProcessContext(
        sourceRecord: new SourceRecord('wp_term', $fields),
        migrationId: 'wp_terms_to_taxonomy',
        destinationField: 'parent_ref',
        lookup: static function (string $migration, SourceId $sourceId) use ($idMap): ?WriteResult {
            return $idMap[$sourceId->hash()] ?? null;
        },
    );
}

it('declares plugin metadata', function () {
    $plugin = new WordPressTermParentResolve([]);
    expect($plugin->id())->toBe('wordpress_term_parent_resolve');
    expect($plugin->stability())->toBe('stable');
});

it('resolves parent_slug within the record\'s own taxonomy', function () {
    $newsId = new SourceId('wp_term', ['id' => '5']);
    $plugin = new WordPressTermParentResolve(slugToTermId: ['category:news' => 5]);

    $context = termParentContext(
        ['taxonomy_name' => 'category', 'parent_slug' => 'news'],
        [$newsId->hash() => new WriteResult('taxonomy_term', 'uuid-news', 'h', '019683d3-0000-7000-8000-000000000000', '2026-05-14T12:00:00Z')],
    );

    expect($plugin->transform('news', $context))->toBe('uuid-news');
});

it('resolves to a storage id when refResolve is supplied', function () {
    $newsId = new SourceId('wp_term', ['id' => '5']);
    $plugin = new WordPressTermParentResolve(
        slugToTermId: ['category:news' => 5],
        refResolve: static fn (string $type, string $uuid) => $uuid === 'uuid-news' ? 500 : null,
    );

    $context = termParentContext(
        ['taxonomy_name' => 'category', 'parent_slug' => 'news'],
        [$newsId->hash() => new WriteResult('taxonomy_term', 'uuid-news', 'h', '019683d3-0000-7000-8000-000000000000', '2026-05-14T12:00:00Z')],
    );

    expect($plugin->transform('news', $context))->toBe(500);
});

it('returns null (not a miss) for a top-level term with no parent', function () {
    $plugin = new WordPressTermParentResolve(slugToTermId: []);
    $context = termParentContext(['taxonomy_name' => 'category', 'parent_slug' => null]);

    expect($plugin->transform(null, $context))->toBeNull();
});

it('distinguishes same-named slugs across taxonomies via the taxonomy prefix', function () {
    $catNewsId = new SourceId('wp_term', ['id' => '5']);
    $plugin = new WordPressTermParentResolve(slugToTermId: [
        'category:news' => 5,
        'post_tag:news' => 9,
    ]);

    $context = termParentContext(
        ['taxonomy_name' => 'category', 'parent_slug' => 'news'],
        [$catNewsId->hash() => new WriteResult('taxonomy_term', 'uuid-category-news', 'h', '019683d3-0000-7000-8000-000000000000', '2026-05-14T12:00:00Z')],
    );

    expect($plugin->transform('news', $context))->toBe('uuid-category-news');
});

it('returns null for an unresolvable parent when onMiss is null (default)', function () {
    $plugin = new WordPressTermParentResolve(slugToTermId: []);
    $context = termParentContext(['taxonomy_name' => 'category', 'parent_slug' => 'ghost']);

    expect($plugin->transform('ghost', $context))->toBeNull();
});

it('throws ProcessException for an unresolvable parent when onMiss is fail', function () {
    $plugin = new WordPressTermParentResolve(slugToTermId: [], onMiss: 'fail');
    $context = termParentContext(['taxonomy_name' => 'category', 'parent_slug' => 'ghost']);

    expect(fn () => $plugin->transform('ghost', $context))->toThrow(ProcessException::class);
});

it('rejects an empty taxonomyField', function () {
    expect(fn () => new WordPressTermParentResolve([], taxonomyField: ''))->toThrow(\InvalidArgumentException::class);
});
