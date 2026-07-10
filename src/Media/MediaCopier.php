<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Media;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Waaseyaa\Migrate\Source\WordPress\Exception\WordPressMediaCopyException;

/**
 * Idempotent local + HTTP media copy primitive used by the WordPress process
 * plugins (WP08) when materialising attachments into the destination
 * filesystem.
 *
 * Idempotency rules:
 *
 * 1. If the target exists and its size matches `$expectedSize` (or the source
 *    file size, when source is local), the copy is skipped.
 * 2. If the target exists but the size differs, the file is replaced and a
 *    warning is logged — the operator should treat this as "WXR is now
 *    authoritative for this slot".
 * 3. Otherwise the payload is streamed to a temp path then atomic-renamed
 *    into place. The atomic rename keeps partial writes from being served.
 *
 * `http://` / `https://` source URIs are dispatched to the injected
 * {@see MediaFetcherInterface}; the package ships no default fetcher so
 * consumers explicitly opt into network I/O. Failures are retried with
 * exponential backoff (3 attempts by default, 500 ms base delay) before
 * being surfaced as {@see WordPressMediaCopyException::httpFetchFailed()}.
 *
 * Optional hash verification (FR-029): when `$expectedHash` is supplied, the
 * sha256 of the materialised target is verified after copy/fetch; a mismatch
 * raises {@see WordPressMediaCopyException::hashMismatch()}.
 *
 * A missing/unreadable local source is never a silent no-op: a `warning` is
 * logged (with the resolved absolute `source` and `target` paths) via the
 * injected `$logger` immediately before
 * {@see WordPressMediaCopyException::sourceNotFound()} is thrown (G-017).
 * Callers that guard the copy call with their own `is_file()`/`is_readable()`
 * pre-check — instead of catching the exception — bypass this warning; they
 * should call {@see MediaCopier::copy()} unconditionally and handle/log the
 * exception themselves rather than pre-checking existence.
 *
 * @api
 *
 * @spec FR-026 FR-027 FR-028 FR-029 — media copy primitive
 */
final class MediaCopier
{
    public function __construct(
        private readonly ?MediaFetcherInterface $httpFetcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxAttempts = 3,
        private readonly int $retryBaseDelayMs = 500,
    ) {
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('MediaCopier::$maxAttempts must be >= 1.');
        }
        if ($this->retryBaseDelayMs < 0) {
            throw new \InvalidArgumentException('MediaCopier::$retryBaseDelayMs must be >= 0.');
        }
    }

    /**
     * Copy `$source` → `$target`, idempotently.
     *
     * @param string      $source        Absolute local path OR `http(s)://` URL
     * @param string      $target        Absolute destination path
     * @param int|null    $expectedSize  Expected byte count (from WXR metadata, optional)
     * @param string|null $expectedHash  Expected sha256 hex digest (optional, FR-029)
     *
     * @throws WordPressMediaCopyException
     */
    public function copy(
        string $source,
        string $target,
        ?int $expectedSize = null,
        ?string $expectedHash = null,
    ): MediaCopyResult {
        $isHttp = $this->isHttpUri($source);

        // Idempotency check: target already present.
        if (is_file($target)) {
            $existingSize = @filesize($target) ?: 0;
            $reference = $expectedSize;
            if ($reference === null && !$isHttp && is_readable($source)) {
                $reference = @filesize($source) ?: 0;
            }

            if ($reference !== null && $existingSize === $reference) {
                $this->verifyHash($target, $expectedHash);
                return MediaCopyResult::skipped($target, $existingSize);
            }

            if ($reference !== null) {
                $this->logger->warning('Replacing media target with mismatched size', [
                    'target' => $target,
                    'existing_size' => $existingSize,
                    'expected_size' => $reference,
                ]);
                return $this->writeReplacing($source, $target, $expectedHash, $isHttp);
            }

            // No size oracle and target exists: assume idempotent skip.
            return MediaCopyResult::skipped($target, $existingSize);
        }

        $this->ensureParentDirectory($target);
        return $this->write($source, $target, $expectedHash, $isHttp, replacing: false);
    }

    private function writeReplacing(string $source, string $target, ?string $expectedHash, bool $isHttp): MediaCopyResult
    {
        return $this->write($source, $target, $expectedHash, $isHttp, replacing: true);
    }

    private function write(string $source, string $target, ?string $expectedHash, bool $isHttp, bool $replacing): MediaCopyResult
    {
        $tmp = $this->tempPath($target);

        try {
            if ($isHttp) {
                $this->fetchHttp($source, $tmp);
                $operation = MediaCopyOperation::Fetched;
            } else {
                if (!is_readable($source)) {
                    $this->logger->warning('Media source not found or unreadable; copy skipped.', [
                        'source' => $source,
                        'target' => $target,
                    ]);
                    throw WordPressMediaCopyException::sourceNotFound($source);
                }
                if (@copy($source, $tmp) === false) {
                    throw WordPressMediaCopyException::targetWriteFailed($tmp, error_get_last()['message'] ?? 'copy() returned false');
                }
                $operation = $replacing ? MediaCopyOperation::Replaced : MediaCopyOperation::Copied;
            }

            $this->verifyHash($tmp, $expectedHash);

            if (@rename($tmp, $target) === false) {
                throw WordPressMediaCopyException::targetWriteFailed($target, error_get_last()['message'] ?? 'rename() returned false');
            }
        } catch (\Throwable $e) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            throw $e;
        }

        $size = @filesize($target) ?: 0;
        return new MediaCopyResult($operation, $target, $size);
    }

    private function fetchHttp(string $url, string $tmpPath): void
    {
        if ($this->httpFetcher === null) {
            throw WordPressMediaCopyException::httpFetcherMissing($url);
        }

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $this->httpFetcher->fetch($url, $tmpPath);
                return;
            } catch (\Throwable $e) {
                if ($attempt === $this->maxAttempts) {
                    throw WordPressMediaCopyException::httpFetchFailed($url, $e->getMessage(), $e);
                }
                $delay = $this->retryBaseDelayMs * (2 ** ($attempt - 1));
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        }
    }

    private function verifyHash(string $path, ?string $expectedHash): void
    {
        if ($expectedHash === null) {
            return;
        }
        $actual = @hash_file('sha256', $path);
        if ($actual === false || !hash_equals($expectedHash, $actual)) {
            throw WordPressMediaCopyException::hashMismatch($path, $expectedHash, (string) $actual);
        }
    }

    private function ensureParentDirectory(string $target): void
    {
        $dir = \dirname($target);
        if (is_dir($dir)) {
            return;
        }
        if (@mkdir($dir, 0o755, true) === false && !is_dir($dir)) {
            throw WordPressMediaCopyException::targetWriteFailed($dir, 'mkdir() failed for parent directory');
        }
    }

    private function tempPath(string $target): string
    {
        return $target . '.tmp.' . bin2hex(random_bytes(6));
    }

    private function isHttpUri(string $source): bool
    {
        return str_starts_with($source, 'http://') || str_starts_with($source, 'https://');
    }
}
