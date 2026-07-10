<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Process;

use Waaseyaa\Migrate\Source\WordPress\Process\WordPressPostmetaExtract;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\SourceRecord;

/**
 * @internal
 */
function postmetaContext(): ProcessContext
{
    return new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', ['id' => 1]),
        migrationId: 'wp_events_to_nodes',
        destinationField: 'event_start',
        lookup: static fn (string $m, $id) => null,
    );
}

it('declares plugin metadata', function () {
    $plugin = new WordPressPostmetaExtract('_EventStartDate');
    expect($plugin->id())->toBe('wordpress_postmeta_extract');
    expect($plugin->stability())->toBe('stable');
});

it('extracts the named postmeta key from an _extra array', function () {
    $plugin = new WordPressPostmetaExtract('_EventStartDate');
    $extra = ['postmeta' => ['_EventStartDate' => '2024-02-27 17:00:00', '_EventEndDate' => '2024-02-27 19:00:00']];

    expect($plugin->transform($extra, postmetaContext()))->toBe('2024-02-27 17:00:00');
});

it('returns the default when the postmeta key is absent', function () {
    $plugin = new WordPressPostmetaExtract('_EventStartDate', 'unset');
    $extra = ['postmeta' => ['_EventEndDate' => '2024-02-27 19:00:00']];

    expect($plugin->transform($extra, postmetaContext()))->toBe('unset');
});

it('returns null by default when the postmeta key is absent and no default given', function () {
    $plugin = new WordPressPostmetaExtract('_EventStartDate');
    $extra = ['postmeta' => []];

    expect($plugin->transform($extra, postmetaContext()))->toBeNull();
});

it('returns the default when the _extra array has no postmeta slot at all', function () {
    $plugin = new WordPressPostmetaExtract('_EventStartDate', 'fallback');

    expect($plugin->transform([], postmetaContext()))->toBe('fallback');
    expect($plugin->transform(['wp:some_field' => 'x'], postmetaContext()))->toBe('fallback');
});

it('returns the default when postmeta is present but not an array (odd shape)', function () {
    $plugin = new WordPressPostmetaExtract('_EventStartDate', 'fallback');

    expect($plugin->transform(['postmeta' => 'not-an-array'], postmetaContext()))->toBe('fallback');
});

it('returns the default when the incoming value is not an array at all', function () {
    $plugin = new WordPressPostmetaExtract('_EventStartDate', 'fallback');

    expect($plugin->transform(null, postmetaContext()))->toBe('fallback');
    expect($plugin->transform('not-an-array', postmetaContext()))->toBe('fallback');
    expect($plugin->transform(42, postmetaContext()))->toBe('fallback');
});

it('tolerates a postmeta value that is itself null', function () {
    $plugin = new WordPressPostmetaExtract('_EventStartDate', 'fallback');
    $extra = ['postmeta' => ['_EventStartDate' => null]];

    // The key is present but its value is null — that IS the stored value,
    // not "absent", so we return null rather than the default.
    expect($plugin->transform($extra, postmetaContext()))->toBeNull();
});
