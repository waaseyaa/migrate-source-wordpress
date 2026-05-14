<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Exception;

/**
 * Raised by {@see \Waaseyaa\Migrate\Source\WordPress\Media\MediaCopier} when a
 * media copy or HTTP fetch cannot complete.
 *
 * Stable codes (charter §4.4):
 * - {@see self::CODE_SOURCE_NOT_FOUND} — local source path does not exist or is unreadable
 * - {@see self::CODE_TARGET_WRITE_FAILED} — destination cannot be written / renamed
 * - {@see self::CODE_HTTP_FETCH_FAILED} — HTTP fetcher exhausted retries
 * - {@see self::CODE_HTTP_FETCHER_MISSING} — caller passed an http URL but did not configure an HTTP fetcher
 * - {@see self::CODE_HASH_MISMATCH} — post-copy sha256 differs from caller-supplied expected hash
 *
 * @api
 */
final class WordPressMediaCopyException extends \RuntimeException
{
    public const string CODE_SOURCE_NOT_FOUND = 'wp_media.source_not_found';
    public const string CODE_TARGET_WRITE_FAILED = 'wp_media.target_write_failed';
    public const string CODE_HTTP_FETCH_FAILED = 'wp_media.http_fetch_failed';
    public const string CODE_HTTP_FETCHER_MISSING = 'wp_media.http_fetcher_missing';
    public const string CODE_HASH_MISMATCH = 'wp_media.hash_mismatch';

    private function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function sourceNotFound(string $sourcePath): self
    {
        return new self(
            self::CODE_SOURCE_NOT_FOUND,
            sprintf('Media source not found or unreadable: %s', $sourcePath),
        );
    }

    public static function targetWriteFailed(string $targetPath, string $reason): self
    {
        return new self(
            self::CODE_TARGET_WRITE_FAILED,
            sprintf('Failed to write media target %s: %s', $targetPath, $reason),
        );
    }

    public static function httpFetchFailed(string $url, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            self::CODE_HTTP_FETCH_FAILED,
            sprintf('HTTP media fetch failed for %s after retries: %s', $url, $reason),
            $previous,
        );
    }

    public static function httpFetcherMissing(string $url): self
    {
        return new self(
            self::CODE_HTTP_FETCHER_MISSING,
            sprintf(
                'Cannot fetch %s without an HTTP fetcher — construct MediaCopier with a MediaFetcherInterface.',
                $url,
            ),
        );
    }

    public static function hashMismatch(string $targetPath, string $expected, string $actual): self
    {
        return new self(
            self::CODE_HASH_MISMATCH,
            sprintf(
                'sha256 mismatch on %s: expected %s, got %s',
                $targetPath,
                $expected,
                $actual,
            ),
        );
    }
}
