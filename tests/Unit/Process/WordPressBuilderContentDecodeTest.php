<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Process;

use Psr\Log\LogLevel;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressBuilderContentDecode;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\SourceRecord;

/**
 * @internal
 */
function builderDecodeContext(array $extra = []): ProcessContext
{
    return new ProcessContext(
        sourceRecord: new SourceRecord('wp_post', ['id' => 1, '_extra' => $extra]),
        migrationId: 'wp_posts_to_articles',
        destinationField: 'content',
        lookup: static fn(string $m, $id) => null,
    );
}

/**
 * @internal
 *
 * Minimal in-memory PSR-3 logger spy: no framework dependency needed for a
 * single-package test.
 */
final class BuilderDecodeSpyLogger implements \Psr\Log\LoggerInterface
{
    use \Psr\Log\LoggerTrait;

    /** @var list<array{level: mixed, message: string, context: array}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

function elementorFixtureJson(string $name): string
{
    $path = __DIR__ . '/../../../testing/Fixtures/' . $name;
    expect(is_file($path))->toBeTrue("Elementor fixture {$name} must be committed at testing/Fixtures/.");

    return (string) file_get_contents($path);
}

it('declares plugin metadata', function () {
    $plugin = new WordPressBuilderContentDecode();
    expect($plugin->id())->toBe('wordpress_builder_content_decode');
    expect($plugin->stability())->toBe('stable');
});

it('returns non-string values unchanged', function () {
    $plugin = new WordPressBuilderContentDecode();
    expect($plugin->transform(42, builderDecodeContext()))->toBe(42);
    expect($plugin->transform(null, builderDecodeContext()))->toBeNull();
});

// ---- (d) pass-through when _elementor_data is absent ----------------------

it('passes original content through unchanged when _elementor_data is absent', function () {
    $plugin = new WordPressBuilderContentDecode();
    $context = builderDecodeContext(['postmeta' => []]);

    $out = $plugin->transform('<p>Plain WordPress content.</p>', $context);
    expect($out)->toBe('<p>Plain WordPress content.</p>');
});

it('passes content through unchanged when _extra has no postmeta key at all', function () {
    $plugin = new WordPressBuilderContentDecode();
    $context = builderDecodeContext([]);

    $out = $plugin->transform('<p>Plain content.</p>', $context);
    expect($out)->toBe('<p>Plain content.</p>');
});

it('passes content through silently for the empty-tree sentinel ([]), without warning', function () {
    $logger = new BuilderDecodeSpyLogger();
    $plugin = new WordPressBuilderContentDecode(logger: $logger);
    $context = builderDecodeContext(['postmeta' => ['_elementor_data' => '[]']]);

    $out = $plugin->transform('<p>Original.</p>', $context);
    expect($out)->toBe('<p>Original.</p>');
    expect($logger->records)->toBe([]);
});

// ---- content REPLACE when _elementor_data decodes ------------------------

it('replaces empty post content with decoded Elementor HTML when _elementor_data is present', function () {
    $plugin = new WordPressBuilderContentDecode();
    $context = builderDecodeContext([
        'postmeta' => [
            '_elementor_data' => elementorFixtureJson('elementor-poc-about.json'),
            '_elementor_edit_mode' => 'builder',
        ],
    ]);

    $out = $plugin->transform('', $context);
    expect($out)->toContain('Our Mission');
    expect($out)->toContain('Acme Community');
});

it('decodes the real 52k SFN home page payload and replaces the (empty) post content', function () {
    $plugin = new WordPressBuilderContentDecode();
    $context = builderDecodeContext([
        'postmeta' => [
            '_elementor_data' => elementorFixtureJson('elementor-sfn-home.json'),
            '_elementor_edit_mode' => 'builder',
        ],
    ]);

    $out = $plugin->transform('', $context);
    expect($out)->toBeString();
    expect($out)->not->toBe('');
    expect($out)->toContain('Aanii');
    expect($out)->toContain('Welcome to Sheguiandah First Nation');
});

// ---- (f) _elementor_edit_mode gate (verifier finding 1 / G-013 follow-up) --

it('passes original content through unchanged when _elementor_data is present but _elementor_edit_mode is absent (real post 975 shape: stale Elementor residue on a non-builder page)', function () {
    $plugin = new WordPressBuilderContentDecode();
    $context = builderDecodeContext([
        'postmeta' => [
            // Real shape from the SFN corpus, post 975 "1850 Robinson Huron
            // Treaty": _elementor_data is present (stale residue from a past
            // edit-mode toggle) but _elementor_edit_mode is ABSENT, meaning
            // WordPress renders post_content, not the Elementor tree.
            '_elementor_data' => elementorFixtureJson('elementor-poc-about.json'),
            '_elementor_template_type' => 'wp-post',
            '_elementor_version' => '3.24.7',
        ],
    ]);

    $liveContent = '<p>The live post_content body for this page — not the stale Elementor tree.</p>';
    $out = $plugin->transform($liveContent, $context);

    expect($out)->toBe($liveContent);
    expect($out)->not->toContain('Our Mission');
});

it('decodes _elementor_data when _elementor_edit_mode is builder (positive control)', function () {
    $plugin = new WordPressBuilderContentDecode();
    $context = builderDecodeContext([
        'postmeta' => [
            '_elementor_data' => elementorFixtureJson('elementor-poc-about.json'),
            '_elementor_edit_mode' => 'builder',
        ],
    ]);

    $out = $plugin->transform('', $context);
    expect($out)->toContain('Our Mission');
    expect($out)->toContain('Acme Community');
});

it('passes content through unchanged when _elementor_edit_mode is present but not "builder"', function () {
    $plugin = new WordPressBuilderContentDecode();
    $context = builderDecodeContext([
        'postmeta' => [
            '_elementor_data' => elementorFixtureJson('elementor-poc-about.json'),
            '_elementor_edit_mode' => 'classic',
        ],
    ]);

    $liveContent = '<p>Classic-editor content.</p>';
    $out = $plugin->transform($liveContent, $context);

    expect($out)->toBe($liveContent);
});

// ---- (e) malformed JSON -> graceful passthrough + warning ------------------

it('keeps original content and logs a warning when _elementor_data is malformed JSON', function () {
    $logger = new BuilderDecodeSpyLogger();
    $plugin = new WordPressBuilderContentDecode(logger: $logger);
    $context = builderDecodeContext(['postmeta' => [
        '_elementor_data' => 'not valid json {',
        '_elementor_edit_mode' => 'builder',
    ]]);

    $out = $plugin->transform('<p>Fallback body.</p>', $context);
    expect($out)->toBe('<p>Fallback body.</p>');

    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['level'])->toBe(LogLevel::WARNING);
    expect($logger->records[0]['context']['migration_id'])->toBe('wp_posts_to_articles');
    expect($logger->records[0]['context']['destination_field'])->toBe('content');
});

it('keeps original content and logs a warning when the tree yields no renderable blocks', function () {
    $logger = new BuilderDecodeSpyLogger();
    $plugin = new WordPressBuilderContentDecode(logger: $logger);
    $onlySkippedWidgets = json_encode([
        ['elType' => 'widget', 'widgetType' => 'spacer', 'settings' => []],
    ]);
    $context = builderDecodeContext(['postmeta' => [
        '_elementor_data' => $onlySkippedWidgets,
        '_elementor_edit_mode' => 'builder',
    ]]);

    $out = $plugin->transform('<p>Fallback.</p>', $context);
    expect($out)->toBe('<p>Fallback.</p>');
    expect($logger->records)->toHaveCount(1);
});

// ---- (c) Gutenberg block-comment stripping (G-029) -------------------------

it('strips Gutenberg block-delimiter comments while preserving inner HTML', function () {
    $plugin = new WordPressBuilderContentDecode();
    $context = builderDecodeContext(['postmeta' => []]);

    $input = "<!-- wp:paragraph -->\n<p>Hello.</p>\n<!-- /wp:paragraph -->";
    $out = $plugin->transform($input, $context);

    expect($out)->not->toContain('<!-- wp:');
    expect($out)->not->toContain('<!-- /wp:');
    expect($out)->toContain('<p>Hello.</p>');
});

it('strips a void-style Gutenberg comment with attributes', function () {
    $plugin = new WordPressBuilderContentDecode();
    $context = builderDecodeContext(['postmeta' => []]);

    $input = '<!-- wp:aioseo/ai-assistant {"tone":"formal","audience":"general"} /-->Body text';
    $out = $plugin->transform($input, $context);

    expect($out)->not->toContain('<!-- wp:');
    expect($out)->toContain('Body text');
});

it('strips Gutenberg comments from a real SFN election-notice body, preserving all headings and text', function () {
    $plugin = new WordPressBuilderContentDecode();
    $context = builderDecodeContext(['postmeta' => []]);

    $path = __DIR__ . '/../../../testing/Fixtures/gutenberg-sfn-election-notice.html';
    $input = (string) file_get_contents($path);

    $out = $plugin->transform($input, $context);

    expect($out)->not->toContain('<!-- wp:');
    expect($out)->not->toContain('<!-- /wp:');
    expect($out)->toContain('STATEMENT OF ELECTED CANDIDATES');
    expect($out)->toContain('TO THE OFFFICE OF CHIEF');
    expect($out)->toContain('WAINDUBENCE, PEARL');
    expect($out)->toContain('<h2 class="wp-block-heading">');
});

it('strips Gutenberg comments from decoded Elementor output too (when the payload embeds literal comment text)', function () {
    $plugin = new WordPressBuilderContentDecode();
    $embeddedCommentJson = json_encode([[
        'elType' => 'widget',
        'widgetType' => 'text-editor',
        'settings' => ['editor' => '<p>See below.</p><!-- wp:paragraph --><p>Nested.</p><!-- /wp:paragraph -->'],
    ]]);
    $context = builderDecodeContext(['postmeta' => [
        '_elementor_data' => $embeddedCommentJson,
        '_elementor_edit_mode' => 'builder',
    ]]);

    $out = $plugin->transform('', $context);
    expect($out)->toContain('See below.');
    expect($out)->toContain('Nested.');
    expect($out)->not->toContain('<!-- wp:');
    expect($out)->not->toContain('<!-- /wp:');
});
