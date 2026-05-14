<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Source;

use Waaseyaa\Migrate\Source\WordPress\Exception\WxrParseException;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * Source plugin yielding one {@see SourceRecord} per WordPress taxonomy term.
 *
 * Three WXR element variants (`<wp:category>`, `<wp:tag>`, `<wp:term>`) share
 * the `'term'` record type emitted by {@see WxrReader}; the reader already
 * normalizes all three into a unified field map (data-model §1.2), so this
 * plugin is a thin pass-through.
 *
 * Legacy WXR 1.0/1.1 `<wp:category>` elements may omit `<wp:term_id>`; the
 * reader synthesises a stable id from `crc32($taxonomy . ':' . $slug)`. The
 * `SourceId` derived here therefore stays deterministic across re-imports of
 * the same legacy export.
 *
 * @api
 *
 * @spec FR-009 — WordPress taxonomy source
 * @spec FR-010 FR-011 FR-012 — deterministic SourceId
 */
final class WordPressTaxonomySource implements SourcePluginInterface
{
    public const string SOURCE_TYPE = 'wp_term';
    public const string PLUGIN_ID = 'wordpress_term';

    public function __construct(
        private readonly WxrReader $reader,
        private readonly string $migrationId = self::PLUGIN_ID,
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

    public function count(): ?int
    {
        return null;
    }

    /**
     * @return iterable<SourceRecord>
     *
     * @throws SourceReadException When the WXR file cannot be read or parsed.
     */
    public function records(): iterable
    {
        try {
            foreach ($this->reader->records() as $record) {
                if ($record['type'] !== 'term') {
                    continue;
                }
                yield new SourceRecord(
                    sourceType: self::SOURCE_TYPE,
                    fields: $this->normalize($record['data']),
                );
            }
        } catch (WxrParseException $e) {
            throw new SourceReadException(
                sourceId: self::PLUGIN_ID,
                migrationId: $this->migrationId,
                reason: $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function sourceIdFor(SourceRecord $record): SourceId
    {
        $id = $record->field('id');
        if (!is_int($id) && !is_string($id)) {
            throw new \InvalidArgumentException(\sprintf(
                'WordPressTaxonomySource::sourceIdFor expected scalar id, got %s.',
                \get_debug_type($id),
            ));
        }

        return new SourceId(
            sourceType: self::SOURCE_TYPE,
            keys: ['id' => (string) $id],
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        return [
            'id' => $data['id'] ?? 0,
            'taxonomy_name' => $data['taxonomy_name'] ?? '',
            'name' => $data['name'] ?? '',
            'slug' => $data['slug'] ?? '',
            'description' => $data['description'] ?? null,
            'parent_slug' => $data['parent_slug'] ?? null,
            '_extra' => $data['_extra'] ?? [],
        ];
    }
}
