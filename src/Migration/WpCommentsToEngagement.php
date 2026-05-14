<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Migration;

use Waaseyaa\Migrate\Source\WordPress\Source\WordPressCommentSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\Process\LookupProcessor;

/**
 * Default WordPress comments → destination engagement migration factory.
 *
 * Assumes the consumer has an "engagement" / "comment" entity type. Consumers
 * without one should override or skip this migration entirely.
 *
 * Cross-migration lookups:
 * - `post_id` (wp_post id) → resolved via {@see LookupProcessor} against
 *   {@see WpPostsToArticles}'s id-map.
 * - `parent_id` (wp_comment id) → resolved against this migration's own
 *   id-map; M-002's runner handles intra-migration topological ordering.
 *
 * `user_login` is left as a string field — author resolution by login is a
 * package limitation (WordPressUserSource keys SourceId by wp_user id, not
 * by login). Consumers needing the linked account uuid resolve it at the
 * destination boundary.
 *
 * @api
 *
 * @spec FR-025 — default comments migration
 */
final class WpCommentsToEngagement
{
    public const string MIGRATION_ID = 'wp_comments_to_engagement';

    public function __construct(
        private readonly WxrReader $reader,
        private readonly DestinationPluginInterface $destination,
    ) {
    }

    public function definition(): MigrationDefinition
    {
        return new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: new WordPressCommentSource($this->reader, self::MIGRATION_ID),
            process: [
                'author' => 'author',
                'author_email' => 'author_email',
                'author_url' => 'author_url',
                'author_ip' => 'author_ip',
                'content' => 'content',
                'published_at' => 'published_at',
                'approved' => 'approved',
                'comment_type' => 'comment_type',
                'user_login' => 'user_login',
                'post_id' => new LookupProcessor(
                    sourceField: 'post_id',
                    migration: WpPostsToArticles::MIGRATION_ID,
                    sourceType: 'wp_post',
                    keyField: 'id',
                ),
                'parent_id' => new LookupProcessor(
                    sourceField: 'parent_id',
                    migration: self::MIGRATION_ID,
                    sourceType: 'wp_comment',
                    keyField: 'id',
                ),
            ],
            destination: $this->destination,
            dependencies: [
                WpUsersToAccounts::MIGRATION_ID,
                WpPostsToArticles::MIGRATION_ID,
            ],
            description: 'Imports WordPress comments as engagement records. Consumers without an engagement entity should skip or override.',
        );
    }
}
