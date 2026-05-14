<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

use Waaseyaa\Migrate\Source\WordPress\Exception\WordPressOembedResolutionException;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;

/**
 * Process plugin that detects oEmbed-capable URLs in post content and
 * optionally inlines the provider's oEmbed HTML.
 *
 * Default behaviour ({@see $resolveRemote} = false, per research §1.6):
 * URLs are detected but the post content is returned unchanged. Operators
 * who want HTML inlining enable remote resolution explicitly and supply
 * an {@see OembedFetcherInterface}.
 *
 * Supported providers (FR-016): YouTube, Vimeo, Twitter/X, Instagram.
 * Instagram's public oEmbed has been deprecated; matches are surfaced
 * but resolution is best-effort and may raise CODE_HTTP_FAILURE.
 *
 * Resolutions are cached per plugin instance so a single migration run
 * never refetches the same URL.
 *
 * @api
 *
 * @spec FR-014 — detect oEmbed-capable URLs
 * @spec FR-015 — opt-in remote resolution
 * @spec FR-016 — supported provider list
 * @spec FR-017 — `wordpress_*` plugin id naming
 */
final class WordPressOembedExpand implements ProcessPluginInterface
{
    public const string PLUGIN_ID = 'wordpress_oembed_expand';

    /**
     * @var array<string, array{regex: string, endpoint: string}>
     */
    private const array PROVIDERS = [
        'youtube' => [
            'regex' => '#https?://(?:www\.)?(?:youtube\.com/watch\?v=[\w\-]+|youtu\.be/[\w\-]+)(?:[?&][^\s"\'<>]*)?#i',
            'endpoint' => 'https://www.youtube.com/oembed',
        ],
        'vimeo' => [
            'regex' => '#https?://(?:www\.)?vimeo\.com/\d+(?:/\w+)?#i',
            'endpoint' => 'https://vimeo.com/api/oembed.json',
        ],
        'twitter' => [
            'regex' => '#https?://(?:www\.)?(?:twitter|x)\.com/[\w]+/status/\d+#i',
            'endpoint' => 'https://publish.twitter.com/oembed',
        ],
        'instagram' => [
            'regex' => '#https?://(?:www\.)?instagram\.com/p/[\w\-]+/?#i',
            'endpoint' => 'https://api.instagram.com/oembed',
        ],
    ];

    /**
     * @var array<string, string> URL → resolved HTML, populated as we resolve
     */
    private array $cache = [];

    public function __construct(
        private readonly bool $resolveRemote = false,
        private readonly ?OembedFetcherInterface $fetcher = null,
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

    public function transform(mixed $value, ProcessContext $context): mixed
    {
        unset($context);
        if (!is_string($value)) {
            return $value;
        }

        if (!$this->resolveRemote) {
            return $value;
        }

        $result = $value;
        foreach (self::PROVIDERS as $provider => $info) {
            $result = preg_replace_callback(
                $info['regex'],
                fn (array $match) => $this->resolveOne($match[0], $provider, $info['endpoint']),
                $result,
            ) ?? $result;
        }
        return $result;
    }

    /**
     * Detect oEmbed-capable URLs in $value without resolving them.
     *
     * @return list<array{provider: string, url: string}>
     */
    public function detect(string $value): array
    {
        $hits = [];
        foreach (self::PROVIDERS as $provider => $info) {
            if (preg_match_all($info['regex'], $value, $matches) > 0) {
                foreach ($matches[0] as $url) {
                    $hits[] = ['provider' => $provider, 'url' => $url];
                }
            }
        }
        return $hits;
    }

    private function resolveOne(string $url, string $provider, string $endpoint): string
    {
        if (isset($this->cache[$url])) {
            return $this->cache[$url];
        }

        if ($this->fetcher === null) {
            throw WordPressOembedResolutionException::httpFailure(
                $url,
                'resolve_remote is true but no OembedFetcherInterface was supplied',
            );
        }

        $oembedUrl = $endpoint . '?url=' . urlencode($url) . '&format=json';
        try {
            $body = $this->fetcher->fetch($oembedUrl);
        } catch (\Throwable $e) {
            throw WordPressOembedResolutionException::httpFailure($url, $e->getMessage(), $e);
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw WordPressOembedResolutionException::invalidResponse(
                $url,
                'JSON decode error: ' . $e->getMessage(),
            );
        }

        if (!is_array($decoded) || !isset($decoded['html']) || !is_string($decoded['html'])) {
            throw WordPressOembedResolutionException::invalidResponse(
                $url,
                'response did not contain a string `html` field',
            );
        }

        unset($provider);
        return $this->cache[$url] = $decoded['html'];
    }
}
