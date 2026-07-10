<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Source;

use Waaseyaa\Migrate\Source\WordPress\Source\WordPressUserSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * @internal
 */
function makeUserSource(string $fixture = 'small-site.xml'): WordPressUserSource
{
    return new WordPressUserSource(new WxrReader(__DIR__ . '/../../../testing/Fixtures/' . $fixture));
}

it('declares plugin metadata', function () {
    $source = makeUserSource();
    expect($source->id())->toBe('wordpress_user');
    expect($source->stability())->toBe('stable');
    expect($source->count())->toBeNull();
});

it('yields one SourceRecord per WP user', function () {
    $records = iterator_to_array(makeUserSource()->records(), false);
    expect($records)->toHaveCount(2);
    expect($records[0])->toBeInstanceOf(SourceRecord::class);
    expect($records[0]->sourceType)->toBe('wp_user');
});

it('populates user fields per data-model §1.1', function () {
    $records = iterator_to_array(makeUserSource()->records(), false);
    $admin = $records[0];

    expect($admin->field('id'))->toBe(1);
    expect($admin->field('login'))->toBe('admin');
    expect($admin->field('email'))->toBe('admin@example.test');
    expect($admin->field('display_name'))->toBe('Site Admin');
    expect($admin->field('first_name'))->toBe('Admin');
    expect($admin->field('last_name'))->toBe('User');
    expect($admin->field('role'))->toBe('administrator');
});

it('normalizes registered date to ISO 8601', function () {
    $records = iterator_to_array(makeUserSource()->records(), false);
    expect($records[0]->field('registered'))->toBe('2024-01-01T00:00:00+00:00');
});

it('falls back display_name to login when WXR omits it', function () {
    $fixturePath = sys_get_temp_dir() . '/wp_user_fallback_' . uniqid('', true) . '.xml';
    file_put_contents($fixturePath, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<wp:wxr_version>1.2</wp:wxr_version>
<wp:author>
<wp:author_id>99</wp:author_id>
<wp:author_login>terse</wp:author_login>
<wp:author_email></wp:author_email>
<wp:author_display_name></wp:author_display_name>
<wp:author_role>subscriber</wp:author_role>
</wp:author>
</channel>
</rss>
XML);

    try {
        $records = iterator_to_array((new WordPressUserSource(new WxrReader($fixturePath)))->records(), false);
        expect($records[0]->field('display_name'))->toBe('terse');
        expect($records[0]->field('email'))->toBe('');
        expect($records[0]->field('first_name'))->toBeNull();
        expect($records[0]->field('last_name'))->toBeNull();
        expect($records[0]->field('registered'))->toBeNull();
    } finally {
        @unlink($fixturePath);
    }
});

it('produces a deterministic SourceId for the same record', function () {
    $source = makeUserSource();
    $records = iterator_to_array($source->records(), false);

    $a = $source->sourceIdFor($records[0]);
    $b = $source->sourceIdFor($records[0]);

    expect($a)->toBeInstanceOf(SourceId::class);
    expect($a->sourceType)->toBe('wp_user');
    expect($a->keys)->toBe(['id' => '1']);
    expect($a->hash())->toBe($b->hash());
});

it('produces collision-free SourceIds vs other source types with the same id', function () {
    $source = makeUserSource();
    $records = iterator_to_array($source->records(), false);
    $userId = $source->sourceIdFor($records[0]);

    $postId = new SourceId('wp_post', ['id' => '1']);

    expect($userId->hash())->not->toBe($postId->hash());
});

it('wraps WxrParseException as SourceReadException when file is missing', function () {
    $source = new WordPressUserSource(new WxrReader('/nonexistent/wp_user.xml'));
    expect(fn () => iterator_to_array($source->records(), false))
        ->toThrow(SourceReadException::class);
});

it('exposes the migrationId on the wrapped SourceReadException', function () {
    $source = new WordPressUserSource(
        new WxrReader('/nonexistent/wp_user.xml'),
        migrationId: 'demo_migration',
    );

    try {
        iterator_to_array($source->records(), false);
        expect(false)->toBeTrue('expected SourceReadException');
    } catch (SourceReadException $e) {
        expect($e->sourceId)->toBe('wordpress_user');
        expect($e->migrationId)->toBe('demo_migration');
    }
});

it('skips non-user records (terms, posts, comments)', function () {
    $records = iterator_to_array(makeUserSource()->records(), false);
    foreach ($records as $record) {
        expect($record->sourceType)->toBe('wp_user');
    }
});

it('loginIndex builds a login => wp:author_id map from the WXR authors', function () {
    $index = WordPressUserSource::loginIndex(new WxrReader(__DIR__ . '/../../../testing/Fixtures/small-site.xml'));

    expect($index)->toBe(['admin' => 1, 'jane' => 2]);
});

it('loginIndex keeps the first wp:author_id seen for a duplicate login', function () {
    $fixturePath = sys_get_temp_dir() . '/wp_user_login_index_' . uniqid('', true) . '.xml';
    file_put_contents($fixturePath, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<wp:wxr_version>1.2</wp:wxr_version>
<wp:author>
<wp:author_id>1</wp:author_id>
<wp:author_login>dupe</wp:author_login>
<wp:author_email>a@example.test</wp:author_email>
<wp:author_display_name>A</wp:author_display_name>
<wp:author_role>author</wp:author_role>
</wp:author>
<wp:author>
<wp:author_id>2</wp:author_id>
<wp:author_login>dupe</wp:author_login>
<wp:author_email>b@example.test</wp:author_email>
<wp:author_display_name>B</wp:author_display_name>
<wp:author_role>author</wp:author_role>
</wp:author>
</channel>
</rss>
XML);

    try {
        $index = WordPressUserSource::loginIndex(new WxrReader($fixturePath));
        expect($index)->toBe(['dupe' => 1]);
    } finally {
        @unlink($fixturePath);
    }
});
