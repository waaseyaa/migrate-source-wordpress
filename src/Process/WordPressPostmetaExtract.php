<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;

/**
 * Process plugin that pulls one named key out of a post's WXR postmeta map.
 *
 * {@see \Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader} captures every
 * `<wp:postmeta>` entry for a post/attachment item into
 * `$record['_extra']['postmeta']` (a flat key → string map), and
 * {@see \Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource}
 * passes that through verbatim as the `_extra` source field. This plugin is
 * the general-purpose bridge from that passthrough blob into a typed
 * destination field — chain it as the second step after a `'_extra'`
 * pass-through head:
 *
 * ```php
 * 'event_start' => ['_extra', new WordPressPostmetaExtract('_EventStartDate')],
 * ```
 *
 * Any plugin (The Events Calendar, WooCommerce, Yoast, ...) that stores data
 * as postmeta can be mapped this way without a dedicated process plugin per
 * meta key.
 *
 * @api
 *
 * @spec FR-017 — `wordpress_*` plugin id naming
 */
final class WordPressPostmetaExtract implements ProcessPluginInterface
{
    public const string PLUGIN_ID = 'wordpress_postmeta_extract';

    /**
     * @param string $metaKey WXR `<wp:meta_key>` to read (e.g. `_EventStartDate`).
     * @param mixed $default Returned when `$metaKey` is absent from postmeta,
     *     or when the incoming value does not carry a postmeta map at all.
     */
    public function __construct(
        private readonly string $metaKey,
        private readonly mixed $default = null,
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

    /**
     * @param mixed $value Expected to be the record's `_extra` array (as
     *     produced by {@see WxrReader}); any other shape — missing, not an
     *     array, no `postmeta` slot, or a non-array `postmeta` slot — is
     *     tolerated and falls back to `$default`.
     */
    public function transform(mixed $value, ProcessContext $context): mixed
    {
        unset($context);

        if (!is_array($value)) {
            return $this->default;
        }

        $postmeta = $value['postmeta'] ?? null;
        if (!is_array($postmeta)) {
            return $this->default;
        }

        return array_key_exists($this->metaKey, $postmeta) ? $postmeta[$this->metaKey] : $this->default;
    }
}
