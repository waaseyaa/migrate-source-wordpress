<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\PageBuilder;

/**
 * One node in a decoded Elementor block tree.
 *
 * Blocks form a tree so layout intent survives decoding: a structural block
 * (band, card) carries child blocks and layout data (background variant,
 * layout mode), while a leaf block (heading, text, image, button, list,
 * icon_box, gallery, html) carries rendered HTML.
 *
 * `type` is a small internal vocabulary (see the constants) that
 * {@see ElementorTreeDecoder} maps Elementor's native widget/element types
 * onto. `data` is type-specific, `children` are nested blocks (empty for
 * leaves), and `html` is the rendered HTML for leaf blocks (structural
 * blocks render from their children via {@see BlockRenderer}).
 *
 * Ported from the framework's former `waaseyaa/page-builder` package
 * (`ElementorDecoder` decoding strategy) as a connector-local implementation
 * detail — see G-013.
 */
final readonly class Block
{
    // Structural.
    public const string BAND = 'band';   // a full-width section/row; data: variant, layout, min_height, align, bg_image
    public const string CARD = 'card';   // a styled box (white rounded card)

    // Leaf.
    public const string HEADING = 'heading';
    public const string TEXT = 'text';
    public const string IMAGE = 'image';
    public const string BUTTON = 'button';
    public const string LIST = 'list';
    public const string ICON_BOX = 'icon_box'; // title + description (+ optional link)
    public const string GALLERY = 'gallery';   // a set of images
    public const string HTML = 'html';

    /**
     * @param string $type One of the type constants.
     * @param array<string, mixed> $data Type-specific payload.
     * @param string $html Rendered HTML for leaf blocks; empty for structural blocks (rendered from {@see $children}).
     * @param list<Block> $children Nested blocks (empty for leaves).
     */
    public function __construct(
        public string $type,
        public array $data = [],
        public string $html = '',
        public array $children = [],
    ) {}
}
