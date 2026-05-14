<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressCommentSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\Testing\SourceConformanceTestCase;

/**
 * @internal
 */
#[CoversNothing]
final class CommentSourceConformanceTest extends SourceConformanceTestCase
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

        $this->largeFixturePath = sys_get_temp_dir() . '/waaseyaa_wp_comment_large_' . uniqid('', true) . '.xml';
        $handle = fopen($this->largeFixturePath, 'wb');
        if ($handle === false) {
            self::fail('Unable to create large fixture for conformance test.');
        }

        fwrite($handle, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
<wp:wxr_version>1.2</wp:wxr_version>
<item>
<title>Host post</title>
<dc:creator><![CDATA[admin]]></dc:creator>
<content:encoded><![CDATA[]]></content:encoded>
<wp:post_id>9999</wp:post_id>
<wp:post_date>2025-05-01 00:00:00</wp:post_date>
<wp:post_date_gmt>2025-05-01 00:00:00</wp:post_date_gmt>
<wp:post_name>host</wp:post_name>
<wp:status>publish</wp:status>
<wp:post_parent>0</wp:post_parent>
<wp:post_type>post</wp:post_type>
<wp:post_password></wp:post_password>

XML);

        for ($i = 1; $i <= 5000; $i++) {
            fwrite($handle, sprintf(
                "<wp:comment><wp:comment_id>%d</wp:comment_id><wp:comment_author><![CDATA[A%d]]></wp:comment_author><wp:comment_content><![CDATA[Body %d]]></wp:comment_content><wp:comment_date>2025-05-01 12:00:00</wp:comment_date><wp:comment_date_gmt>2025-05-01 12:00:00</wp:comment_date_gmt><wp:comment_approved>1</wp:comment_approved><wp:comment_parent>0</wp:comment_parent><wp:comment_user_id>0</wp:comment_user_id></wp:comment>\n",
                $i,
                $i,
                $i,
            ));
        }

        fwrite($handle, "</item>\n</channel>\n</rss>\n");
        fclose($handle);

        return $this->largeFixturePath;
    }

    protected function buildPluginForFixture(string $fixturePath): SourcePluginInterface
    {
        return new WordPressCommentSource(new WxrReader($fixturePath));
    }
}
