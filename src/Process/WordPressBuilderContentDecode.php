<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Waaseyaa\Migrate\Source\WordPress\PageBuilder\ElementorTreeDecoder;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;

/**
 * Process plugin that decodes page-builder content into semantic HTML.
 *
 * Elementor stores a page's real content as a JSON tree in the
 * `_elementor_data` postmeta entry — the `post_content` field itself is
 * typically empty or a stub for Elementor-built pages. `WordPressPostSource`
 * captures all postmeta under the source record's `_extra.postmeta` map (see
 * {@see \Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource}), so
 * this plugin reads `$context->sourceRecord->field('_extra')['postmeta']['_elementor_data']`
 * — the `ProcessContext` carries the whole source record specifically so a
 * plugin can consult sibling fields while transforming one destination
 * field (G-013).
 *
 * Contract:
 *   - `_elementor_data` present and non-trivial (not `''`/`'[]'`) and decodes
 *     to at least one renderable block → the decoded HTML REPLACES `$value`.
 *   - `_elementor_data` present but malformed JSON, not an array, or decodes
 *     to zero renderable blocks → `$value` passes through unchanged and a
 *     warning is logged (the payload looked like Elementor data but could
 *     not be turned into content — worth an operator's attention).
 *   - `_elementor_data` absent or empty/`'[]'` (the common non-Elementor
 *     case) → `$value` passes through unchanged, silently.
 *
 * Independently of the above, Gutenberg block-delimiter comments
 * (`<!-- wp:name ... -->` / `<!-- /wp:name -->`) are always stripped from the
 * resulting HTML, preserving the inner markup (G-029). This covers both the
 * common case (a Gutenberg-authored post body) and the incidental case (an
 * Elementor text widget that pastes literal Gutenberg comment markup as
 * plain text, observed in real WordPress exports).
 *
 * @api
 *
 * @spec G-013 — decode `_elementor_data` into semantic HTML
 * @spec G-029 — strip Gutenberg block-delimiter comments
 * @spec FR-017 — `wordpress_*` plugin id naming
 */
final class WordPressBuilderContentDecode implements ProcessPluginInterface
{
    public const string PLUGIN_ID = 'wordpress_builder_content_decode';

    private const string ELEMENTOR_META_KEY = '_elementor_data';

    /** Matches both paired (`<!-- wp:name -->` / `<!-- /wp:name -->`) and void (`<!-- wp:name /-->`) delimiters. */
    private const string GUTENBERG_COMMENT_PATTERN = '/<!--\s*\/?wp:[^>]*-->/';

    public function __construct(
        private readonly ElementorTreeDecoder $elementorDecoder = new ElementorTreeDecoder(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

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
        if (!is_string($value)) {
            return $value;
        }

        $html = $this->decodeElementor($value, $context) ?? $value;

        return $this->stripGutenbergComments($html);
    }

    private function decodeElementor(string $value, ProcessContext $context): ?string
    {
        unset($value);

        $extra = $context->sourceRecord->field('_extra', []);
        $postmeta = is_array($extra) ? ($extra['postmeta'] ?? null) : null;
        $raw = is_array($postmeta) ? ($postmeta[self::ELEMENTOR_META_KEY] ?? null) : null;

        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '' || $trimmed === '[]') {
            return null;
        }

        $decoded = $this->elementorDecoder->decode($raw);
        if ($decoded === null) {
            $this->logger->warning('Could not decode _elementor_data payload; leaving content unchanged.', [
                'migration_id' => $context->migrationId,
                'destination_field' => $context->destinationField,
            ]);

            return null;
        }

        return $decoded;
    }

    private function stripGutenbergComments(string $html): string
    {
        $result = preg_replace(self::GUTENBERG_COMMENT_PATTERN, '', $html);

        return is_string($result) ? $result : $html;
    }
}
