<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Migration;

use Waaseyaa\Migrate\Source\WordPress\Migration\WpEventsToNodes;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpMediaToEntities;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpOrganizersToNodes;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpTermsToTaxonomy;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpUsersToAccounts;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpVenuesToNodes;
use Waaseyaa\Migrate\Source\WordPress\Testing\InMemoryDestination;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\SourceRecord;

const EVENTS_FIXTURE = __DIR__ . '/../../../testing/Fixtures/events-fixture.xml';

/**
 * @internal
 */
function driveEventsMigration(MigrationDefinition $def, InMemoryDestination $dest): void
{
    foreach ($def->source->records() as $sourceRecord) {
        $sourceId = $def->source->sourceIdFor($sourceRecord);
        $values = [];
        foreach ($def->process as $destinationField => $entry) {
            $values[$destinationField] = resolveEventsField($entry, $sourceRecord, $def->id, $destinationField);
        }
        $dest->write(new DestinationRecord(migrationId: $def->id, sourceId: $sourceId, values: $values));
    }
}

/**
 * @internal
 */
function resolveEventsField(mixed $entry, SourceRecord $record, string $migrationId, string $destinationField): mixed
{
    if (is_string($entry)) {
        return $record->field($entry);
    }
    if ($entry instanceof ProcessPluginInterface) {
        return $entry->transform(null, eventsContext($record, $migrationId, $destinationField));
    }
    if (is_array($entry)) {
        $value = null;
        $context = eventsContext($record, $migrationId, $destinationField);
        foreach ($entry as $step) {
            if (is_string($step)) {
                $value = $record->field($step);
                continue;
            }
            if ($step instanceof ProcessPluginInterface) {
                $value = $step->transform($value, $context);
            }
        }
        return $value;
    }
    return null;
}

/**
 * @internal
 */
function eventsContext(SourceRecord $record, string $migrationId, string $destinationField): ProcessContext
{
    return new ProcessContext(
        sourceRecord: $record,
        migrationId: $migrationId,
        destinationField: $destinationField,
        lookup: static fn (string $m, $id) => null,
    );
}

it('builds a well-formed migration definition with dependencies', function () {
    $reader = new WxrReader(EVENTS_FIXTURE);
    $def = (new WpEventsToNodes($reader, new InMemoryDestination()))->definition();

    expect($def->id)->toBe('wp_events_to_nodes');
    expect($def->dependencies)->toBe([
        WpUsersToAccounts::MIGRATION_ID,
        WpTermsToTaxonomy::MIGRATION_ID,
        WpMediaToEntities::MIGRATION_ID,
    ]);
    expect($def->process)->toHaveKeys(['title', 'slug', 'content', 'excerpt', 'event_start', 'event_end']);
});

it('extracts _EventStartDate and _EventEndDate from postmeta into node fields', function () {
    $reader = new WxrReader(EVENTS_FIXTURE);
    $dest = new InMemoryDestination();
    $def = (new WpEventsToNodes($reader, $dest))->definition();

    driveEventsMigration($def, $dest);

    expect($dest->log)->toHaveCount(1);
    $values = $dest->log[0]['record']->values;
    expect($values['title'])->toBe('Community Meeting');
    expect($values['event_start'])->toBe('2024-02-27 17:00:00');
    expect($values['event_end'])->toBe('2024-02-27 19:00:00');
});

it('extracts organizer contact postmeta', function () {
    $reader = new WxrReader(EVENTS_FIXTURE);
    $dest = new InMemoryDestination();
    $def = (new WpOrganizersToNodes($reader, $dest))->definition();

    driveEventsMigration($def, $dest);

    expect($dest->log)->toHaveCount(1);
    $values = $dest->log[0]['record']->values;
    expect($values['title'])->toBe('UCCMM');
    expect($values['email'])->toBe('info@uccmm.example.test');
    expect($values['phone'])->toBe('705-555-0101');
    expect($values['website'])->toBe('https://uccmm.example.test');
});

it('extracts venue address postmeta', function () {
    $reader = new WxrReader(EVENTS_FIXTURE);
    $dest = new InMemoryDestination();
    $def = (new WpVenuesToNodes($reader, $dest))->definition();

    driveEventsMigration($def, $dest);

    expect($dest->log)->toHaveCount(1);
    $values = $dest->log[0]['record']->values;
    expect($values['title'])->toBe('Community Hall');
    expect($values['address'])->toBe('142 Ogemah Miikan');
    expect($values['city'])->toBe('Example First Nation');
    expect($values['province'])->toBe('Ontario');
    expect($values['country'])->toBe('Canada');
});

it('does not leak events/organizers/venues into each other\'s destinations', function () {
    $reader = new WxrReader(EVENTS_FIXTURE);

    $eventsDest = new InMemoryDestination();
    $organizersDest = new InMemoryDestination();
    $venuesDest = new InMemoryDestination();

    driveEventsMigration((new WpEventsToNodes($reader, $eventsDest))->definition(), $eventsDest);
    driveEventsMigration((new WpOrganizersToNodes(new WxrReader(EVENTS_FIXTURE), $organizersDest))->definition(), $organizersDest);
    driveEventsMigration((new WpVenuesToNodes(new WxrReader(EVENTS_FIXTURE), $venuesDest))->definition(), $venuesDest);

    expect($eventsDest->log)->toHaveCount(1);
    expect($organizersDest->log)->toHaveCount(1);
    expect($venuesDest->log)->toHaveCount(1);
});
