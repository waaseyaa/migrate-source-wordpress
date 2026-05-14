<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;

/**
 * Process plugin that strips WordPress shortcodes from post content.
 *
 * Shortcodes match `[name attr="val" ...]content[/name]` (with or without
 * a closing tag); unknown shortcodes are stripped silently. Registered
 * `$rewriters` are invoked with `(tagName, parsedAttrs, inner)` and their
 * return value replaces the shortcode inline.
 *
 * The package handles the well-formed 95% case — operator content with
 * deeply nested or malformed escaping should be cleaned up before import.
 * Nested shortcodes are processed greedy-outer-first, so re-running the
 * plugin in a chain naturally handles two-level nests.
 *
 * @api
 *
 * @spec FR-013 — strip/rewrite WordPress shortcodes
 * @spec FR-017 — `wordpress_*` plugin id naming
 */
final class WordPressShortcodeStrip implements ProcessPluginInterface
{
    public const string PLUGIN_ID = 'wordpress_shortcode_strip';

    /**
     * @param array<string, \Closure(string, array<string, string>, string): string> $rewriters
     *     Map of tag name → callback. Keys are case-insensitive at registration; matching
     *     is case-insensitive at runtime.
     */
    public function __construct(
        private readonly array $rewriters = [],
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

        return $this->strip($value, depth: 0);
    }

    private function strip(string $value, int $depth): string
    {
        if ($depth > 4) {
            return $value;
        }

        $pattern = '/\[([\w-]+)((?:\s+[^\]\[]*)?)\](?:(.*?)\[\/\1\])?/s';

        $result = preg_replace_callback(
            $pattern,
            function (array $match) use ($depth): string {
                $tag = strtolower($match[1]);
                $attrs = $this->parseAttrs($match[2]);
                $inner = isset($match[3]) ? $this->strip($match[3], $depth + 1) : '';

                $rewriter = $this->rewriters[$tag] ?? null;
                if ($rewriter instanceof \Closure) {
                    return $rewriter($tag, $attrs, $inner);
                }
                return $inner;
            },
            $value,
        );

        return is_string($result) ? $result : $value;
    }

    /**
     * @return array<string, string>
     */
    private function parseAttrs(string $raw): array
    {
        $attrs = [];
        if (preg_match_all('/([\w-]+)=("([^"]*)"|\'([^\']*)\'|(\S+))/', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = strtolower($match[1]);
                $attrs[$name] = $match[3] !== '' ? $match[3] : ($match[4] !== '' ? $match[4] : ($match[5] ?? ''));
            }
        }
        return $attrs;
    }
}
