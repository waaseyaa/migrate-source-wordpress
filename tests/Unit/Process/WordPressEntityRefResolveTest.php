<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Process;

use Waaseyaa\Migrate\Source\WordPress\Process\WordPressEntityRefResolve;
use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\SourceRecord;

/**
 * @internal
 */
function refResolveContext(): ProcessContext
{
    return new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', ['id' => 1]),
        migrationId: 'wp_posts_to_articles',
        destinationField: 'uid',
        lookup: static fn (string $m, $id) => null,
    );
}

it('declares plugin metadata', function () {
    $plugin = new WordPressEntityRefResolve(static fn () => null, 'account');
    expect($plugin->id())->toBe('wordpress_entity_ref_resolve');
    expect($plugin->stability())->toBe('stable');
});

it('resolves a destination uuid to a storage id via the resolver closure', function () {
    $plugin = new WordPressEntityRefResolve(
        resolver: static fn (string $type, string $uuid) => $type === 'account' && $uuid === 'uuid-abc' ? 42 : null,
        destinationEntityType: 'account',
    );

    expect($plugin->transform('uuid-abc', refResolveContext()))->toBe(42);
});

it('passes the destination entity type through to the resolver verbatim', function () {
    $seen = [];
    $plugin = new WordPressEntityRefResolve(
        resolver: function (string $type, string $uuid) use (&$seen) {
            $seen[] = [$type, $uuid];
            return 'storage-id';
        },
        destinationEntityType: 'taxonomy_term',
    );

    $plugin->transform('uuid-xyz', refResolveContext());
    expect($seen)->toBe([['taxonomy_term', 'uuid-xyz']]);
});

it('returns null when the chained value is null and onMiss is null (default)', function () {
    $plugin = new WordPressEntityRefResolve(static fn () => 'unreachable', 'account');
    expect($plugin->transform(null, refResolveContext()))->toBeNull();
});

it('returns null when the chained value is an empty string', function () {
    $plugin = new WordPressEntityRefResolve(static fn () => 'unreachable', 'account');
    expect($plugin->transform('', refResolveContext()))->toBeNull();
});

it('returns null when the resolver itself returns null', function () {
    $plugin = new WordPressEntityRefResolve(static fn () => null, 'account');
    expect($plugin->transform('uuid-abc', refResolveContext()))->toBeNull();
});

it('throws ProcessException on miss when onMiss is fail', function () {
    $plugin = new WordPressEntityRefResolve(static fn () => null, 'account', onMiss: 'fail');

    expect(fn () => $plugin->transform('uuid-abc', refResolveContext()))
        ->toThrow(ProcessException::class);
});

it('throws ProcessException on a null chained value when onMiss is fail', function () {
    $plugin = new WordPressEntityRefResolve(static fn () => 'unreachable', 'account', onMiss: 'fail');

    expect(fn () => $plugin->transform(null, refResolveContext()))
        ->toThrow(ProcessException::class);
});

it('rejects an empty destinationEntityType', function () {
    expect(fn () => new WordPressEntityRefResolve(static fn () => null, ''))
        ->toThrow(\InvalidArgumentException::class);
});

it('rejects an unrecognised onMiss value', function () {
    expect(fn () => new WordPressEntityRefResolve(static fn () => null, 'account', onMiss: 'bogus'))
        ->toThrow(\InvalidArgumentException::class);
});
