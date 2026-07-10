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
 * Source plugin yielding one {@see SourceRecord} per WordPress attachment.
 *
 * Filters {@see WxrReader} records to `type === 'attachment'` and projects
 * the post-like data shape into the media record contract defined by
 * data-model §1.3. `<wp:attachment_url>` is pulled from `_extra` (the reader
 * captures it there because `attachment_url` is not a typed post slot), and
 * postmeta keys `_wp_attached_file` / `_wp_attachment_image_alt` are pulled
 * from the `_extra.postmeta` sub-map.
 *
 * `size_bytes` is intentionally null at the source layer; downstream WP08
 * process plugins populate it after MediaCopier returns.
 *
 * @api
 *
 * @spec FR-008 — WordPress media source
 * @spec FR-010 FR-011 FR-012 — deterministic SourceId
 */
final class WordPressMediaSource implements SourcePluginInterface
{
    public const string SOURCE_TYPE = 'wp_media';
    public const string PLUGIN_ID = 'wordpress_media';

    /**
     * Conservative MIME table covering the WP-typical attachment extensions.
     * Anything not listed falls back to `application/octet-stream` per
     * data-model §1.3.
     */
    private const array MIME_BY_EXTENSION = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'avif' => 'image/avif',
        'bmp' => 'image/bmp',
        'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'zip' => 'application/zip',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
    ];

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
                if ($record['type'] !== 'attachment') {
                    continue;
                }
                yield new SourceRecord(
                    sourceType: self::SOURCE_TYPE,
                    fields: $this->project($record['data']),
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
                'WordPressMediaSource::sourceIdFor expected scalar id, got %s.',
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
    private function project(array $data): array
    {
        $extra = is_array($data['_extra'] ?? null) ? $data['_extra'] : [];
        /** @var array<string, mixed> $extra */

        $postmeta = is_array($extra['postmeta'] ?? null) ? $extra['postmeta'] : [];
        /** @var array<string, mixed> $postmeta */

        $originalUrl = is_string($extra['wp:attachment_url'] ?? null) ? $extra['wp:attachment_url'] : '';
        $filePath = $this->derivePath($originalUrl, $postmeta);
        $mimeType = $this->deriveMime($filePath);

        $altText = isset($postmeta['_wp_attachment_image_alt']) && $postmeta['_wp_attachment_image_alt'] !== ''
            ? (string) $postmeta['_wp_attachment_image_alt']
            : null;

        $caption = isset($data['excerpt']) && is_string($data['excerpt']) && $data['excerpt'] !== ''
            ? $data['excerpt']
            : null;
        $description = isset($data['content']) && is_string($data['content']) && $data['content'] !== ''
            ? $data['content']
            : null;

        return [
            'id' => $data['id'] ?? 0,
            'file_path' => $filePath,
            'mime_type' => $mimeType,
            'alt_text' => $altText,
            'caption' => $caption,
            'description' => $description,
            'parent_post_id' => $data['parent_id'] ?? null,
            'original_url' => $originalUrl,
            'size_bytes' => null,
            '_extra' => $this->pruneExtra($extra),
        ];
    }

    /**
     * Resolve the uploads-relative file path for an attachment — relative to
     * the WordPress `wp-content/uploads/` directory, no leading slash.
     *
     * Contract (BREAKING as of G-017 — previously site-root-relative, e.g.
     * `/wp-content/uploads/2025/05/logo.png`):
     *
     * 1. Prefers postmeta `_wp_attached_file`, which WordPress already
     *    stores uploads-relative (e.g. `2025/05/logo.png`). Returned
     *    verbatim, minus any leading slash.
     * 2. Falls back to `<wp:attachment_url>` when postmeta is absent: the
     *    URL path is percent-decoded, then stripped through its
     *    `wp-content/uploads/` segment (case-insensitive). This covers both
     *    year/month subfolder layouts and flat (non-year) layouts.
     * 3. Returns `''` when neither source yields a resolvable
     *    uploads-relative path (e.g. `attachment_url` present but has no
     *    `wp-content/uploads/` segment — an off-site or CDN-only URL).
     *    Callers must treat `''` as "no file to copy".
     *
     * The returned value composes directly with the layout documented in
     * docs/migrating-from-wordpress.md ("Option A"): join it to the local
     * `uploads/` root the operator rsynced media into
     * (`storage/imports/uploads/<file_path>`).
     *
     * @param array<string, mixed> $postmeta
     */
    private function derivePath(string $originalUrl, array $postmeta): string
    {
        $attached = $postmeta['_wp_attached_file'] ?? null;
        if (is_string($attached) && $attached !== '') {
            return ltrim($attached, '/');
        }

        if ($originalUrl !== '') {
            $path = parse_url($originalUrl, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $decoded = rawurldecode($path);
                $relative = preg_replace('#^.*?/wp-content/uploads/#i', '', $decoded);
                if (is_string($relative) && $relative !== $decoded) {
                    return ltrim($relative, '/');
                }
            }
        }

        return '';
    }

    private function deriveMime(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return self::MIME_BY_EXTENSION[$ext] ?? 'application/octet-stream';
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function pruneExtra(array $extra): array
    {
        unset($extra['wp:attachment_url']);
        return $extra;
    }
}
