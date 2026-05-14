<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Testing;

use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

/**
 * In-memory destination plugin used by the package's integration tests.
 *
 * Mirrors M-002's `Waaseyaa\Migration\PluginFixtures\InMemoryDestination`
 * but lives in this package's `testing/` namespace so we do not have to
 * register vendor's autoload-dev path.
 *
 * Idempotent by source-id hash: writing the same SourceId twice replaces
 * the existing WriteResult slot — second-run callers see `lookup()` return
 * the prior result, which is exactly the contract upstream relies on.
 *
 * @api
 */
final class InMemoryDestination implements DestinationPluginInterface
{
    /** @var array<string, WriteResult> Keyed by source-id hash for cheap re-lookup. */
    public array $writes = [];

    /** @var list<array{record: DestinationRecord, hash: string}> Per-call write log, in order. */
    public array $log = [];

    public function __construct(
        private readonly string $id = 'in_memory_dest',
        private readonly string $destinationEntityType = 'in_memory_entity',
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function stability(): string
    {
        return 'stable';
    }

    public function write(DestinationRecord $record): WriteResult
    {
        $hash = $record->sourceId->hash();
        $writeResult = new WriteResult(
            destinationEntityType: $this->destinationEntityType,
            destinationUuid: 'uuid-' . substr($hash, 0, 12),
            sourceRecordHash: hash('sha256', json_encode($record->values, JSON_THROW_ON_ERROR)),
            runId: '019683d3-' . substr($hash, 0, 4) . '-7000-8000-' . substr($hash, 0, 12),
            writtenAt: '2026-05-14T12:00:00Z',
        );

        $this->writes[$hash] = $writeResult;
        $this->log[] = ['record' => $record, 'hash' => $hash];
        return $writeResult;
    }

    public function rollback(WriteResult $result): void
    {
        unset($result);
    }

    public function lookup(SourceId $sourceId): ?WriteResult
    {
        return $this->writes[$sourceId->hash()] ?? null;
    }
}
