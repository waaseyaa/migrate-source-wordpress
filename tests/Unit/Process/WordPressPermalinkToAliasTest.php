<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Process;

use Waaseyaa\Migrate\Source\WordPress\Process\WordPressPermalinkToAlias;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\SourceRecord;

/**
 * @internal
 */
function permalinkContext(): ProcessContext
{
    return new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', ['id' => 1]),
        migrationId: 'wp_posts_to_path_aliases',
        destinationField: 'alias',
        lookup: static fn (string $m, $id) => null,
    );
}

it('declares plugin metadata', function () {
    $plugin = new WordPressPermalinkToAlias();
    expect($plugin->id())->toBe('wordpress_permalink_to_alias');
    expect($plugin->stability())->toBe('stable');
});

it('normalizes a nested hierarchical permalink, stripping domain and trailing slash', function () {
    $plugin = new WordPressPermalinkToAlias();
    $out = $plugin->transform(['link' => 'https://example.test/members/rht/'], permalinkContext());
    expect($out)->toBe('/members/rht');
});

it('normalizes a bare single-segment permalink', function () {
    $plugin = new WordPressPermalinkToAlias();
    $out = $plugin->transform(['link' => 'https://example.test/about/'], permalinkContext());
    expect($out)->toBe('/about');
});

it('preserves a path with no trailing slash as-is', function () {
    $plugin = new WordPressPermalinkToAlias();
    $out = $plugin->transform(['link' => 'https://example.test/2025/05/first-post'], permalinkContext());
    expect($out)->toBe('/2025/05/first-post');
});

it('returns null for a querystring-only "plain" permalink (?p=100)', function () {
    $plugin = new WordPressPermalinkToAlias();
    $out = $plugin->transform(['link' => 'https://example.test/?p=102'], permalinkContext());
    expect($out)->toBeNull();
});

it('returns null for a querystring-only custom-post-type permalink (?project=104)', function () {
    $plugin = new WordPressPermalinkToAlias();
    $out = $plugin->transform(['link' => 'https://example.test/?project=104'], permalinkContext());
    expect($out)->toBeNull();
});

it('returns null for the bare homepage link', function () {
    $plugin = new WordPressPermalinkToAlias();
    $out = $plugin->transform(['link' => 'https://example.test'], permalinkContext());
    expect($out)->toBeNull();
});

it('drops a query string appended to a real path', function () {
    $plugin = new WordPressPermalinkToAlias();
    $out = $plugin->transform(['link' => 'https://example.test/members/rht/?utm_source=x'], permalinkContext());
    expect($out)->toBe('/members/rht');
});

it('percent-decodes non-ASCII path segments', function () {
    $plugin = new WordPressPermalinkToAlias();
    $out = $plugin->transform(['link' => 'https://example.test/%E6%97%A5%E6%9C%AC%E8%AA%9E/'], permalinkContext());
    expect($out)->toBe('/日本語');
});

it('accepts a bare link string as well as an _extra array', function () {
    $plugin = new WordPressPermalinkToAlias();
    $out = $plugin->transform('https://example.test/about/', permalinkContext());
    expect($out)->toBe('/about');
});

it('returns null when _extra has no link key', function () {
    $plugin = new WordPressPermalinkToAlias();
    $out = $plugin->transform(['postmeta' => []], permalinkContext());
    expect($out)->toBeNull();
});

it('returns null for a non-string, non-array value', function () {
    $plugin = new WordPressPermalinkToAlias();
    expect($plugin->transform(null, permalinkContext()))->toBeNull();
    expect($plugin->transform(42, permalinkContext()))->toBeNull();
});
