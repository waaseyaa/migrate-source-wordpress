<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressUserSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;

/**
 * Smoke tests against the edge-case fixtures committed under
 * `testing/Fixtures/edge-cases/`. These guard memory + charset behaviour
 * for the medium-/long-tail cases that don't fit the small-site fixture.
 *
 * @internal
 */
#[CoversNothing]
final class EdgeCaseFixturesTest extends TestCase
{
    private const string EDGE_DIR = __DIR__ . '/../../testing/Fixtures/edge-cases';

    public function test_rtl_language_fixture_preserves_unicode_verbatim(): void
    {
        $userSource = new WordPressUserSource(new WxrReader(self::EDGE_DIR . '/rtl-language.xml'));
        $records = iterator_to_array($userSource->records(), false);

        self::assertCount(2, $records);

        $hebrewName = $records[0]->field('display_name');
        $arabicName = $records[1]->field('display_name');

        self::assertIsString($hebrewName);
        self::assertIsString($arabicName);
        self::assertSame('כוכב', $hebrewName);
        self::assertSame('نَوْرَس', $arabicName);
    }

    public function test_rtl_post_content_preserves_bidi_marks_and_mixed_script(): void
    {
        $postSource = new WordPressPostSource(new WxrReader(self::EDGE_DIR . '/rtl-language.xml'));
        $records = iterator_to_array($postSource->records(), false);

        self::assertCount(1, $records);
        $title = $records[0]->field('title');
        $content = $records[0]->field('content');

        self::assertIsString($title);
        self::assertIsString($content);
        self::assertStringContainsString('שלום עולם', $content);
        self::assertStringContainsString('مرحبا بالعالم', $content);
    }

    public function test_large_entries_fixture_streams_under_memory_budget(): void
    {
        gc_collect_cycles();
        $baseline = memory_get_usage(true);

        $postSource = new WordPressPostSource(new WxrReader(self::EDGE_DIR . '/large-entries.xml'));
        $count = 0;
        foreach ($postSource->records() as $record) {
            unset($record);
            $count++;
        }

        gc_collect_cycles();
        $peak = memory_get_peak_usage(true);
        $growth = $peak - $baseline;

        self::assertGreaterThan(0, $count);
        // 50 MB ceiling matches the conformance suite C5 bound.
        self::assertLessThanOrEqual(50 * 1024 * 1024, $growth);
    }
}
