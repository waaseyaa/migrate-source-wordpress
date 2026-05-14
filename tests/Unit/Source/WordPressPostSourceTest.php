<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Source;

use Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * @internal
 */
function makePostSource(string $fixture = 'small-site.xml'): WordPressPostSource
{
    return new WordPressPostSource(new WxrReader(__DIR__ . '/../../../testing/Fixtures/' . $fixture));
}

it('declares plugin metadata', function () {
    $source = makePostSource();
    expect($source->id())->toBe('wordpress_post');
    expect($source->stability())->toBe('stable');
    expect($source->count())->toBeNull();
});

it('yields one SourceRecord per non-attachment item', function () {
    $records = iterator_to_array(makePostSource()->records(), false);
    expect($records)->toHaveCount(5);
    foreach ($records as $record) {
        expect($record)->toBeInstanceOf(SourceRecord::class);
        expect($record->sourceType)->toBe('wp_post');
    }
});

it('extracts post fields per data-model §1.4', function () {
    $records = iterator_to_array(makePostSource()->records(), false);
    $first = $records[0];

    expect($first->field('id'))->toBe(100);
    expect($first->field('post_type'))->toBe('post');
    expect($first->field('title'))->toBe('First post');
    expect($first->field('slug'))->toBe('first-post');
    expect($first->field('content'))->toBe('Welcome to the test site.');
    expect($first->field('excerpt'))->toBe('Welcome.');
    expect($first->field('status'))->toBe('publish');
    expect($first->field('comment_status'))->toBe('open');
    expect($first->field('author_login'))->toBe('admin');
});

it('preserves both post and page post_type values (no filtering)', function () {
    $records = iterator_to_array(makePostSource()->records(), false);
    $types = array_map(fn ($r) => $r->field('post_type'), $records);
    expect($types)->toContain('post');
    expect($types)->toContain('page');
});

it('preserves custom post types (CPT support)', function () {
    $records = iterator_to_array(makePostSource()->records(), false);
    $cpt = null;
    foreach ($records as $record) {
        if ($record->field('post_type') === 'project') {
            $cpt = $record;
            break;
        }
    }

    expect($cpt)->not->toBeNull();
    expect($cpt?->field('id'))->toBe(104);
    expect($cpt?->field('title'))->toBe('Featured project');
});

it('captures multiple <category> elements as terms array', function () {
    $records = iterator_to_array(makePostSource()->records(), false);
    $first = $records[0];

    $terms = $first->field('terms');
    expect($terms)->toBeArray();
    expect($terms)->toHaveCount(2);
    expect($terms)->toContain(['taxonomy' => 'category', 'slug' => 'news']);
    expect($terms)->toContain(['taxonomy' => 'post_tag', 'slug' => 'php']);
});

it('handles empty terms array gracefully', function () {
    $records = iterator_to_array(makePostSource()->records(), false);
    $page = null;
    foreach ($records as $record) {
        if ($record->field('post_type') === 'page') {
            $page = $record;
            break;
        }
    }

    expect($page?->field('terms'))->toBe([]);
});

it('normalizes published_at to ISO 8601', function () {
    $records = iterator_to_array(makePostSource()->records(), false);
    $first = $records[0];

    expect($first->field('published_at'))->toBe('2025-05-05T09:00:00+00:00');
});

it('falls back to post_date when post_date_gmt is the 0000 sentinel', function () {
    $records = iterator_to_array(makePostSource()->records(), false);
    $draft = null;
    foreach ($records as $record) {
        if ($record->field('id') === 102) {
            $draft = $record;
            break;
        }
    }

    expect($draft?->field('status'))->toBe('draft');
    expect($draft?->field('published_at'))->toBe('2025-05-07T09:00:00+00:00');
});

it('handles posts with empty content without crashing', function () {
    $fixturePath = sys_get_temp_dir() . '/wp_post_empty_' . uniqid('', true) . '.xml';
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
<title>Empty</title>
<dc:creator><![CDATA[admin]]></dc:creator>
<content:encoded><![CDATA[]]></content:encoded>
<wp:post_id>900</wp:post_id>
<wp:post_date>2025-05-01 00:00:00</wp:post_date>
<wp:post_date_gmt>2025-05-01 00:00:00</wp:post_date_gmt>
<wp:post_name>empty</wp:post_name>
<wp:status>publish</wp:status>
<wp:post_parent>0</wp:post_parent>
<wp:post_type>post</wp:post_type>
<wp:post_password></wp:post_password>
</item>
</channel>
</rss>
XML);

    try {
        $records = iterator_to_array((new WordPressPostSource(new WxrReader($fixturePath)))->records(), false);
        expect($records[0]->field('content'))->toBe('');
        expect($records[0]->field('excerpt'))->toBeNull();
    } finally {
        @unlink($fixturePath);
    }
});

it('populates password for password-protected posts', function () {
    $fixturePath = sys_get_temp_dir() . '/wp_post_pwd_' . uniqid('', true) . '.xml';
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
<title>Secret</title>
<dc:creator><![CDATA[admin]]></dc:creator>
<content:encoded><![CDATA[Top secret]]></content:encoded>
<wp:post_id>901</wp:post_id>
<wp:post_date>2025-05-02 00:00:00</wp:post_date>
<wp:post_date_gmt>2025-05-02 00:00:00</wp:post_date_gmt>
<wp:post_name>secret</wp:post_name>
<wp:status>publish</wp:status>
<wp:post_parent>0</wp:post_parent>
<wp:post_type>post</wp:post_type>
<wp:post_password>letmein</wp:post_password>
</item>
</channel>
</rss>
XML);

    try {
        $records = iterator_to_array((new WordPressPostSource(new WxrReader($fixturePath)))->records(), false);
        expect($records[0]->field('password'))->toBe('letmein');
    } finally {
        @unlink($fixturePath);
    }
});

it('preserves parent_id for child pages', function () {
    $fixturePath = sys_get_temp_dir() . '/wp_post_child_' . uniqid('', true) . '.xml';
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
<title>Child page</title>
<dc:creator><![CDATA[admin]]></dc:creator>
<content:encoded><![CDATA[Hello child]]></content:encoded>
<wp:post_id>902</wp:post_id>
<wp:post_date>2025-05-03 00:00:00</wp:post_date>
<wp:post_date_gmt>2025-05-03 00:00:00</wp:post_date_gmt>
<wp:post_name>child</wp:post_name>
<wp:status>publish</wp:status>
<wp:post_parent>800</wp:post_parent>
<wp:post_type>page</wp:post_type>
<wp:post_password></wp:post_password>
</item>
</channel>
</rss>
XML);

    try {
        $records = iterator_to_array((new WordPressPostSource(new WxrReader($fixturePath)))->records(), false);
        expect($records[0]->field('parent_id'))->toBe(800);
    } finally {
        @unlink($fixturePath);
    }
});

it('produces a deterministic SourceId for the same record', function () {
    $source = makePostSource();
    $records = iterator_to_array($source->records(), false);

    $a = $source->sourceIdFor($records[0]);
    $b = $source->sourceIdFor($records[0]);

    expect($a)->toBeInstanceOf(SourceId::class);
    expect($a->sourceType)->toBe('wp_post');
    expect($a->keys)->toBe(['id' => '100']);
    expect($a->hash())->toBe($b->hash());
});

it('produces collision-free SourceIds vs other source types with the same id', function () {
    $source = makePostSource();
    $records = iterator_to_array($source->records(), false);
    $postId = $source->sourceIdFor($records[0]);

    $userId = new SourceId('wp_user', ['id' => '100']);
    expect($postId->hash())->not->toBe($userId->hash());
});

it('wraps WxrParseException as SourceReadException when file is missing', function () {
    $source = new WordPressPostSource(new WxrReader('/nonexistent/posts.xml'));
    expect(fn () => iterator_to_array($source->records(), false))
        ->toThrow(SourceReadException::class);
});

it('does not yield attachment records', function () {
    $records = iterator_to_array(makePostSource()->records(), false);
    foreach ($records as $record) {
        expect($record->field('post_type'))->not->toBe('attachment');
    }
});
