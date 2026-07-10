<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;

/**
 * Resolves a destination-entity uuid (typically the output of a chained
 * {@see \Waaseyaa\Migration\Plugin\Process\LookupProcessor}) into a
 * destination *system path* string, e.g. `/node/42`.
 *
 * `LookupProcessor` (and the id-map behind it) only ever hands back a
 * {@see \Waaseyaa\Migration\Plugin\WriteResult::$destinationUuid} — a
 * UUIDv7 string, not the integer/serial id most system-path schemes
 * (`waaseyaa/path`'s `PathAlias::$path`, e.g. `/node/42`) actually need.
 * Bridging uuid → id is application-specific (it depends on how the
 * destination entity storage exposes its serial id), so the bridge is a
 * `\Closure` seam the operator supplies — exactly the same seam shape
 * {@see WordPressMediaRewriteUrl} uses for its uuid→URL resolver.
 *
 * A `null` chained value (upstream `LookupProcessor` miss) short-circuits
 * without invoking the resolver at all — there is nothing to resolve.  A
 * resolver miss (closure returns `null`) is logged as a warning and also
 * returns `null`, mirroring {@see WordPressMediaRewriteUrl}'s
 * leave-untouched-and-warn miss policy.
 *
 * @api
 *
 * @spec G-020 — path-alias `path` field (destination system path) resolution
 */
final class UuidToSystemPathProcessor implements ProcessPluginInterface
{
    public const string PLUGIN_ID = 'wordpress_uuid_to_system_path';

    /**
     * @param \Closure(string $entityType, string $uuid): (int|string|null) $uuidToId Resolves a destination
     *     entity's uuid to its serial/system id. Return null when the uuid is unknown.
     * @param string $entityType Destination entity type passed to the resolver (e.g. `node`).
     * @param string $prefix System-path prefix the resolved id is appended to (e.g. `/node/`).
     */
    public function __construct(
        private readonly \Closure $uuidToId,
        private readonly string $entityType,
        private readonly string $prefix = '/node/',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function id(): string
    {
        return self::PLUGIN_ID;
    }

    public function stability(): string
    {
        return 'stable';
    }

    public function transform(mixed $value, ProcessContext $context): mixed
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $resolvedId = ($this->uuidToId)($this->entityType, $value);
        if ($resolvedId === null) {
            $this->logger->warning('No destination id for uuid; path alias not built.', [
                'uuid' => $value,
                'entity_type' => $this->entityType,
                'migration_id' => $context->migrationId,
            ]);
            return null;
        }

        return $this->prefix . $resolvedId;
    }
}
