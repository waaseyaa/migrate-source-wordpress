<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Wxr;

use Psr\Log\AbstractLogger;
use Stringable;
use Waaseyaa\Migrate\Source\WordPress\Exception\WxrParseException;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrVersion;

/**
 * @internal
 */
final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

it('throws fileNotFound for a missing path', function () {
    $reader = new WxrReader('/nonexistent/path.xml');

    expect(fn () => iterator_to_array($reader->records()))
        ->toThrow(WxrParseException::class);
});

it('parses every record in the small-site fixture with correct counts', function () {
    $fixture = __DIR__ . '/../../../testing/Fixtures/small-site.xml';
    $reader = new WxrReader($fixture);

    $records = [];
    foreach ($reader->records() as $r) {
        $records[] = $r;
    }

    $byType = array_count_values(array_column($records, 'type'));

    expect($byType['user'])->toBe(2);
    expect($byType['term'])->toBe(6);
    expect($byType['post'])->toBe(5);          // 4 standard posts/pages + 1 CPT (project)
    expect($byType['attachment'])->toBe(3);
    expect($byType['comment'])->toBe(4);

    // Assert the CPT post (post_type=project) is yielded as 'post'.
    $postTypes = array_map(
        static fn (array $r): string => (string) $r['data']['post_type'],
        array_filter($records, static fn (array $r): bool => $r['type'] === 'post'),
    );
    expect($postTypes)->toContain('project');
});

it('extracts user fields from the small-site fixture', function () {
    $fixture = __DIR__ . '/../../../testing/Fixtures/small-site.xml';
    $reader = new WxrReader($fixture);

    $users = [];
    foreach ($reader->records() as $r) {
        if ($r['type'] === 'user') {
            $users[$r['data']['login']] = $r['data'];
        }
    }

    expect($users)->toHaveKeys(['admin', 'jane']);
    expect($users['admin']['email'])->toBe('admin@example.test');
    expect($users['admin']['display_name'])->toBe('Site Admin');
    expect($users['admin']['role'])->toBe('administrator');
    expect($users['jane']['display_name'])->toBe('Jane Author');
});

it('extracts term parent slugs for hierarchical categories', function () {
    $fixture = __DIR__ . '/../../../testing/Fixtures/small-site.xml';
    $reader = new WxrReader($fixture);

    $terms = [];
    foreach ($reader->records() as $r) {
        if ($r['type'] === 'term') {
            $terms[$r['data']['slug']] = $r['data'];
        }
    }

    expect($terms['announcements']['parent_slug'])->toBe('news');
    expect($terms['news']['parent_slug'])->toBeNull();
});

it('extracts <category> tags into the post terms array', function () {
    $fixture = __DIR__ . '/../../../testing/Fixtures/small-site.xml';
    $reader = new WxrReader($fixture);

    $firstPost = null;
    foreach ($reader->records() as $r) {
        if ($r['type'] === 'post' && $r['data']['slug'] === 'first-post') {
            $firstPost = $r['data'];
            break;
        }
    }

    expect($firstPost)->not->toBeNull();
    expect($firstPost['terms'])->toEqualCanonicalizing([
        ['taxonomy' => 'category', 'slug' => 'news'],
        ['taxonomy' => 'post_tag', 'slug' => 'php'],
    ]);
});

it('falls back to post_date when post_date_gmt is sentinel', function () {
    $fixture = __DIR__ . '/../../../testing/Fixtures/small-site.xml';
    $reader = new WxrReader($fixture);

    $draft = null;
    foreach ($reader->records() as $r) {
        if ($r['type'] === 'post' && $r['data']['slug'] === 'draft-post') {
            $draft = $r['data'];
            break;
        }
    }

    expect($draft)->not->toBeNull();
    expect($draft['published_at'])->toBe('2025-05-07 09:00:00');
});

it('emits comments under each item with parent_id preserved', function () {
    $fixture = __DIR__ . '/../../../testing/Fixtures/small-site.xml';
    $reader = new WxrReader($fixture);

    $comments = [];
    foreach ($reader->records() as $r) {
        if ($r['type'] === 'comment') {
            $comments[$r['data']['id']] = $r['data'];
        }
    }

    expect($comments)->toHaveCount(4);
    expect($comments[500]['parent_id'])->toBeNull();
    expect($comments[501]['parent_id'])->toBe(500);
    expect($comments[503]['comment_type'])->toBe('pingback');
    // Spam preserved in _extra.approved_raw, approved=false.
    expect($comments[502]['approved'])->toBeFalse();
    expect($comments[502]['_extra']['approved_raw'])->toBe('spam');
});

it('extracts attachment metadata from postmeta', function () {
    $fixture = __DIR__ . '/../../../testing/Fixtures/small-site.xml';
    $reader = new WxrReader($fixture);

    $logo = null;
    foreach ($reader->records() as $r) {
        if ($r['type'] === 'attachment' && $r['data']['slug'] === 'logo') {
            $logo = $r['data'];
            break;
        }
    }

    expect($logo)->not->toBeNull();
    expect($logo['_extra']['postmeta']['_wp_attached_file'])->toBe('2025/05/logo.png');
    expect($logo['_extra']['postmeta']['_wp_attachment_image_alt'])->toBe('Site logo');
});

it('preserves unicode content without corruption', function () {
    $fixture = __DIR__ . '/../../../testing/Fixtures/edge-cases/unicode.xml';
    $reader = new WxrReader($fixture);

    $records = iterator_to_array($reader->records(), false);

    $userJiang = null;
    foreach ($records as $r) {
        if ($r['type'] === 'user' && $r['data']['login'] === 'jiang') {
            $userJiang = $r['data'];
            break;
        }
    }
    expect($userJiang['display_name'])->toBe('蒋小明');

    $post = null;
    foreach ($records as $r) {
        if ($r['type'] === 'post') {
            $post = $r['data'];
            break;
        }
    }
    expect($post['title'])->toContain('日本語のタイトル');
    expect($post['content'])->toContain('🎉🚀');
});

it('captures plugin-namespaced elements as opaque _extra entries', function () {
    $fixture = __DIR__ . '/../../../testing/Fixtures/edge-cases/plugin-namespaces.xml';
    $reader = new WxrReader($fixture);

    $records = iterator_to_array($reader->records(), false);

    $seoPost = null;
    foreach ($records as $r) {
        if ($r['type'] === 'post' && $r['data']['slug'] === 'seo-post') {
            $seoPost = $r['data'];
            break;
        }
    }
    expect($seoPost['_extra'])->toHaveKey('yoast:focus_keyword');
    expect($seoPost['_extra']['yoast:focus_keyword'])->toBe('example keyword');

    $widget = null;
    foreach ($records as $r) {
        if ($r['type'] === 'post' && $r['data']['slug'] === 'widget') {
            $widget = $r['data'];
            break;
        }
    }
    expect($widget['_extra'])->toHaveKey('wc:product_id');
    expect($widget['_extra']['wc:price'])->toBe('19.99');
});

it('skips malformed records with a warning in non-strict mode', function () {
    $fixture = __DIR__ . '/../../../testing/Fixtures/edge-cases/malformed-entries.xml';
    $logger = new CapturingLogger();
    $reader = new WxrReader($fixture, strict: false, logger: $logger);

    $records = iterator_to_array($reader->records(), false);
    $posts = array_filter($records, static fn (array $r): bool => $r['type'] === 'post');

    // libxml's recovery on broken CDATA varies — some libxml versions can
    // resync to the next item, others poison the rest of the parse. Either
    // way, at least one valid post should be yielded AND at least one warning
    // logged for the broken record.
    expect(count($posts))->toBeGreaterThanOrEqual(1);

    $warnings = array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'warning');
    expect(count($warnings))->toBeGreaterThanOrEqual(1);
});

it('rejects unsupported WXR versions on detect', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'wxr');
    file_put_contents($tmp, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/2.0/">
<channel>
    <wp:wxr_version>2.0</wp:wxr_version>
</channel>
</rss>
XML);

    $reader = new WxrReader($tmp);

    expect(fn () => iterator_to_array($reader->records()))
        ->toThrow(WxrParseException::class);

    @unlink($tmp);
});

it('exposes the detected WXR version after at least one read', function () {
    $fixture = __DIR__ . '/../../../testing/Fixtures/small-site.xml';
    $reader = new WxrReader($fixture);

    foreach ($reader->records() as $_) {
        // first record only
        break;
    }

    expect($reader->version())->toBe(WxrVersion::V_1_2);
});
