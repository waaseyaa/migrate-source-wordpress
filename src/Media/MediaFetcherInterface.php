<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Media;

use Waaseyaa\Migrate\Source\WordPress\Exception\WordPressMediaCopyException;

/**
 * Pluggable HTTP fetcher used by {@see MediaCopier} for `http://` /
 * `https://` source URIs.
 *
 * Implementations stream the response body to the destination path. The
 * package ships no default; consumers wire their preferred PSR-18 client
 * (Symfony HttpClient, Guzzle, Slim PSR-7, etc.) behind this interface.
 *
 * @api
 */
interface MediaFetcherInterface
{
    /**
     * Stream-download $url into $destinationPath.
     *
     * Implementations MUST stream (do not buffer the entire response in
     * memory) and MAY write to a temporary path internally; the value at
     * $destinationPath on successful return is the final payload.
     *
     * @throws WordPressMediaCopyException If the fetch ultimately fails. Other
     *     exceptions are caught by {@see MediaCopier} and re-thrown as
     *     `CODE_HTTP_FETCH_FAILED`.
     */
    public function fetch(string $url, string $destinationPath): void;
}
