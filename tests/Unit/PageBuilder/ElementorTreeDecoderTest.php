<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\PageBuilder;

use Waaseyaa\Migrate\Source\WordPress\PageBuilder\ElementorTreeDecoder;

/**
 * @internal
 */
function elementorFixture(string $name): string
{
    $path = __DIR__ . '/../../../testing/Fixtures/' . $name;
    expect(is_file($path))->toBeTrue("Elementor fixture {$name} must be committed at testing/Fixtures/.");

    return (string) file_get_contents($path);
}

// ---- absent / malformed input --------------------------------------------

it('returns null for an empty string', function () {
    expect(new ElementorTreeDecoder()->decode(''))->toBeNull();
});

it('returns null for the empty-tree sentinel', function () {
    expect(new ElementorTreeDecoder()->decode('[]'))->toBeNull();
});

it('returns null for malformed JSON (graceful degradation)', function () {
    expect(new ElementorTreeDecoder()->decode('not valid json {'))->toBeNull();
});

it('returns null when the JSON decodes to a non-array scalar', function () {
    expect(new ElementorTreeDecoder()->decode('"just a string"'))->toBeNull();
});

it('returns null when every widget in the tree is skip-listed', function () {
    $json = json_encode([
        ['elType' => 'widget', 'widgetType' => 'spacer', 'settings' => []],
        ['elType' => 'widget', 'widgetType' => 'divider', 'settings' => []],
    ]);
    expect(new ElementorTreeDecoder()->decode($json))->toBeNull();
});

// ---- POC-derived fixtures (synthetic, ported from the former page-builder package) ----

it('renders the POC about fixture with expected content and no builder leakage', function () {
    $html = new ElementorTreeDecoder()->decode(elementorFixture('elementor-poc-about.json'));

    expect($html)->not->toBeNull();
    expect($html)->toContain('Our Mission');
    expect($html)->toContain('Acme Community');
    expect($html)->toContain('12.34');

    expect(mb_stripos($html, 'elementor-'))->toBeFalse();
    expect(mb_stripos($html, 'wp-block'))->toBeFalse();
    expect(mb_stripos($html, 'class="elementor'))->toBeFalse();
    expect(mb_stripos($html, 'data-elementor'))->toBeFalse();
    expect(mb_stripos($html, '<script'))->toBeFalse();
    expect(preg_match('/\[\/?[a-z][a-z0-9_\-]*(?:[^\]]*)\]/i', $html))->toBe(0);
    expect($html)->toContain('pb-');
});

it('is deterministic for the POC about fixture', function () {
    $decoder = new ElementorTreeDecoder();
    $json = elementorFixture('elementor-poc-about.json');

    expect($decoder->decode($json))->toBe($decoder->decode($json));
});

it('renders the POC home fixture as a grid with cards, buttons, and icon boxes', function () {
    $html = new ElementorTreeDecoder()->decode(elementorFixture('elementor-poc-home.json'));

    expect($html)->not->toBeNull();
    expect($html)->toContain('pb-grid');
    expect($html)->toContain('pb-card');
    expect($html)->toContain('pb-btn');
    expect($html)->toContain('pb-iconbox');
    expect($html)->toContain('Education Program');
    expect($html)->toContain('Learn More');
    expect(mb_stripos($html, 'elementor-'))->toBeFalse();
    expect(mb_stripos($html, '<script'))->toBeFalse();
});

it('captures the hero band background variant and min-height as design intent', function () {
    $html = new ElementorTreeDecoder()->decode(elementorFixture('elementor-poc-home.json'));

    expect($html)->toContain('pb-band--tall');
    expect($html)->toContain('--pb-min-h:');
});

it('captures heading and button icon intent, rendered as inline SVG (no Font Awesome)', function () {
    $html = new ElementorTreeDecoder()->decode(elementorFixture('elementor-poc-home.json'));

    expect($html)->toContain('pb-align-center');
    expect($html)->toContain('pb-icon');
    expect($html)->toContain('<svg');
    expect(mb_stripos($html, 'fa-arrow'))->toBeFalse();
    expect(mb_stripos($html, 'fas '))->toBeFalse();
});

it('emits exactly one h1 per page across both POC fixtures', function () {
    foreach (['elementor-poc-home.json', 'elementor-poc-about.json'] as $fixture) {
        $html = new ElementorTreeDecoder()->decode(elementorFixture($fixture));
        expect(preg_match_all('/<h1\b/i', $html))->toBe(1, "exactly one <h1> in {$fixture}");
    }
});

// ---- real pass-1 payloads (Sheguiandah First Nation WXR export) ----------

it('decodes the real 52k SFN home page payload to non-empty semantic HTML with known text fragments', function () {
    $html = new ElementorTreeDecoder()->decode(elementorFixture('elementor-sfn-home.json'));

    expect($html)->not->toBeNull();
    expect($html)->not->toBe('');
    expect($html)->toContain('Aanii');
    expect($html)->toContain('Welcome to Sheguiandah First Nation');
    expect($html)->toContain('Monthly Newsletter');
});

it('decodes the real SFN home payload with no Elementor builder leakage', function () {
    $html = new ElementorTreeDecoder()->decode(elementorFixture('elementor-sfn-home.json'));

    expect(mb_stripos($html, 'elementor-'))->toBeFalse();
    expect(mb_stripos($html, 'class="elementor'))->toBeFalse();
    expect(mb_stripos($html, '<script'))->toBeFalse();
});

it('emits exactly one h1 for the real SFN home payload', function () {
    $html = new ElementorTreeDecoder()->decode(elementorFixture('elementor-sfn-home.json'));

    expect(preg_match_all('/<h1\b/i', $html))->toBe(1);
});

it('decodes a smaller real SFN payload (footer) to non-empty HTML', function () {
    $html = new ElementorTreeDecoder()->decode(elementorFixture('elementor-sfn-footer.json'));

    expect($html)->not->toBeNull();
    expect($html)->not->toBe('');
});

it('is deterministic for the real 52k SFN home payload', function () {
    $decoder = new ElementorTreeDecoder();
    $json = elementorFixture('elementor-sfn-home.json');

    expect($decoder->decode($json))->toBe($decoder->decode($json));
});
