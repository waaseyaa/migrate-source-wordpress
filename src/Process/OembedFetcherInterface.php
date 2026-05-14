<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

/**
 * HTTP plug for {@see WordPressOembedExpand}.
 *
 * Implementations issue an HTTP GET to the supplied oEmbed endpoint URL
 * (already includes the `url` and `format=json` query string) and return
 * the response body verbatim. The plugin parses the JSON.
 *
 * The package ships no default fetcher to avoid taking on `psr/http-client`
 * as a hard dependency.
 *
 * @api
 */
interface OembedFetcherInterface
{
    /**
     * @throws \Throwable On HTTP failure. Will be wrapped as
     *     {@see \Waaseyaa\Migrate\Source\WordPress\Exception\WordPressOembedResolutionException::httpFailure()}.
     */
    public function fetch(string $oembedEndpointUrl): string;
}
