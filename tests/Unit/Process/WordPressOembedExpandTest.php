<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Process;

use Waaseyaa\Migrate\Source\WordPress\Exception\WordPressOembedResolutionException;
use Waaseyaa\Migrate\Source\WordPress\Process\OembedFetcherInterface;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressOembedExpand;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\SourceRecord;

/**
 * @internal
 */
function oembedContext(): ProcessContext
{
    return new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', ['id' => 1]),
        migrationId: 'wp_posts',
        destinationField: 'body',
        lookup: static fn (string $m, $id) => null,
    );
}

it('declares plugin metadata', function () {
    $plugin = new WordPressOembedExpand();
    expect($plugin->id())->toBe('wordpress_oembed_expand');
    expect($plugin->stability())->toBe('stable');
});

it('returns content unchanged with resolveRemote=false (default)', function () {
    $plugin = new WordPressOembedExpand();
    $input = 'Check this: https://www.youtube.com/watch?v=abc123 ok';
    expect($plugin->transform($input, oembedContext()))->toBe($input);
});

it('detects YouTube/Vimeo/Twitter/Instagram URLs', function () {
    $plugin = new WordPressOembedExpand();
    $hits = $plugin->detect(
        'YT https://www.youtube.com/watch?v=abc Vimeo https://vimeo.com/12345 Twitter https://twitter.com/foo/status/9 Instagram https://www.instagram.com/p/CXY/'
    );

    $providers = array_map(static fn ($h) => $h['provider'], $hits);
    expect($providers)->toContain('youtube');
    expect($providers)->toContain('vimeo');
    expect($providers)->toContain('twitter');
    expect($providers)->toContain('instagram');
});

it('detects youtu.be short links', function () {
    $hits = (new WordPressOembedExpand())->detect('Try https://youtu.be/abc123');
    expect($hits[0]['provider'])->toBe('youtube');
});

it('replaces a detected URL with provider HTML when resolveRemote=true', function () {
    $fetcher = new class implements OembedFetcherInterface {
        public function fetch(string $oembedEndpointUrl): string
        {
            return json_encode(['html' => '<iframe src="yt"></iframe>'], JSON_THROW_ON_ERROR);
        }
    };

    $plugin = new WordPressOembedExpand(resolveRemote: true, fetcher: $fetcher);
    $out = $plugin->transform('Watch: https://www.youtube.com/watch?v=abc123 here', oembedContext());
    expect($out)->toBe('Watch: <iframe src="yt"></iframe> here');
});

it('caches resolved URLs within a single plugin instance', function () {
    $fetcher = new class implements OembedFetcherInterface {
        public int $calls = 0;

        public function fetch(string $oembedEndpointUrl): string
        {
            $this->calls++;
            return json_encode(['html' => '<iframe></iframe>'], JSON_THROW_ON_ERROR);
        }
    };

    $plugin = new WordPressOembedExpand(resolveRemote: true, fetcher: $fetcher);
    $plugin->transform('A https://www.youtube.com/watch?v=abc B https://www.youtube.com/watch?v=abc', oembedContext());

    expect($fetcher->calls)->toBe(1);
});

it('throws CODE_HTTP_FAILURE when the fetcher throws', function () {
    $fetcher = new class implements OembedFetcherInterface {
        public function fetch(string $oembedEndpointUrl): string
        {
            throw new \RuntimeException('connection refused');
        }
    };

    $plugin = new WordPressOembedExpand(resolveRemote: true, fetcher: $fetcher);
    try {
        $plugin->transform('https://www.youtube.com/watch?v=abc', oembedContext());
        expect(false)->toBeTrue('expected exception');
    } catch (WordPressOembedResolutionException $e) {
        expect($e->errorCode)->toBe(WordPressOembedResolutionException::CODE_HTTP_FAILURE);
    }
});

it('throws CODE_INVALID_RESPONSE for non-JSON', function () {
    $fetcher = new class implements OembedFetcherInterface {
        public function fetch(string $oembedEndpointUrl): string
        {
            return 'not json';
        }
    };

    $plugin = new WordPressOembedExpand(resolveRemote: true, fetcher: $fetcher);
    try {
        $plugin->transform('https://vimeo.com/12345', oembedContext());
        expect(false)->toBeTrue('expected exception');
    } catch (WordPressOembedResolutionException $e) {
        expect($e->errorCode)->toBe(WordPressOembedResolutionException::CODE_INVALID_RESPONSE);
    }
});

it('throws CODE_INVALID_RESPONSE when response lacks html', function () {
    $fetcher = new class implements OembedFetcherInterface {
        public function fetch(string $oembedEndpointUrl): string
        {
            return json_encode(['type' => 'video'], JSON_THROW_ON_ERROR);
        }
    };

    $plugin = new WordPressOembedExpand(resolveRemote: true, fetcher: $fetcher);
    try {
        $plugin->transform('https://twitter.com/foo/status/9', oembedContext());
        expect(false)->toBeTrue('expected exception');
    } catch (WordPressOembedResolutionException $e) {
        expect($e->errorCode)->toBe(WordPressOembedResolutionException::CODE_INVALID_RESPONSE);
    }
});

it('throws CODE_HTTP_FAILURE when resolveRemote is true without a fetcher', function () {
    $plugin = new WordPressOembedExpand(resolveRemote: true);
    try {
        $plugin->transform('https://www.youtube.com/watch?v=abc', oembedContext());
        expect(false)->toBeTrue('expected exception');
    } catch (WordPressOembedResolutionException $e) {
        expect($e->errorCode)->toBe(WordPressOembedResolutionException::CODE_HTTP_FAILURE);
    }
});
