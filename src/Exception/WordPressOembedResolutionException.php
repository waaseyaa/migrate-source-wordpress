<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Exception;

/**
 * Raised by {@see \Waaseyaa\Migrate\Source\WordPress\Process\WordPressOembedExpand}
 * when remote oEmbed resolution fails.
 *
 * Stable codes (charter §4.4, FR-034):
 * - {@see self::CODE_PROVIDER_UNSUPPORTED} — URL matched no known oEmbed provider
 * - {@see self::CODE_HTTP_FAILURE} — provider returned non-2xx / network error
 * - {@see self::CODE_INVALID_RESPONSE} — response body was not valid JSON or lacked `html`
 *
 * The migration runner converts these to per-record warnings unless
 * `--halt-on-error` is set (FR-046/FR-047 semantics inherited from M-002).
 *
 * @api
 */
final class WordPressOembedResolutionException extends \RuntimeException
{
    public const string CODE_PROVIDER_UNSUPPORTED = 'wp_oembed.provider_unsupported';
    public const string CODE_HTTP_FAILURE = 'wp_oembed.http_failure';
    public const string CODE_INVALID_RESPONSE = 'wp_oembed.invalid_response';

    private function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function providerUnsupported(string $url): self
    {
        return new self(
            self::CODE_PROVIDER_UNSUPPORTED,
            sprintf('No oEmbed provider matches URL: %s', $url),
        );
    }

    public static function httpFailure(string $url, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            self::CODE_HTTP_FAILURE,
            sprintf('oEmbed HTTP failure for %s: %s', $url, $reason),
            $previous,
        );
    }

    public static function invalidResponse(string $url, string $reason): self
    {
        return new self(
            self::CODE_INVALID_RESPONSE,
            sprintf('oEmbed invalid response for %s: %s', $url, $reason),
        );
    }
}
