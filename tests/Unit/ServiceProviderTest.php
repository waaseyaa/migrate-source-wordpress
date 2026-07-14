<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit;

use Waaseyaa\Migrate\Source\WordPress\ServiceProvider;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;

it('registers as a migration provider without the retired plugin-discovery interface', function () {
    $provider = new ServiceProvider();

    expect($provider)->toBeInstanceOf(HasMigrationsInterface::class);
    expect(iterator_to_array($provider->migrations()))->toBe([]);
    $methods = array_map(
        static fn (\ReflectionMethod $method): string => $method->getName(),
        (new \ReflectionClass($provider))->getMethods(),
    );
    expect($methods)->not->toContain('migrationPlugins');
});
