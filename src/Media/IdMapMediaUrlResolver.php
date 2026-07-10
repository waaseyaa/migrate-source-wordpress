<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Media;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressMediaRewriteUrl;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressMediaSource;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\SourceId;

/**
 * Stock id-map-backed resolver for {@see WordPressMediaRewriteUrl}'s
 * `$urlResolver` seam (G-020).
 *
 * `WordPressMediaRewriteUrl` intentionally has no default implementation of
 * that resolver — the mapping from a `wp-content/uploads/...`-relative path
 * to a destination URL depends on the app's media id-map, which this
 * package cannot see from inside a stateless process plugin. This class is
 * the "batteries included" implementation of that seam, built from three
 * pieces every WordPress import already has lying around:
 *
 * 1. **`$pathToAttachmentId`** — an uploads-relative path → WordPress
 *    attachment id index. Build it with {@see indexFromSource()} straight
 *    from a {@see WordPressMediaSource} (which already emits `file_path`
 *    uploads-relative per G-017, and `id`).
 * 2. **The media migration's id-map** ({@see MigrationIdMap}), consulted
 *    directly (not through a process-chain `$lookup` closure — this class
 *    is not a {@see \Waaseyaa\Migration\Plugin\ProcessPluginInterface}, it
 *    is a factory that *produces* the closure `WordPressMediaRewriteUrl`'s
 *    constructor wants, so it has no `ProcessContext` to pull `$lookup`
 *    from). `MigrationIdMap::lookupDestination()` is public and
 *    ctor-injectable specifically for callers like this one.
 * 3. **`$uuidToUrl`** — an app-supplied closure resolving the destination
 *    media entity's `(entityType, uuid)` to its final public URL (e.g.
 *    `/media/<id>/file`, or a copied-file CDN URL). URL-shape is entirely
 *    an app decision, so it stays a seam rather than a built-in.
 *
 * Any miss in the chain (path not indexed, no id-map row yet, `$uuidToUrl`
 * itself misses) returns `null` and logs a warning — `null` is exactly
 * what {@see WordPressMediaRewriteUrl} treats as "no mapping, leave the URL
 * untouched and warn," so a partially-completed media migration degrades
 * gracefully instead of throwing mid-run.
 *
 * Use it via {@see resolver()} or by passing the instance itself
 * (`__invoke`) directly as `WordPressMediaRewriteUrl`'s constructor
 * argument — both are the same closure shape.
 *
 * @api
 *
 * @spec G-020 — stock id-map-backed media URL resolver
 */
final class IdMapMediaUrlResolver
{
    /**
     * @param MigrationIdMap $idMap The framework's id-map repository.
     * @param string $mediaMigrationId Id of the migration that wrote media entities (whose id-map rows this resolver reads).
     * @param array<string, int|string> $pathToAttachmentId Uploads-relative path (e.g. `2025/05/logo.png`, no leading slash) => WordPress attachment id. Build via {@see indexFromSource()}.
     * @param \Closure(string $entityType, string $uuid): ?string $uuidToUrl Resolves a destination media entity to its final public URL. Return null when unresolvable.
     * @param string $mediaSourceType {@see SourceId::$sourceType} used to look up the media migration's id-map rows. Must match the `SourceId` the media source plugin used when writing (e.g. {@see WordPressMediaSource::SOURCE_TYPE}).
     */
    public function __construct(
        private readonly MigrationIdMap $idMap,
        private readonly string $mediaMigrationId,
        private readonly array $pathToAttachmentId,
        private readonly \Closure $uuidToUrl,
        private readonly string $mediaSourceType = WordPressMediaSource::SOURCE_TYPE,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Build a `$pathToAttachmentId` index by iterating every record a
     * {@see WordPressMediaSource} yields. Skips attachments with an
     * unresolvable (empty) `file_path` — those never had a rewritable
     * uploads-relative path to begin with (see
     * {@see WordPressMediaSource::derivePath()}'s `''` contract).
     *
     * @return array<string, int|string>
     */
    public static function indexFromSource(SourcePluginInterface $source): array
    {
        $index = [];
        foreach ($source->records() as $record) {
            $filePath = $record->field('file_path');
            $id = $record->field('id');
            if (!is_string($filePath) || $filePath === '') {
                continue;
            }
            if (!is_int($id) && !is_string($id)) {
                continue;
            }
            $index[$filePath] = $id;
        }

        return $index;
    }

    /**
     * The closure shape {@see WordPressMediaRewriteUrl} expects — pass this
     * (or the instance itself, since `__invoke` has the identical
     * signature) as its `$urlResolver` constructor argument.
     *
     * @return \Closure(string $relativePath): ?string
     */
    public function resolver(): \Closure
    {
        return $this->__invoke(...);
    }

    public function __invoke(string $relativePath): ?string
    {
        $uploadsRelative = preg_replace('#^/?wp-content/uploads/#i', '', ltrim($relativePath, '/'));
        if (!is_string($uploadsRelative) || $uploadsRelative === '') {
            $uploadsRelative = ltrim($relativePath, '/');
        }

        $attachmentId = $this->pathToAttachmentId[$uploadsRelative] ?? null;
        if ($attachmentId === null) {
            $this->logger->warning('No indexed WordPress attachment for uploads-relative path; leaving media URL untouched.', [
                'relative_path' => $relativePath,
                'uploads_relative' => $uploadsRelative,
            ]);
            return null;
        }

        $sourceId = new SourceId($this->mediaSourceType, ['id' => (string) $attachmentId]);
        $writeResult = $this->idMap->lookupDestination($this->mediaMigrationId, $sourceId);
        if ($writeResult === null) {
            $this->logger->warning('No id-map row yet for WordPress attachment; media migration may not have run.', [
                'relative_path' => $relativePath,
                'attachment_id' => $attachmentId,
                'media_migration_id' => $this->mediaMigrationId,
            ]);
            return null;
        }

        $url = ($this->uuidToUrl)($writeResult->destinationEntityType, $writeResult->destinationUuid);
        if ($url === null || $url === '') {
            $this->logger->warning('uuidToUrl resolver returned no URL for a migrated attachment.', [
                'relative_path' => $relativePath,
                'attachment_id' => $attachmentId,
                'destination_entity_type' => $writeResult->destinationEntityType,
                'destination_uuid' => $writeResult->destinationUuid,
            ]);
            return null;
        }

        return $url;
    }
}
