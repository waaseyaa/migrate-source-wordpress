<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressTaxonomySource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\Testing\SourceConformanceTestCase;

/**
 * @internal
 */
#[CoversNothing]
final class TaxonomySourceConformanceTest extends SourceConformanceTestCase
{
    private const string FIXTURE_DIR = __DIR__ . '/../../testing/Fixtures';

    private ?string $largeFixturePath = null;

    protected function tearDown(): void
    {
        if ($this->largeFixturePath !== null && is_file($this->largeFixturePath)) {
            @unlink($this->largeFixturePath);
        }
        $this->largeFixturePath = null;
        parent::tearDown();
    }

    protected function buildPluginUnderTest(): SourcePluginInterface
    {
        return $this->buildPluginForFixture($this->buildSmallFixturePath());
    }

    protected function buildSmallFixturePath(): string
    {
        return self::FIXTURE_DIR . '/small-site.xml';
    }

    protected function buildLargeFixturePath(): string
    {
        if ($this->largeFixturePath !== null && is_file($this->largeFixturePath)) {
            return $this->largeFixturePath;
        }

        $this->largeFixturePath = sys_get_temp_dir() . '/waaseyaa_wp_term_large_' . uniqid('', true) . '.xml';
        $handle = fopen($this->largeFixturePath, 'wb');
        if ($handle === false) {
            self::fail('Unable to create large fixture for conformance test.');
        }

        fwrite($handle, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<wp:wxr_version>1.2</wp:wxr_version>

XML);

        for ($i = 1; $i <= 5000; $i++) {
            fwrite($handle, sprintf(
                "<wp:category><wp:term_id>%d</wp:term_id><wp:category_nicename>term-%d</wp:category_nicename><wp:cat_name>Term %d</wp:cat_name></wp:category>\n",
                $i,
                $i,
                $i,
            ));
        }

        fwrite($handle, "</channel>\n</rss>\n");
        fclose($handle);

        return $this->largeFixturePath;
    }

    protected function buildPluginForFixture(string $fixturePath): SourcePluginInterface
    {
        return new WordPressTaxonomySource(new WxrReader($fixturePath));
    }
}
