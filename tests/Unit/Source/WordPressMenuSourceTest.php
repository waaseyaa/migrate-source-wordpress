<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Source;

use Waaseyaa\Migrate\Source\WordPress\Source\WordPressMenuSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * @internal
 */
function makeMenuSource(string $fixture = 'menus.xml'): WordPressMenuSource
{
    return new WordPressMenuSource(new WxrReader(__DIR__ . '/../../../testing/Fixtures/' . $fixture));
}

/**
 * @return array<string, SourceRecord>
 */
function menuRecordsById(): array
{
    $records = iterator_to_array(makeMenuSource()->records(), false);
    $byId = [];
    foreach ($records as $record) {
        $byId[(string) $record->field('id')] = $record;
    }

    return $byId;
}

function menuRecordById(string $id): SourceRecord
{
    $record = menuRecordsById()[$id] ?? null;
    if ($record === null) {
        throw new \RuntimeException("No menu record with id {$id}.");
    }

    return $record;
}

it('declares plugin metadata', function () {
    $source = makeMenuSource();
    expect($source->id())->toBe('wordpress_menu_item');
    expect($source->stability())->toBe('stable');
    expect($source->count())->toBeNull();
});

it('yields only nav_menu_item records, dropping ordinary posts', function () {
    $records = iterator_to_array(makeMenuSource()->records(), false);
    expect($records)->toHaveCount(4);
    foreach ($records as $record) {
        expect($record)->toBeInstanceOf(SourceRecord::class);
        expect($record->sourceType)->toBe('wp_menu_item');
    }
});

it('extracts a custom link item with its raw URL', function () {
    $record = menuRecordById('428');

    expect($record->field('title'))->toBe('Events');
    expect($record->field('url'))->toBe('/events/');
    expect($record->field('object_type'))->toBeNull();
    expect($record->field('object_id'))->toBeNull();
    expect($record->field('menu_name'))->toBe('menu');
    expect($record->field('parent_wp_id'))->toBeNull();
    expect($record->field('weight'))->toBe(13);
    expect($record->field('enabled'))->toBeTrue();
});

it('extracts a post_type (page) item with object_type/object_id instead of a URL', function () {
    $record = menuRecordById('169');

    expect($record->field('url'))->toBeNull();
    expect($record->field('object_type'))->toBe('page');
    expect($record->field('object_id'))->toBe(76);
    expect($record->field('menu_name'))->toBe('menu');
    expect($record->field('parent_wp_id'))->toBeNull();
    expect($record->field('weight'))->toBe(2);
});

it('extracts a taxonomy (category) item with object_type/object_id', function () {
    $record = menuRecordById('190');

    expect($record->field('url'))->toBeNull();
    expect($record->field('object_type'))->toBe('category');
    expect($record->field('object_id'))->toBe(24);
    expect($record->field('menu_name'))->toBe('portal-menu');
});

it('preserves the WordPress parent nav item id (not resolved to a destination id)', function () {
    $record = menuRecordById('175');

    expect($record->field('parent_wp_id'))->toBe(169);
    expect($record->field('menu_name'))->toBe('menu');
    expect($record->field('weight'))->toBe(3);
});

it('normalizes the WordPress "0" no-parent sentinel to null', function () {
    $record = menuRecordById('428');
    expect($record->field('parent_wp_id'))->toBeNull();
});

it('distinguishes menus by nav_menu term slug', function () {
    expect(menuRecordById('428')->field('menu_name'))->toBe('menu');
    expect(menuRecordById('169')->field('menu_name'))->toBe('menu');
    expect(menuRecordById('175')->field('menu_name'))->toBe('menu');
    expect(menuRecordById('190')->field('menu_name'))->toBe('portal-menu');
});

it('captures wp:menu_order as weight', function () {
    expect(menuRecordById('190')->field('weight'))->toBe(1);
    expect(menuRecordById('428')->field('weight'))->toBe(13);
});

it('always marks emitted items enabled', function () {
    foreach (menuRecordsById() as $record) {
        expect($record->field('enabled'))->toBeTrue();
    }
});

it('produces a deterministic SourceId for the same record', function () {
    $source = makeMenuSource();
    $records = iterator_to_array($source->records(), false);

    $a = $source->sourceIdFor($records[0]);
    $b = $source->sourceIdFor($records[0]);

    expect($a)->toBeInstanceOf(SourceId::class);
    expect($a->sourceType)->toBe('wp_menu_item');
    expect($a->hash())->toBe($b->hash());
});

it('produces collision-free SourceIds vs other source types with the same id', function () {
    $source = makeMenuSource();
    $records = iterator_to_array($source->records(), false);
    $menuId = $source->sourceIdFor($records[0]);

    $postId = new SourceId('wp_post', $menuId->keys);
    expect($menuId->hash())->not->toBe($postId->hash());
});

it('wraps WxrParseException as SourceReadException when the file is missing', function () {
    $source = new WordPressMenuSource(new WxrReader('/nonexistent/menus.xml'));
    expect(fn () => iterator_to_array($source->records(), false))
        ->toThrow(SourceReadException::class);
});
