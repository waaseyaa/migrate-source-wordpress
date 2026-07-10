<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;

/**
 * Convert a post's `author_login` (the `dc:creator` string) into the
 * `wp:author_id` a {@see \Waaseyaa\Migration\Plugin\Process\LookupProcessor}
 * can key against.
 *
 * WordPress WXR posts carry authorship as a login string
 * (`WordPressPostSource::$fields['author_login']`), but
 * {@see \Waaseyaa\Migrate\Source\WordPress\Source\WordPressUserSource}
 * derives its `SourceId` from the numeric `wp:author_id` — so a direct
 * `LookupProcessor(sourceField: 'author_login', sourceType: 'wp_user')`
 * always misses. This plugin closes that gap using a login → id index built
 * once from the WXR `<wp:author>` elements (see
 * {@see \Waaseyaa\Migrate\Source\WordPress\Source\WordPressUserSource::loginIndex()}),
 * so a standard `LookupProcessor` can key on `wp:author_id` afterwards.
 *
 * Chain shape: `['author_login', new WordPressAuthorIdResolve($loginToId), new LookupProcessor(sourceField: 'author_login', migration: WpUsersToAccounts::MIGRATION_ID, sourceType: 'wp_user', keyField: 'id')]`.
 *
 * @api
 *
 * @spec G-019 — id-map reference resolution (authorship)
 */
final class WordPressAuthorIdResolve implements ProcessPluginInterface
{
    public const string PLUGIN_ID = 'wordpress_author_id_resolve';

    public const string ON_MISS_NULL = 'null';
    public const string ON_MISS_FAIL = 'fail';

    /**
     * @param array<string, int|string> $loginToId WordPress user login → `wp:author_id`.
     * @param string $sourceField Source field to read the login from when the chain has no upstream value yet. Non-empty.
     * @param string $onMiss One of {@see self::ON_MISS_NULL} (default) or {@see self::ON_MISS_FAIL}.
     *
     * @throws \InvalidArgumentException If $sourceField is empty or $onMiss is unrecognised.
     */
    public function __construct(
        private readonly array $loginToId,
        private readonly string $sourceField = 'author_login',
        private readonly string $onMiss = self::ON_MISS_NULL,
    ) {
        if ($sourceField === '') {
            throw new \InvalidArgumentException('WordPressAuthorIdResolve::$sourceField must be a non-empty string.');
        }
        if ($onMiss !== self::ON_MISS_NULL && $onMiss !== self::ON_MISS_FAIL) {
            throw new \InvalidArgumentException(\sprintf(
                'WordPressAuthorIdResolve::$onMiss must be %s or %s, got %s.',
                var_export(self::ON_MISS_NULL, true),
                var_export(self::ON_MISS_FAIL, true),
                var_export($onMiss, true),
            ));
        }
    }

    public function id(): string
    {
        return self::PLUGIN_ID;
    }

    public function stability(): string
    {
        return 'stable';
    }

    /**
     * @throws ProcessException When the login is missing from the index and `$onMiss === 'fail'`.
     */
    public function transform(mixed $value, ProcessContext $context): mixed
    {
        $login = $value ?? $context->sourceRecord->field($this->sourceField);

        if (!is_string($login) || $login === '' || !isset($this->loginToId[$login])) {
            if ($this->onMiss === self::ON_MISS_FAIL) {
                throw new ProcessException(
                    processCode: 'WORDPRESS_AUTHOR_LOGIN_MISS',
                    sourceField: $this->sourceField,
                    migrationId: $context->migrationId,
                    message: \sprintf(
                        'WordPressAuthorIdResolve: no wp:author_id in the login index for login %s.',
                        var_export($login, true),
                    ),
                );
            }

            return null;
        }

        // LookupProcessor does not normalize types (SourceId's "type
        // stability" contract, SourceId.php docblock) — WordPressUserSource
        // always casts its SourceId key to a string
        // (`keys: ['id' => (string) $id]`), so this bridge must emit the
        // same type or every lookup silently misses on an int-vs-string
        // hash mismatch.
        return (string) $this->loginToId[$login];
    }
}
