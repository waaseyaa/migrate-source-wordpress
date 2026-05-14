<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Process;

use Psr\Log\AbstractLogger;
use Stringable;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressMediaRewriteUrl;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\SourceRecord;

/**
 * @internal
 */
final class RewriteCapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

/**
 * @internal
 */
function rewriteContext(): ProcessContext
{
    return new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', ['id' => 1]),
        migrationId: 'wp_posts',
        destinationField: 'body',
        lookup: static fn (string $m, $id) => null,
    );
}

it('declares plugin metadata', function () {
    $plugin = new WordPressMediaRewriteUrl(static fn () => null);
    expect($plugin->id())->toBe('wordpress_media_rewrite_url');
    expect($plugin->stability())->toBe('stable');
});

it('rewrites canonical wp-content/uploads URL via the resolver', function () {
    $plugin = new WordPressMediaRewriteUrl(
        static fn (string $rel): ?string => $rel === '/wp-content/uploads/2024/01/photo.jpg'
            ? 'https://destination.test/media/photo.jpg'
            : null,  // this branch only — null is reachable here
    );

    $input = '<img src="https://example.com/wp-content/uploads/2024/01/photo.jpg" />';
    $out = $plugin->transform($input, rewriteContext());
    expect($out)->toBe('<img src="https://destination.test/media/photo.jpg" />');
});

it('rewrites a CDN-prefixed URL when the host is allowlisted', function () {
    $plugin = new WordPressMediaRewriteUrl(
        urlResolver: static fn (string $rel) => 'https://destination.test' . $rel,
        cdnHosts: ['cdn.example.com'],
    );

    $input = '<img src="https://cdn.example.com/wp-content/uploads/2024/01/banner.jpg" />';
    $out = $plugin->transform($input, rewriteContext());
    expect($out)->toBe('<img src="https://destination.test/wp-content/uploads/2024/01/banner.jpg" />');
});

it('leaves non-allowlisted CDN URLs untouched', function () {
    $plugin = new WordPressMediaRewriteUrl(
        urlResolver: static fn () => 'https://destination.test/replaced',
        cdnHosts: ['cdn.example.com'],
    );

    $input = '<img src="https://other.cdn.example.org/wp-content/uploads/2024/01/photo.jpg" />';
    $out = $plugin->transform($input, rewriteContext());
    expect($out)->toBe($input);
});

it('rewrites any host when cdnHosts is empty (rewrite-everywhere mode)', function () {
    $plugin = new WordPressMediaRewriteUrl(
        static fn (string $rel) => 'https://destination.test' . $rel,
    );

    $input = '<img src="https://random.example/wp-content/uploads/2024/02/x.png" />';
    $out = $plugin->transform($input, rewriteContext());
    expect($out)->toBe('<img src="https://destination.test/wp-content/uploads/2024/02/x.png" />');
});

it('logs a warning when the resolver returns null and leaves URL unchanged', function () {
    $logger = new RewriteCapturingLogger();
    $plugin = new WordPressMediaRewriteUrl(
        urlResolver: static fn (): ?string => null,
        logger: $logger,
    );

    $input = '<img src="https://example.com/wp-content/uploads/2024/01/orphan.png" />';
    $out = $plugin->transform($input, rewriteContext());

    expect($out)->toBe($input);
    expect($logger->records)->not->toBeEmpty();
    expect($logger->records[0]['level'])->toBe('warning');
});

it('handles host-less /wp-content/uploads/ references', function () {
    $plugin = new WordPressMediaRewriteUrl(
        static fn (string $rel) => 'https://destination.test' . $rel,
    );

    $input = '<img src="/wp-content/uploads/2024/01/img.png" />';
    $out = $plugin->transform($input, rewriteContext());
    expect($out)->toBe('<img src="https://destination.test/wp-content/uploads/2024/01/img.png" />');
});

it('returns non-string values unchanged', function () {
    $plugin = new WordPressMediaRewriteUrl(static fn () => null);
    expect($plugin->transform(42, rewriteContext()))->toBe(42);
    expect($plugin->transform(null, rewriteContext()))->toBeNull();
});

it('is case-insensitive on CDN host matching', function () {
    $plugin = new WordPressMediaRewriteUrl(
        urlResolver: static fn (string $rel) => 'https://destination.test' . $rel,
        cdnHosts: ['cdn.example.com'],
    );

    $input = '<img src="https://CDN.Example.COM/wp-content/uploads/2024/01/x.jpg" />';
    $out = $plugin->transform($input, rewriteContext());
    expect($out)->toBe('<img src="https://destination.test/wp-content/uploads/2024/01/x.jpg" />');
});

it('chains cleanly with WordPressShortcodeStrip and WordPressOembedExpand', function () {
    $context = rewriteContext();
    $input = '<p>[gallery ids="1,2"]</p><p>https://www.youtube.com/watch?v=abc</p><p><img src="https://example.com/wp-content/uploads/2024/01/photo.jpg"></p>';

    $stripped = (new \Waaseyaa\Migrate\Source\WordPress\Process\WordPressShortcodeStrip())->transform($input, $context);
    $expanded = (new \Waaseyaa\Migrate\Source\WordPress\Process\WordPressOembedExpand(resolveRemote: false))->transform($stripped, $context);
    $rewritten = (new WordPressMediaRewriteUrl(
        static fn (string $rel) => 'https://destination.test/media' . $rel,
    ))->transform($expanded, $context);

    expect($rewritten)->not->toContain('[gallery');
    expect($rewritten)->toContain('https://destination.test/media/wp-content/uploads/2024/01/photo.jpg');
});
