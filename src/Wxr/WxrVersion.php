<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Wxr;

/**
 * Supported WXR (WordPress eXtended RSS) format versions.
 *
 * WXR has been stable since WP 3.0 (2010, version 1.2). Real-world exports are
 * overwhelmingly v1.2; v1.0 and v1.1 still appear in legacy archives. Any other
 * version (pre-1.0 or hypothetical 2.x) is rejected by {@see WxrReader}.
 *
 * @api
 */
enum WxrVersion: string
{
    case V_1_0 = '1.0';
    case V_1_1 = '1.1';
    case V_1_2 = '1.2';

    /**
     * Resolve a version string from a `<wp:wxr_version>` element.
     *
     * @throws \Waaseyaa\Migrate\Source\WordPress\Exception\WxrParseException
     *     when the version is not one of the supported values
     */
    public static function fromString(string $raw): self
    {
        return self::tryFrom($raw)
            ?? throw \Waaseyaa\Migrate\Source\WordPress\Exception\WxrParseException::unsupportedVersion($raw);
    }
}
