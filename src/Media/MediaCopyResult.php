<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Media;

/**
 * Result envelope returned by {@see MediaCopier::copy()}.
 *
 * @api
 */
final readonly class MediaCopyResult
{
    public function __construct(
        public MediaCopyOperation $operation,
        public string $targetPath,
        public int $sizeBytes,
    ) {
    }

    public static function skipped(string $targetPath, int $sizeBytes): self
    {
        return new self(MediaCopyOperation::Skipped, $targetPath, $sizeBytes);
    }

    public static function copied(string $targetPath, int $sizeBytes): self
    {
        return new self(MediaCopyOperation::Copied, $targetPath, $sizeBytes);
    }

    public static function replaced(string $targetPath, int $sizeBytes): self
    {
        return new self(MediaCopyOperation::Replaced, $targetPath, $sizeBytes);
    }

    public static function fetched(string $targetPath, int $sizeBytes): self
    {
        return new self(MediaCopyOperation::Fetched, $targetPath, $sizeBytes);
    }
}
