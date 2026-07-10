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
 * Source plugin yielding one {@see SourceRecord} per WordPress post.
 *
 * Per research §1.4, this is a single post source that handles `post`,
 * `page`, and any custom post type — the discriminator from {@see WxrReader}
 * is "non-attachment item", so the source emits everything the consumer can
 * then filter by `post_type` field downstream, or narrow at the source via
 * the `$postTypes` constructor allowlist (G-027) for per-bundle migration
 * splits (e.g. one migration per WordPress post type).
 *
 * Dates (`published_at`, `modified_at`) are normalised from WP's MySQL
 * `Y-m-d H:i:s` shape to ISO 8601. The `0000-00-00 00:00:00` "no date"
 * sentinel is already stripped by the reader's post_date_gmt → post_date
 * fallback chain.
 *
 * **Trash handling (G-021):** WordPress-trashed items (`wp:status` ===
 * `trash`) are skipped by default, since they are typically not intended to
 * survive a migration. Pass `includeTrashed: true` to opt into importing
 * them anyway.
 *
 * **Status contract (G-021):** the `status` field is always the raw
 * WordPress status string (`publish`, `draft`, `pending`, `private`,
 * `future`, `trash`, ...), emitted verbatim — this source never flattens it
 * to a boolean. Consumers that want a boolean "published" field must add an
 * explicit status→bool mapping step in their own process map (e.g. a small
 * `ProcessPluginInterface` that maps `'publish' => true`, everything else to
 * `false`). Flattening status at the source would silently discard
 * `draft`/`private`/`pending`/`future` distinctions that some destinations
 * care about.
 *
 * @api
 *
 * @spec FR-005 — WordPress post source
 * @spec FR-010 FR-011 FR-012 — deterministic SourceId
 * @spec G-021 — trash-skip default + verbatim status contract
 * @spec G-027 — post_type allowlist filter
 */
final class WordPressPostSource implements SourcePluginInterface
{
    public const string SOURCE_TYPE = 'wp_post';
    public const string PLUGIN_ID = 'wordpress_post';

    /** @var ?list<string> */
    private readonly ?array $postTypes;

    /**
     * @param array<mixed>|null $postTypes Allowlist of `post_type` values to
     *     emit. `null` (default) emits every post type. When provided, must
     *     be non-empty and contain only non-empty strings (G-027). Typed as
     *     `array<mixed>` here (rather than `list<string>`) so this
     *     constructor-time validation stays meaningful for callers outside
     *     PHPStan's static view (e.g. dynamic config-driven callers).
     */
    public function __construct(
        private readonly WxrReader $reader,
        private readonly string $migrationId = self::PLUGIN_ID,
        private readonly bool $includeTrashed = false,
        ?array $postTypes = null,
    ) {
        if ($postTypes !== null) {
            if ($postTypes === []) {
                throw new \InvalidArgumentException(
                    'WordPressPostSource::$postTypes must be null or a non-empty list of post_type strings.',
                );
            }
            foreach ($postTypes as $postType) {
                if (!is_string($postType) || $postType === '') {
                    throw new \InvalidArgumentException(\sprintf(
                        'WordPressPostSource::$postTypes must contain only non-empty strings, got %s.',
                        \get_debug_type($postType),
                    ));
                }
            }
        }
        $this->postTypes = $postTypes;
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
                if ($record['type'] !== 'post') {
                    continue;
                }

                $data = $record['data'];

                if (!$this->includeTrashed && ($data['status'] ?? null) === 'trash') {
                    continue;
                }

                if ($this->postTypes !== null && !\in_array($data['post_type'] ?? 'post', $this->postTypes, true)) {
                    continue;
                }

                yield new SourceRecord(
                    sourceType: self::SOURCE_TYPE,
                    fields: $this->normalize($data),
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
                'WordPressPostSource::sourceIdFor expected scalar id, got %s.',
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
            'post_type' => $data['post_type'] ?? 'post',
            'title' => $data['title'] ?? '',
            'slug' => $data['slug'] ?? '',
            'content' => $data['content'] ?? '',
            'excerpt' => $data['excerpt'] ?? null,
            'status' => $data['status'] ?? '',
            'published_at' => $this->toIso8601(is_string($data['published_at'] ?? null) ? $data['published_at'] : null),
            'modified_at' => $this->toIso8601(is_string($data['modified_at'] ?? null) ? $data['modified_at'] : null),
            'author_login' => $data['author_login'] ?? '',
            'parent_id' => $data['parent_id'] ?? null,
            'terms' => $data['terms'] ?? [],
            'comment_status' => $data['comment_status'] ?? '',
            'password' => $data['password'] ?? null,
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
