<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Source;

use Waaseyaa\Migrate\Source\WordPress\Source\WordPressCommentSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * @internal
 */
function makeCommentSource(string $fixture = 'small-site.xml'): WordPressCommentSource
{
    return new WordPressCommentSource(new WxrReader(__DIR__ . '/../../../testing/Fixtures/' . $fixture));
}

/**
 * @param iterable<SourceRecord> $records
 */
function findCommentById(iterable $records, int $id): ?SourceRecord
{
    foreach ($records as $record) {
        if ($record->field('id') === $id) {
            return $record;
        }
    }
    return null;
}

it('declares plugin metadata', function () {
    $source = makeCommentSource();
    expect($source->id())->toBe('wordpress_comment');
    expect($source->stability())->toBe('stable');
    expect($source->count())->toBeNull();
});

it('yields one SourceRecord per WP comment', function () {
    $records = iterator_to_array(makeCommentSource()->records(), false);
    expect($records)->toHaveCount(4);
    foreach ($records as $record) {
        expect($record)->toBeInstanceOf(SourceRecord::class);
        expect($record->sourceType)->toBe('wp_comment');
    }
});

it('extracts comment fields per data-model §1.5', function () {
    $records = iterator_to_array(makeCommentSource()->records(), false);
    $first = findCommentById($records, 500);

    expect($first?->field('post_id'))->toBe(100);
    expect($first?->field('author'))->toBe('Visitor One');
    expect($first?->field('author_email'))->toBe('visitor1@example.test');
    expect($first?->field('author_ip'))->toBe('10.0.0.1');
    expect($first?->field('content'))->toBe('Great first post!');
    expect($first?->field('approved'))->toBeTrue();
});

it('preserves threading with parent_id null for top-level and integer for replies', function () {
    $records = iterator_to_array(makeCommentSource()->records(), false);

    $top = findCommentById($records, 500);
    $reply = findCommentById($records, 501);

    expect($top?->field('parent_id'))->toBeNull();
    expect($reply?->field('parent_id'))->toBe(500);
});

it('maps approved=1 to true and surfaces no approved_raw in _extra', function () {
    $records = iterator_to_array(makeCommentSource()->records(), false);
    $approved = findCommentById($records, 500);

    expect($approved?->field('approved'))->toBeTrue();
    $extra = $approved?->field('_extra');
    expect($extra)->toBeArray();
    expect($extra)->not->toHaveKey('approved_raw');
});

it('maps approved=spam to false and surfaces approved_raw=spam in _extra', function () {
    $records = iterator_to_array(makeCommentSource()->records(), false);
    $spam = findCommentById($records, 502);

    expect($spam?->field('approved'))->toBeFalse();
    $extra = $spam?->field('_extra');
    expect($extra)->toBeArray();
    expect($extra['approved_raw'] ?? null)->toBe('spam');
});

it('preserves comment_type for pingbacks/trackbacks', function () {
    $records = iterator_to_array(makeCommentSource()->records(), false);
    $pingback = findCommentById($records, 503);

    expect($pingback?->field('comment_type'))->toBe('pingback');
});

it('populates user_login from comment_user_id when non-zero', function () {
    $records = iterator_to_array(makeCommentSource()->records(), false);
    $admin = findCommentById($records, 501);
    $anon = findCommentById($records, 500);

    expect($admin?->field('user_login'))->toBe('1');
    expect($anon?->field('user_login'))->toBeNull();
});

it('normalizes published_at to ISO 8601', function () {
    $records = iterator_to_array(makeCommentSource()->records(), false);
    $first = findCommentById($records, 500);

    expect($first?->field('published_at'))->toBe('2025-05-05T10:00:00+00:00');
});

it('handles approved=0 (pending) as false with approved_raw preserved', function () {
    $fixturePath = sys_get_temp_dir() . '/wp_comment_pending_' . uniqid('', true) . '.xml';
    file_put_contents($fixturePath, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
     xmlns:wp="http://wordpress.org/export/1.2/"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
     xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
<wp:wxr_version>1.2</wp:wxr_version>
<item>
<title>Host post</title>
<dc:creator><![CDATA[admin]]></dc:creator>
<content:encoded><![CDATA[]]></content:encoded>
<wp:post_id>700</wp:post_id>
<wp:post_date>2025-05-01 00:00:00</wp:post_date>
<wp:post_date_gmt>2025-05-01 00:00:00</wp:post_date_gmt>
<wp:post_name>host</wp:post_name>
<wp:status>publish</wp:status>
<wp:post_parent>0</wp:post_parent>
<wp:post_type>post</wp:post_type>
<wp:post_password></wp:post_password>
<wp:comment>
<wp:comment_id>9000</wp:comment_id>
<wp:comment_author><![CDATA[Pending Person]]></wp:comment_author>
<wp:comment_content><![CDATA[Hello, awaiting moderation.]]></wp:comment_content>
<wp:comment_date>2025-05-01 12:00:00</wp:comment_date>
<wp:comment_date_gmt>2025-05-01 12:00:00</wp:comment_date_gmt>
<wp:comment_approved>0</wp:comment_approved>
<wp:comment_parent>0</wp:comment_parent>
<wp:comment_user_id>0</wp:comment_user_id>
</wp:comment>
</item>
</channel>
</rss>
XML);

    try {
        $records = iterator_to_array((new WordPressCommentSource(new WxrReader($fixturePath)))->records(), false);
        expect($records[0]->field('approved'))->toBeFalse();
        $extra = $records[0]->field('_extra');
        expect($extra)->toBeArray();
        expect($extra['approved_raw'] ?? null)->toBe('0');
    } finally {
        @unlink($fixturePath);
    }
});

it('produces a deterministic SourceId for the same record', function () {
    $source = makeCommentSource();
    $records = iterator_to_array($source->records(), false);

    $first = findCommentById($records, 500);
    expect($first)->not->toBeNull();

    $a = $source->sourceIdFor($first);
    $b = $source->sourceIdFor($first);

    expect($a)->toBeInstanceOf(SourceId::class);
    expect($a->sourceType)->toBe('wp_comment');
    expect($a->keys)->toBe(['id' => '500']);
    expect($a->hash())->toBe($b->hash());
});

it('produces collision-free SourceIds vs other source types with the same id', function () {
    $source = makeCommentSource();
    $records = iterator_to_array($source->records(), false);
    $first = findCommentById($records, 500);
    expect($first)->not->toBeNull();

    $commentId = $source->sourceIdFor($first);
    $userId = new SourceId('wp_user', ['id' => '500']);

    expect($commentId->hash())->not->toBe($userId->hash());
});

it('wraps WxrParseException as SourceReadException when file is missing', function () {
    $source = new WordPressCommentSource(new WxrReader('/nonexistent/comments.xml'));
    expect(fn () => iterator_to_array($source->records(), false))
        ->toThrow(SourceReadException::class);
});

it('skips non-comment records', function () {
    $records = iterator_to_array(makeCommentSource()->records(), false);
    foreach ($records as $record) {
        expect($record->sourceType)->toBe('wp_comment');
    }
});
