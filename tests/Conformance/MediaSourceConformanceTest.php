<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressMediaSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\Testing\SourceConformanceTestCase;

/**
 * @internal
 */
#[CoversNothing]
final class MediaSourceConformanceTest extends SourceConformanceTestCase
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

        $this->largeFixturePath = sys_get_temp_dir() . '/waaseyaa_wp_media_large_' . uniqid('', true) . '.xml';
        $handle = fopen($this->largeFixturePath, 'wb');
        if ($handle === false) {
            self::fail('Unable to create large fixture for conformance test.');
        }

        fwrite($handle, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
<wp:wxr_version>1.2</wp:wxr_version>

XML);

        for ($i = 1; $i <= 5000; $i++) {
            fwrite($handle, sprintf(
                "<item><title>file-%d.png</title><wp:post_id>%d</wp:post_id><wp:post_type>attachment</wp:post_type><wp:post_parent>0</wp:post_parent><wp:attachment_url>https://example.test/wp-content/uploads/file-%d.png</wp:attachment_url><wp:postmeta><wp:meta_key>_wp_attached_file</wp:meta_key><wp:meta_value>file-%d.png</wp:meta_value></wp:postmeta></item>\n",
                $i,
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
        return new WordPressMediaSource(new WxrReader($fixturePath));
    }
}
