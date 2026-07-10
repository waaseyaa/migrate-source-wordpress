<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Source;

use Waaseyaa\Migrate\Source\WordPress\Source\WordPressTaxonomySource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * @internal
 */
function makeTaxonomySource(string $fixture = 'small-site.xml'): WordPressTaxonomySource
{
    return new WordPressTaxonomySource(new WxrReader(__DIR__ . '/../../../testing/Fixtures/' . $fixture));
}

/**
 * @internal
 */
function writeTempWxr(string $body): string
{
    $fixturePath = sys_get_temp_dir() . '/wp_term_test_' . uniqid('', true) . '.xml';
    file_put_contents($fixturePath, $body);
    return $fixturePath;
}

it('declares plugin metadata', function () {
    $source = makeTaxonomySource();
    expect($source->id())->toBe('wordpress_term');
    expect($source->stability())->toBe('stable');
    expect($source->count())->toBeNull();
});

it('yields one SourceRecord per WP term across all 3 element variants', function () {
    $records = iterator_to_array(makeTaxonomySource()->records(), false);
    expect($records)->toHaveCount(6);
    foreach ($records as $record) {
        expect($record)->toBeInstanceOf(SourceRecord::class);
        expect($record->sourceType)->toBe('wp_term');
    }
});

it('extracts <wp:category> fields with taxonomy_name=category', function () {
    $records = iterator_to_array(makeTaxonomySource()->records(), false);
    $news = $records[0];

    expect($news->field('id'))->toBe(10);
    expect($news->field('taxonomy_name'))->toBe('category');
    expect($news->field('name'))->toBe('News');
    expect($news->field('slug'))->toBe('news');
    expect($news->field('parent_slug'))->toBeNull();
});

it('extracts <wp:tag> fields with taxonomy_name=post_tag', function () {
    $records = iterator_to_array(makeTaxonomySource()->records(), false);
    $php = $records[4];

    expect($php->field('id'))->toBe(20);
    expect($php->field('taxonomy_name'))->toBe('post_tag');
    expect($php->field('name'))->toBe('PHP');
    expect($php->field('slug'))->toBe('php');
});

it('preserves parent_slug for hierarchical categories', function () {
    $records = iterator_to_array(makeTaxonomySource()->records(), false);
    $announcements = $records[1];

    expect($announcements->field('slug'))->toBe('announcements');
    expect($announcements->field('parent_slug'))->toBe('news');
});

it('returns null parent_slug for top-level terms', function () {
    $records = iterator_to_array(makeTaxonomySource()->records(), false);
    foreach ([$records[0], $records[2], $records[3], $records[4], $records[5]] as $top) {
        expect($top->field('parent_slug'))->toBeNull();
    }
});

it('synthesises stable ids for legacy <wp:category> elements without <wp:term_id>', function () {
    $fixturePath = writeTempWxr(<<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.1/">
<channel>
<wp:wxr_version>1.1</wp:wxr_version>
<wp:category>
<wp:category_nicename>legacy</wp:category_nicename>
<wp:cat_name>Legacy Cat</wp:cat_name>
</wp:category>
</channel>
</rss>
XML);

    try {
        $first = iterator_to_array((new WordPressTaxonomySource(new WxrReader($fixturePath)))->records(), false);
        $second = iterator_to_array((new WordPressTaxonomySource(new WxrReader($fixturePath)))->records(), false);

        expect($first[0]->field('id'))->toBeInt();
        expect($first[0]->field('id'))->toBeGreaterThan(0);
        expect($first[0]->field('id'))->toBe($second[0]->field('id'));
    } finally {
        @unlink($fixturePath);
    }
});

it('honours custom <wp:category_taxonomy> when present', function () {
    $fixturePath = writeTempWxr(<<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<wp:wxr_version>1.2</wp:wxr_version>
<wp:category>
<wp:term_id>77</wp:term_id>
<wp:category_taxonomy>genre</wp:category_taxonomy>
<wp:category_nicename>fiction</wp:category_nicename>
<wp:cat_name>Fiction</wp:cat_name>
</wp:category>
</channel>
</rss>
XML);

    try {
        $records = iterator_to_array((new WordPressTaxonomySource(new WxrReader($fixturePath)))->records(), false);
        expect($records[0]->field('taxonomy_name'))->toBe('genre');
        expect($records[0]->field('slug'))->toBe('fiction');
    } finally {
        @unlink($fixturePath);
    }
});

it('produces a deterministic SourceId for the same record', function () {
    $source = makeTaxonomySource();
    $records = iterator_to_array($source->records(), false);

    $a = $source->sourceIdFor($records[0]);
    $b = $source->sourceIdFor($records[0]);

    expect($a)->toBeInstanceOf(SourceId::class);
    expect($a->sourceType)->toBe('wp_term');
    expect($a->keys)->toBe(['id' => '10']);
    expect($a->hash())->toBe($b->hash());
});

it('produces collision-free SourceIds vs other source types with the same id', function () {
    $source = makeTaxonomySource();
    $records = iterator_to_array($source->records(), false);
    $termId = $source->sourceIdFor($records[0]);

    $userId = new SourceId('wp_user', ['id' => '10']);
    expect($termId->hash())->not->toBe($userId->hash());
});

it('wraps WxrParseException as SourceReadException when file is missing', function () {
    $source = new WordPressTaxonomySource(new WxrReader('/nonexistent/terms.xml'));
    expect(fn () => iterator_to_array($source->records(), false))
        ->toThrow(SourceReadException::class);
});

it('skips non-term records', function () {
    $records = iterator_to_array(makeTaxonomySource()->records(), false);
    foreach ($records as $record) {
        expect($record->sourceType)->toBe('wp_term');
    }
});

it('slugIndex builds a "taxonomy:slug" => wp:term_id map from every term', function () {
    $index = WordPressTaxonomySource::slugIndex(new WxrReader(__DIR__ . '/../../../testing/Fixtures/small-site.xml'));

    expect($index)->toBe([
        'category:news' => 10,
        'category:announcements' => 11,
        'category:guides' => 12,
        'category:uncategorized' => 13,
        'post_tag:php' => 20,
        'post_tag:wordpress' => 21,
    ]);
});

it('slugIndex keeps same-named slugs distinct across taxonomies', function () {
    $fixturePath = writeTempWxr(<<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<wp:wxr_version>1.2</wp:wxr_version>
<wp:category>
<wp:term_id>1</wp:term_id>
<wp:category_nicename>news</wp:category_nicename>
<wp:cat_name>News</wp:cat_name>
</wp:category>
<wp:tag>
<wp:term_id>2</wp:term_id>
<wp:tag_slug>news</wp:tag_slug>
<wp:tag_name>News</wp:tag_name>
</wp:tag>
</channel>
</rss>
XML);

    try {
        $index = WordPressTaxonomySource::slugIndex(new WxrReader($fixturePath));
        expect($index)->toBe(['category:news' => 1, 'post_tag:news' => 2]);
    } finally {
        @unlink($fixturePath);
    }
});
