<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Process;

use Waaseyaa\Migrate\Source\WordPress\Process\WordPressTermsResolve;
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
function termsContext(array $fields, array $idMap = []): ProcessContext
{
    return new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', $fields),
        migrationId: 'wp_posts_to_articles',
        destinationField: 'term_refs',
        lookup: static function (string $migration, SourceId $sourceId) use ($idMap): ?WriteResult {
            return $idMap[$sourceId->hash()] ?? null;
        },
    );
}

function termWriteResult(string $uuid): WriteResult
{
    return new WriteResult(
        destinationEntityType: 'taxonomy_term',
        destinationUuid: $uuid,
        sourceRecordHash: 'hash',
        runId: '019683d3-0000-7000-8000-000000000000',
        writtenAt: '2026-05-14T12:00:00Z',
    );
}

it('declares plugin metadata', function () {
    $plugin = new WordPressTermsResolve([]);
    expect($plugin->id())->toBe('wordpress_terms_resolve');
    expect($plugin->stability())->toBe('stable');
});

it('resolves a list of taxonomy:slug terms into destination uuids', function () {
    $newsId = new SourceId('wp_term', ['id' => '5']);
    $tagId = new SourceId('wp_term', ['id' => '9']);

    $plugin = new WordPressTermsResolve(
        slugToTermId: ['category:news' => 5, 'post_tag:php' => 9],
    );

    $terms = [
        ['taxonomy' => 'category', 'slug' => 'news'],
        ['taxonomy' => 'post_tag', 'slug' => 'php'],
    ];

    $out = $plugin->transform($terms, termsContext(
        ['terms' => $terms],
        [
            $newsId->hash() => termWriteResult('uuid-news'),
            $tagId->hash() => termWriteResult('uuid-php'),
        ],
    ));

    expect($out)->toBe(['uuid-news', 'uuid-php']);
});

it('resolves to storage ids when refResolve is supplied', function () {
    $newsId = new SourceId('wp_term', ['id' => '5']);
    $plugin = new WordPressTermsResolve(
        slugToTermId: ['category:news' => 5],
        refResolve: static fn (string $type, string $uuid) => $type === 'taxonomy_term' && $uuid === 'uuid-news' ? 500 : null,
    );

    $terms = [['taxonomy' => 'category', 'slug' => 'news']];
    $out = $plugin->transform($terms, termsContext(['terms' => $terms], [$newsId->hash() => termWriteResult('uuid-news')]));

    expect($out)->toBe([500]);
});

it('skips a term missing from the slug index when onMiss is null (default)', function () {
    $plugin = new WordPressTermsResolve(slugToTermId: []);
    $terms = [['taxonomy' => 'category', 'slug' => 'ghost']];

    $out = $plugin->transform($terms, termsContext(['terms' => $terms]));
    expect($out)->toBe([]);
});

it('skips a term with no id-map row when onMiss is null (default)', function () {
    $plugin = new WordPressTermsResolve(slugToTermId: ['category:news' => 5]);
    $terms = [['taxonomy' => 'category', 'slug' => 'news']];

    $out = $plugin->transform($terms, termsContext(['terms' => $terms]));
    expect($out)->toBe([]);
});

it('throws ProcessException on a missing slug when onMiss is fail', function () {
    $plugin = new WordPressTermsResolve(slugToTermId: [], onMiss: 'fail');
    $terms = [['taxonomy' => 'category', 'slug' => 'ghost']];

    expect(fn () => $plugin->transform($terms, termsContext(['terms' => $terms])))
        ->toThrow(ProcessException::class);
});

it('returns an empty list for a record with no terms', function () {
    $plugin = new WordPressTermsResolve(slugToTermId: []);
    expect($plugin->transform([], termsContext(['terms' => []])))->toBe([]);
});

it('reads terms from the source record when the chain has no upstream value', function () {
    $newsId = new SourceId('wp_term', ['id' => '5']);
    $plugin = new WordPressTermsResolve(slugToTermId: ['category:news' => 5]);
    $terms = [['taxonomy' => 'category', 'slug' => 'news']];

    $out = $plugin->transform(null, termsContext(['terms' => $terms], [$newsId->hash() => termWriteResult('uuid-news')]));
    expect($out)->toBe(['uuid-news']);
});

it('rejects an empty migration id', function () {
    expect(fn () => new WordPressTermsResolve([], migration: ''))->toThrow(\InvalidArgumentException::class);
});
