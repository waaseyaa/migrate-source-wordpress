<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Source;

use Waaseyaa\Migrate\Source\WordPress\Source\WordPressMediaSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * @internal
 */
function makeMediaSource(string $fixture = 'small-site.xml'): WordPressMediaSource
{
    return new WordPressMediaSource(new WxrReader(__DIR__ . '/../../../testing/Fixtures/' . $fixture));
}

it('declares plugin metadata', function () {
    $source = makeMediaSource();
    expect($source->id())->toBe('wordpress_media');
    expect($source->stability())->toBe('stable');
    expect($source->count())->toBeNull();
});

it('yields one SourceRecord per WP attachment', function () {
    $records = iterator_to_array(makeMediaSource()->records(), false);
    expect($records)->toHaveCount(3);
    foreach ($records as $record) {
        expect($record)->toBeInstanceOf(SourceRecord::class);
        expect($record->sourceType)->toBe('wp_media');
    }
});

it('extracts file_path from attachment_url stripping host', function () {
    $records = iterator_to_array(makeMediaSource()->records(), false);
    $logo = $records[0];

    expect($logo->field('id'))->toBe(200);
    expect($logo->field('file_path'))->toBe('/wp-content/uploads/2025/05/logo.png');
    expect($logo->field('original_url'))->toBe('https://example.test/wp-content/uploads/2025/05/logo.png');
});

it('derives mime_type from file extension', function () {
    $records = iterator_to_array(makeMediaSource()->records(), false);
    expect($records[0]->field('mime_type'))->toBe('image/png');
    expect($records[1]->field('mime_type'))->toBe('image/jpeg');
    expect($records[2]->field('mime_type'))->toBe('application/pdf');
});

it('falls back mime_type to application/octet-stream for unknown extensions', function () {
    $fixturePath = sys_get_temp_dir() . '/wp_media_unknown_' . uniqid('', true) . '.xml';
    file_put_contents($fixturePath, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
<wp:wxr_version>1.2</wp:wxr_version>
<item>
<title>thing.xyz</title>
<wp:post_id>500</wp:post_id>
<wp:post_type>attachment</wp:post_type>
<wp:attachment_url>https://example.test/wp-content/uploads/2025/06/thing.xyz</wp:attachment_url>
</item>
</channel>
</rss>
XML);

    try {
        $records = iterator_to_array((new WordPressMediaSource(new WxrReader($fixturePath)))->records(), false);
        expect($records[0]->field('mime_type'))->toBe('application/octet-stream');
    } finally {
        @unlink($fixturePath);
    }
});

it('extracts alt_text from postmeta _wp_attachment_image_alt', function () {
    $records = iterator_to_array(makeMediaSource()->records(), false);
    expect($records[0]->field('alt_text'))->toBe('Site logo');
    expect($records[1]->field('alt_text'))->toBeNull();
});

it('preserves parent_post_id when post_parent != 0', function () {
    $records = iterator_to_array(makeMediaSource()->records(), false);
    expect($records[0]->field('parent_post_id'))->toBe(100);
    expect($records[1]->field('parent_post_id'))->toBeNull();
    expect($records[2]->field('parent_post_id'))->toBe(103);
});

it('leaves size_bytes null at the source layer', function () {
    $records = iterator_to_array(makeMediaSource()->records(), false);
    foreach ($records as $record) {
        expect($record->field('size_bytes'))->toBeNull();
    }
});

it('produces a deterministic SourceId for the same record', function () {
    $source = makeMediaSource();
    $records = iterator_to_array($source->records(), false);

    $a = $source->sourceIdFor($records[0]);
    $b = $source->sourceIdFor($records[0]);

    expect($a)->toBeInstanceOf(SourceId::class);
    expect($a->sourceType)->toBe('wp_media');
    expect($a->keys)->toBe(['id' => '200']);
    expect($a->hash())->toBe($b->hash());
});

it('produces collision-free SourceIds vs other source types with the same id', function () {
    $source = makeMediaSource();
    $records = iterator_to_array($source->records(), false);
    $mediaId = $source->sourceIdFor($records[0]);

    $userId = new SourceId('wp_user', ['id' => '200']);
    expect($mediaId->hash())->not->toBe($userId->hash());
});

it('wraps WxrParseException as SourceReadException when file is missing', function () {
    $source = new WordPressMediaSource(new WxrReader('/nonexistent/media.xml'));
    expect(fn () => iterator_to_array($source->records(), false))
        ->toThrow(SourceReadException::class);
});

it('falls back file_path to _wp_attached_file when attachment_url is absent', function () {
    $fixturePath = sys_get_temp_dir() . '/wp_media_no_url_' . uniqid('', true) . '.xml';
    file_put_contents($fixturePath, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
<wp:wxr_version>1.2</wp:wxr_version>
<item>
<title>no-url.jpg</title>
<wp:post_id>501</wp:post_id>
<wp:post_type>attachment</wp:post_type>
<wp:postmeta>
<wp:meta_key>_wp_attached_file</wp:meta_key>
<wp:meta_value>2025/07/no-url.jpg</wp:meta_value>
</wp:postmeta>
</item>
</channel>
</rss>
XML);

    try {
        $records = iterator_to_array((new WordPressMediaSource(new WxrReader($fixturePath)))->records(), false);
        expect($records[0]->field('file_path'))->toBe('/2025/07/no-url.jpg');
        expect($records[0]->field('original_url'))->toBe('');
    } finally {
        @unlink($fixturePath);
    }
});
