<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Media;

use Waaseyaa\Migrate\Source\WordPress\Exception\WordPressMediaCopyException;
use Waaseyaa\Migrate\Source\WordPress\Media\MediaCopier;
use Waaseyaa\Migrate\Source\WordPress\Media\MediaCopyOperation;
use Waaseyaa\Migrate\Source\WordPress\Media\MediaFetcherInterface;

/**
 * @internal
 */
function makeTempDir(): string
{
    $dir = sys_get_temp_dir() . '/wp_media_copier_' . uniqid('', true);
    mkdir($dir, 0o755, true);
    return $dir;
}

/**
 * @internal
 *
 * @param iterable<string> $tempDirs
 */
function cleanupDirs(iterable $tempDirs): void
{
    foreach ($tempDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $fileinfo) {
            $action = $fileinfo->isDir() ? 'rmdir' : 'unlink';
            @$action($fileinfo->getRealPath() ?: $fileinfo->getPathname());
        }
        @rmdir($dir);
    }
}

it('copies a local file into a fresh target', function () {
    $tmp = makeTempDir();
    try {
        $source = $tmp . '/source.bin';
        $target = $tmp . '/dst/target.bin';
        file_put_contents($source, 'hello world');

        $result = (new MediaCopier())->copy($source, $target);

        expect($result->operation)->toBe(MediaCopyOperation::Copied);
        expect($result->sizeBytes)->toBe(11);
        expect(file_get_contents($target))->toBe('hello world');
    } finally {
        cleanupDirs([$tmp]);
    }
});

it('skips a copy when the target already matches source size', function () {
    $tmp = makeTempDir();
    try {
        $source = $tmp . '/source.bin';
        $target = $tmp . '/target.bin';
        file_put_contents($source, 'twelve bytes');
        file_put_contents($target, 'twelve bytes');

        $result = (new MediaCopier())->copy($source, $target);

        expect($result->operation)->toBe(MediaCopyOperation::Skipped);
    } finally {
        cleanupDirs([$tmp]);
    }
});

it('replaces a target with a mismatched size and warns', function () {
    $tmp = makeTempDir();
    try {
        $source = $tmp . '/source.bin';
        $target = $tmp . '/target.bin';
        file_put_contents($source, 'fresh payload');
        file_put_contents($target, 'old');

        $result = (new MediaCopier())->copy($source, $target);

        expect($result->operation)->toBe(MediaCopyOperation::Replaced);
        expect(file_get_contents($target))->toBe('fresh payload');
    } finally {
        cleanupDirs([$tmp]);
    }
});

it('throws CODE_SOURCE_NOT_FOUND when the local source is missing', function () {
    $tmp = makeTempDir();
    try {
        $copier = new MediaCopier();
        try {
            $copier->copy($tmp . '/missing.bin', $tmp . '/target.bin');
            expect(false)->toBeTrue('expected exception');
        } catch (WordPressMediaCopyException $e) {
            expect($e->errorCode)->toBe(WordPressMediaCopyException::CODE_SOURCE_NOT_FOUND);
        }
    } finally {
        cleanupDirs([$tmp]);
    }
});

it('throws CODE_HTTP_FETCHER_MISSING when given an http url without a fetcher', function () {
    $tmp = makeTempDir();
    try {
        try {
            (new MediaCopier())->copy('https://example.test/file.png', $tmp . '/file.png');
            expect(false)->toBeTrue('expected exception');
        } catch (WordPressMediaCopyException $e) {
            expect($e->errorCode)->toBe(WordPressMediaCopyException::CODE_HTTP_FETCHER_MISSING);
        }
    } finally {
        cleanupDirs([$tmp]);
    }
});

it('fetches via the http fetcher and writes atomically', function () {
    $tmp = makeTempDir();
    try {
        $fetcher = new class implements MediaFetcherInterface {
            public int $calls = 0;

            public function fetch(string $url, string $destinationPath): void
            {
                $this->calls++;
                file_put_contents($destinationPath, 'remote bytes');
            }
        };

        $result = (new MediaCopier($fetcher, retryBaseDelayMs: 0))
            ->copy('https://example.test/file.bin', $tmp . '/file.bin');

        expect($result->operation)->toBe(MediaCopyOperation::Fetched);
        expect($fetcher->calls)->toBe(1);
        expect(file_get_contents($tmp . '/file.bin'))->toBe('remote bytes');
    } finally {
        cleanupDirs([$tmp]);
    }
});

it('retries the http fetcher with exponential backoff', function () {
    $tmp = makeTempDir();
    try {
        $fetcher = new class implements MediaFetcherInterface {
            public int $calls = 0;

            public function fetch(string $url, string $destinationPath): void
            {
                $this->calls++;
                if ($this->calls < 3) {
                    throw new \RuntimeException('transient');
                }
                file_put_contents($destinationPath, 'eventual success');
            }
        };

        $result = (new MediaCopier($fetcher, retryBaseDelayMs: 0))
            ->copy('https://example.test/r.bin', $tmp . '/r.bin');

        expect($fetcher->calls)->toBe(3);
        expect($result->operation)->toBe(MediaCopyOperation::Fetched);
    } finally {
        cleanupDirs([$tmp]);
    }
});

it('throws CODE_HTTP_FETCH_FAILED after exhausting retries', function () {
    $tmp = makeTempDir();
    try {
        $fetcher = new class implements MediaFetcherInterface {
            public int $calls = 0;

            public function fetch(string $url, string $destinationPath): void
            {
                $this->calls++;
                throw new \RuntimeException('always fails');
            }
        };

        $copier = new MediaCopier($fetcher, retryBaseDelayMs: 0);
        try {
            $copier->copy('https://example.test/x.bin', $tmp . '/x.bin');
            expect(false)->toBeTrue('expected exception');
        } catch (WordPressMediaCopyException $e) {
            expect($e->errorCode)->toBe(WordPressMediaCopyException::CODE_HTTP_FETCH_FAILED);
            expect($fetcher->calls)->toBe(3);
            expect($e->getPrevious()?->getMessage())->toBe('always fails');
        }
    } finally {
        cleanupDirs([$tmp]);
    }
});

it('throws CODE_HASH_MISMATCH when sha256 differs from expected', function () {
    $tmp = makeTempDir();
    try {
        $source = $tmp . '/source.bin';
        $target = $tmp . '/target.bin';
        file_put_contents($source, 'real content');

        $bogus = str_repeat('0', 64);

        try {
            (new MediaCopier())->copy($source, $target, expectedHash: $bogus);
            expect(false)->toBeTrue('expected exception');
        } catch (WordPressMediaCopyException $e) {
            expect($e->errorCode)->toBe(WordPressMediaCopyException::CODE_HASH_MISMATCH);
            expect(is_file($target))->toBeFalse('temp file must be cleaned up on hash mismatch');
        }
    } finally {
        cleanupDirs([$tmp]);
    }
});

it('passes hash verification when sha256 matches expected', function () {
    $tmp = makeTempDir();
    try {
        $source = $tmp . '/source.bin';
        $target = $tmp . '/target.bin';
        file_put_contents($source, 'verified content');
        $expectedHash = hash('sha256', 'verified content');

        $result = (new MediaCopier())->copy($source, $target, expectedHash: $expectedHash);
        expect($result->operation)->toBe(MediaCopyOperation::Copied);
    } finally {
        cleanupDirs([$tmp]);
    }
});

it('skips and verifies hash when target already matches expected size', function () {
    $tmp = makeTempDir();
    try {
        $source = $tmp . '/source.bin';
        $target = $tmp . '/target.bin';
        $payload = 'idempotent';
        file_put_contents($source, $payload);
        file_put_contents($target, $payload);
        $hash = hash('sha256', $payload);

        $result = (new MediaCopier())->copy($source, $target, expectedSize: strlen($payload), expectedHash: $hash);

        expect($result->operation)->toBe(MediaCopyOperation::Skipped);
    } finally {
        cleanupDirs([$tmp]);
    }
});
