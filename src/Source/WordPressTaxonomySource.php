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
     * Build a `"{taxonomy}:{slug}" → wp:term_id` index from every term
     * element in the WXR document.
     *
     * Posts (`terms`) and terms (`parent_slug`) reference other terms by
     * slug within a taxonomy, but this source's `SourceId` is keyed by the
     * numeric `wp:term_id` (or the legacy `crc32()`-derived id the reader
     * synthesises for `<wp:category>` elements without one). The taxonomy is
     * part of the key because slugs are only unique within a taxonomy — a
     * category and a tag may legitimately share a slug. This index is the
     * bridge `Process\WordPressTermsResolve` and
     * `Process\WordPressTermParentResolve` need to convert a slug reference
     * into a value a standard id-map lookup can key against (G-019).
     *
     * Reads the reader once; callers building both a terms migration and a
     * slug index should construct a fresh `WxrReader` for each pass
     * (streaming readers are not re-entrant / rewindable mid-stream).
     *
     * @return array<string, int|string> Keyed by `"{taxonomy}:{slug}"`; duplicate keys keep the first `wp:term_id` seen.
     *
     * @throws \Waaseyaa\Migration\Exception\SourceReadException When the WXR file cannot be read or parsed.
     */
    public static function slugIndex(WxrReader $reader): array
    {
        $index = [];
        foreach ((new self($reader))->records() as $record) {
            $taxonomy = $record->field('taxonomy_name');
            $slug = $record->field('slug');
            $id = $record->field('id');
            if (!is_string($taxonomy) || !is_string($slug) || $taxonomy === '' || $slug === '') {
                continue;
            }
            $key = $taxonomy . ':' . $slug;
            if (isset($index[$key])) {
                continue;
            }
            if (is_int($id) || is_string($id)) {
                $index[$key] = $id;
            }
        }

        return $index;
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
