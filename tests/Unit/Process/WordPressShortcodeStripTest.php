<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Process;

use Waaseyaa\Migrate\Source\WordPress\Process\WordPressShortcodeStrip;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\SourceRecord;

/**
 * @internal
 */
function shortcodeContext(): ProcessContext
{
    return new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', ['id' => 1]),
        migrationId: 'wp_posts',
        destinationField: 'body',
        lookup: static fn (string $m, $id) => null,
    );
}

it('declares plugin metadata', function () {
    $plugin = new WordPressShortcodeStrip();
    expect($plugin->id())->toBe('wordpress_shortcode_strip');
    expect($plugin->stability())->toBe('stable');
});

it('returns non-string values unchanged', function () {
    $plugin = new WordPressShortcodeStrip();
    expect($plugin->transform(42, shortcodeContext()))->toBe(42);
    expect($plugin->transform(null, shortcodeContext()))->toBeNull();
    expect($plugin->transform(['a'], shortcodeContext()))->toBe(['a']);
});

it('strips a self-closing shortcode silently', function () {
    $plugin = new WordPressShortcodeStrip();
    $out = $plugin->transform('Before [gallery ids="1,2,3"] after', shortcodeContext());
    expect($out)->toBe('Before  after');
});

it('strips a paired shortcode preserving inner text', function () {
    $plugin = new WordPressShortcodeStrip();
    $out = $plugin->transform('A [caption align="left"]inner caption[/caption] B', shortcodeContext());
    expect($out)->toBe('A inner caption B');
});

it('invokes the registered rewriter for a known tag', function () {
    $plugin = new WordPressShortcodeStrip([
        'youtube' => static fn (string $tag, array $attrs, string $inner): string =>
            sprintf('<iframe src="https://www.youtube.com/embed/%s"></iframe>', $attrs['id'] ?? ''),
    ]);

    $out = $plugin->transform('See [youtube id="abc123"] for details.', shortcodeContext());
    expect($out)->toBe('See <iframe src="https://www.youtube.com/embed/abc123"></iframe> for details.');
});

it('passes parsed attrs to the rewriter (double and single quotes)', function () {
    $captured = null;
    $plugin = new WordPressShortcodeStrip([
        'box' => static function (string $tag, array $attrs, string $inner) use (&$captured): string {
            $captured = $attrs;
            return '';
        },
    ]);

    $plugin->transform("[box type='warning' size=\"large\"]Body[/box]", shortcodeContext());
    expect($captured)->toBe(['type' => 'warning', 'size' => 'large']);
});

it('handles nested shortcodes via depth-limited recursion', function () {
    $plugin = new WordPressShortcodeStrip();
    $out = $plugin->transform('[outer]some [inner]nested[/inner] text[/outer]', shortcodeContext());
    expect($out)->toBe('some nested text');
});

it('is case-insensitive on tag matching', function () {
    $plugin = new WordPressShortcodeStrip(['box' => static fn () => 'BOX']);
    $out = $plugin->transform('[BOX]ignored[/BOX]', shortcodeContext());
    expect($out)->toBe('BOX');
});

it('leaves unmatched closing tags alone (well-formed-only contract)', function () {
    $plugin = new WordPressShortcodeStrip();
    $out = $plugin->transform('text [/dangling] more', shortcodeContext());
    expect($out)->toBe('text [/dangling] more');
});
