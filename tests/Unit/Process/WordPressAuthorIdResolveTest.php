<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Process;

use Waaseyaa\Migrate\Source\WordPress\Process\WordPressAuthorIdResolve;
use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\SourceRecord;

/**
 * @internal
 */
function authorContext(array $fields = ['author_login' => 'admin']): ProcessContext
{
    return new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', $fields),
        migrationId: 'wp_posts_to_articles',
        destinationField: 'uid',
        lookup: static fn (string $m, $id) => null,
    );
}

it('declares plugin metadata', function () {
    $plugin = new WordPressAuthorIdResolve([]);
    expect($plugin->id())->toBe('wordpress_author_id_resolve');
    expect($plugin->stability())->toBe('stable');
});

it('resolves a login to its wp:author_id via the chained value', function () {
    $plugin = new WordPressAuthorIdResolve(['admin' => 1, 'jane' => 2]);
    expect($plugin->transform('admin', authorContext()))->toBe('1');
});

it('always returns a string id, even when the index stores an int (LookupProcessor type-stability contract)', function () {
    $plugin = new WordPressAuthorIdResolve(['admin' => 1]);
    $result = $plugin->transform('admin', authorContext());
    expect($result)->toBeString();
    expect($result)->toBe('1');
});

it('reads the source field directly when the chain has no upstream value', function () {
    $plugin = new WordPressAuthorIdResolve(['admin' => 1]);
    expect($plugin->transform(null, authorContext(['author_login' => 'admin'])))->toBe('1');
});

it('returns null for an unknown login when onMiss is null (default)', function () {
    $plugin = new WordPressAuthorIdResolve(['admin' => 1]);
    expect($plugin->transform('ghost', authorContext()))->toBeNull();
});

it('throws ProcessException for an unknown login when onMiss is fail', function () {
    $plugin = new WordPressAuthorIdResolve(['admin' => 1], onMiss: 'fail');
    expect(fn () => $plugin->transform('ghost', authorContext()))->toThrow(ProcessException::class);
});

it('returns null for an empty/missing login', function () {
    $plugin = new WordPressAuthorIdResolve(['admin' => 1]);
    expect($plugin->transform(null, authorContext(['author_login' => ''])))->toBeNull();
});

it('rejects an empty sourceField', function () {
    expect(fn () => new WordPressAuthorIdResolve([], sourceField: ''))->toThrow(\InvalidArgumentException::class);
});

it('rejects an unrecognised onMiss value', function () {
    expect(fn () => new WordPressAuthorIdResolve([], onMiss: 'bogus'))->toThrow(\InvalidArgumentException::class);
});
