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
 * Source plugin yielding one {@see SourceRecord} per WordPress comment.
 *
 * Comments live nested under post `<item>` elements in WXR; {@see WxrReader}
 * already emits them as separate `type=comment` records with `post_id`
 * extracted from the enclosing item and `parent_id` set to `null` for
 * top-level comments (raw `wp:comment_parent` of `0`). The reader also
 * surfaces the raw `wp:comment_approved` token via `_extra['approved_raw']`
 * whenever it is anything other than `1`, so consumers can distinguish
 * spam/trash/pending from "simply not approved".
 *
 * Per research §1.12, threading is preserved verbatim — we never flatten
 * `parent_id`, even if the destination engagement model cannot represent
 * trees today.
 *
 * @api
 *
 * @spec FR-007 — WordPress comment source
 * @spec FR-010 FR-011 FR-012 — deterministic SourceId
 */
final class WordPressCommentSource implements SourcePluginInterface
{
    public const string SOURCE_TYPE = 'wp_comment';
    public const string PLUGIN_ID = 'wordpress_comment';

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
                if ($record['type'] !== 'comment') {
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
                'WordPressCommentSource::sourceIdFor expected scalar id, got %s.',
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
            'post_id' => $data['post_id'] ?? 0,
            'parent_id' => $data['parent_id'] ?? null,
            'author' => $data['author'] ?? '',
            'author_email' => $data['author_email'] ?? null,
            'author_url' => $data['author_url'] ?? null,
            'author_ip' => $data['author_ip'] ?? null,
            'content' => $data['content'] ?? '',
            'published_at' => $this->toIso8601(is_string($data['published_at'] ?? null) ? $data['published_at'] : null),
            'approved' => (bool) ($data['approved'] ?? false),
            'comment_type' => $data['comment_type'] ?? '',
            'user_login' => $data['user_login'] ?? null,
            '_extra' => $data['_extra'] ?? [],
        ];
    }

    private function toIso8601(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new \DateTimeZone('UTC'));
        if ($dt === false) {
            return $raw;
        }
        return $dt->format(\DateTimeInterface::ATOM);
    }
}
