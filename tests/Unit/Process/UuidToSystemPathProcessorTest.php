<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Process;

use Psr\Log\AbstractLogger;
use Stringable;
use Waaseyaa\Migrate\Source\WordPress\Process\UuidToSystemPathProcessor;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\SourceRecord;

/**
 * @internal
 */
final class SystemPathCapturingLogger extends AbstractLogger
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
function systemPathContext(): ProcessContext
{
    return new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', ['id' => 1]),
        migrationId: 'wp_posts_to_path_aliases',
        destinationField: 'path',
        lookup: static fn (string $m, $id) => null,
    );
}

it('declares plugin metadata', function () {
    $plugin = new UuidToSystemPathProcessor(static fn () => null, 'node');
    expect($plugin->id())->toBe('wordpress_uuid_to_system_path');
    expect($plugin->stability())->toBe('stable');
});

it('resolves a uuid to a prefixed system path', function () {
    $plugin = new UuidToSystemPathProcessor(
        static fn (string $entityType, string $uuid): ?int => $entityType === 'node' && $uuid === 'uuid-abc' ? 42 : null,
        'node',
    );

    $out = $plugin->transform('uuid-abc', systemPathContext());
    expect($out)->toBe('/node/42');
});

it('honors a custom prefix', function () {
    $plugin = new UuidToSystemPathProcessor(
        static fn (string $entityType, string $uuid): int => 7,
        'article',
        prefix: '/article/',
    );

    $out = $plugin->transform('uuid-xyz', systemPathContext());
    expect($out)->toBe('/article/7');
});

it('returns null and does not call the resolver when the chained uuid is null', function () {
    $called = false;
    $plugin = new UuidToSystemPathProcessor(
        static function () use (&$called): int {
            $called = true;
            return 99;
        },
        'node',
    );

    $out = $plugin->transform(null, systemPathContext());
    expect($out)->toBeNull();
    expect($called)->toBeFalse();
});

it('returns null and logs a warning when the uuid-to-id closure misses', function () {
    $logger = new SystemPathCapturingLogger();
    $plugin = new UuidToSystemPathProcessor(
        static fn (): int|string|null => null,
        'node',
        logger: $logger,
    );

    $out = $plugin->transform('uuid-orphan', systemPathContext());

    expect($out)->toBeNull();
    expect($logger->records)->not->toBeEmpty();
    expect($logger->records[0]['level'])->toBe('warning');
});

it('returns null for a non-string value', function () {
    $plugin = new UuidToSystemPathProcessor(static fn () => 1, 'node');
    expect($plugin->transform(42, systemPathContext()))->toBeNull();
});
