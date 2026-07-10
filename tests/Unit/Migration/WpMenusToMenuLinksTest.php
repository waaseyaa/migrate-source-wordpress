<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Migration;

use Waaseyaa\Migrate\Source\WordPress\Migration\WpMenusToMenuLinks;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressMenuSource;
use Waaseyaa\Migrate\Source\WordPress\Testing\InMemoryDestination;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;

function makeMenusToMenuLinksFactory(): WpMenusToMenuLinks
{
    $reader = new WxrReader(__DIR__ . '/../../../testing/Fixtures/menus.xml');

    return new WpMenusToMenuLinks($reader, new InMemoryDestination());
}

it('declares a migration definition with the expected id and source', function () {
    $definition = makeMenusToMenuLinksFactory()->definition();

    expect($definition)->toBeInstanceOf(MigrationDefinition::class);
    expect($definition->id)->toBe('wp_menus_to_menu_links');
    expect($definition->source)->toBeInstanceOf(WordPressMenuSource::class);
});

it('maps title, url, menu_name, weight, and enabled through the process map', function () {
    $definition = makeMenusToMenuLinksFactory()->definition();

    expect($definition->processForField('title'))->toBe(['title']);
    expect($definition->processForField('url'))->toBe(['url']);
    expect($definition->processForField('menu_name'))->toBe(['menu_name']);
    expect($definition->processForField('weight'))->toBe(['weight']);
    expect($definition->processForField('enabled'))->toBe(['enabled']);
});

it('does not map parent_id — parent resolution is app-side wiring', function () {
    $definition = makeMenusToMenuLinksFactory()->definition();

    expect(fn () => $definition->processForField('parent_id'))
        ->toThrow(\OutOfBoundsException::class);
});
