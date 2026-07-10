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
 * Source plugin yielding one {@see SourceRecord} per WordPress user.
 *
 * Iterates {@see WxrReader} records, filters to `type === 'user'`, and
 * normalizes each user's data into a stable field map per data-model §1.1.
 * The `registered` field is normalized from WP's MySQL `Y-m-d H:i:s` shape
 * into ISO 8601 (`Y-m-d\TH:i:sP`) for cross-source consistency.
 *
 * `SourceId` is derived from `('wp_user', ['id' => (string)$id])`; the string
 * cast keeps the hash stable across PHP int/string coercion.
 *
 * @api
 *
 * @spec FR-006 — WordPress user source
 * @spec FR-010 FR-011 FR-012 — deterministic SourceId
 */
final class WordPressUserSource implements SourcePluginInterface
{
    public const string SOURCE_TYPE = 'wp_user';
    public const string PLUGIN_ID = 'wordpress_user';

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
                if ($record['type'] !== 'user') {
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
                'WordPressUserSource::sourceIdFor expected scalar id, got %s.',
                \get_debug_type($id),
            ));
        }

        return new SourceId(
            sourceType: self::SOURCE_TYPE,
            keys: ['id' => (string) $id],
        );
    }

    /**
     * Build a `login → wp:author_id` index from every `<wp:author>` element
     * in the WXR document.
     *
     * Posts carry authorship as `dc:creator` — a login string — but this
     * source's `SourceId` is keyed by the numeric `wp:author_id`
     * (data-model §1.1). This index is the bridge a
     * `Process\WordPressAuthorIdResolve` chain step needs to convert a
     * post's `author_login` into a value a standard `LookupProcessor` can
     * key against (G-019).
     *
     * Reads the reader once, independent of and in addition to any other
     * iteration over it — callers building both a users migration and an
     * author index should construct a fresh `WxrReader` for each pass
     * (streaming readers are not re-entrant / rewindable mid-stream).
     *
     * @return array<string, int|string> Keyed by login; duplicate logins keep the first `wp:author_id` seen.
     *
     * @throws \Waaseyaa\Migration\Exception\SourceReadException When the WXR file cannot be read or parsed.
     */
    public static function loginIndex(WxrReader $reader): array
    {
        $index = [];
        foreach ((new self($reader))->records() as $record) {
            $login = $record->field('login');
            $id = $record->field('id');
            if (!is_string($login) || $login === '' || isset($index[$login])) {
                continue;
            }
            if (is_int($id) || is_string($id)) {
                $index[$login] = $id;
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
        $registered = $data['registered'] ?? null;
        if (is_string($registered) && $registered !== '') {
            $registered = $this->toIso8601($registered);
        } else {
            $registered = null;
        }

        return [
            'id' => $data['id'] ?? 0,
            'login' => $data['login'] ?? '',
            'email' => $data['email'] ?? '',
            'display_name' => $data['display_name'] ?? ($data['login'] ?? ''),
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'registered' => $registered,
            'role' => $data['role'] ?? '',
            '_extra' => $data['_extra'] ?? [],
        ];
    }

    private function toIso8601(string $raw): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new \DateTimeZone('UTC'));
        if ($dt === false) {
            return $raw;
        }

        return $dt->format(\DateTimeInterface::ATOM);
    }
}
