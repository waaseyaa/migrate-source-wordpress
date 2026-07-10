<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Source;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * Source plugin yielding one {@see SourceRecord} per WordPress user, read
 * directly from a live (or restored) WordPress database rather than a WXR
 * export.
 *
 * ### Why this source exists (G-018)
 *
 * WordPress's WXR export (`Wxr\WxrReader`, consumed by
 * {@see WordPressUserSource}) only serializes `<wp:author>` elements — one
 * per *post author* — because WXR is fundamentally a *content* export
 * format, not a user export format. On a real site the overwhelming
 * majority of registered accounts never authored a post (e.g. member
 * accounts that only ever logged in to view gated content), so a WXR-only
 * migration silently drops them along with any state that lives solely on
 * the account row / usermeta (consent flags, disabled/frozen status,
 * membership metadata). This source reads `{prefix}users` /
 * `{prefix}usermeta` directly so every account — not just post authors —
 * can migrate.
 *
 * ### Field-shape parity with {@see WordPressUserSource}
 *
 * This source emits the exact same base field map as the WXR
 * {@see WordPressUserSource} (`id`, `login`, `email`, `display_name`,
 * `first_name`, `last_name`, `registered`, `role`), plus two additions:
 *
 * - `status` — `{prefix}users.user_status` as an int (WordPress core rarely
 *   uses this column, but plugins sometimes repurpose it).
 * - One entry per `$metaFields` mapping (`record field name => meta_key`),
 *   read verbatim from `{prefix}usermeta` — including any PHP-serialized
 *   string, which is passed through *raw* for the caller's own process
 *   chain to unserialize (this source does not guess a shape for
 *   site-specific meta).
 *
 * `role` is the one meta-derived field this source computes itself (to
 * match the WXR source's `role` contract): it unserializes
 * `{prefix}capabilities` and takes the first truthy role key, mirroring how
 * WordPress core resolves a user's primary role. A missing capabilities row
 * or a malformed/unserializable payload yields `null` for `role` rather
 * than throwing — a malformed row must not abort the whole source.
 *
 * ### Id-map continuity (critical)
 *
 * `SOURCE_TYPE` is pinned to `WordPressUserSource::SOURCE_TYPE`
 * (`'wp_user'`) and {@see sourceIdFor()} keys the `SourceId` identically —
 * `('wp_user', ['id' => (string) $ID])`. Running this source against the
 * SAME WordPress database after a WXR-based `wp_users_to_accounts` run
 * therefore UPSERTS the id-map rows the WXR pass already created for post
 * authors (via `(migration_id, source_id_hash)` — see
 * {@see \Waaseyaa\Migration\MigrationIdMap}) instead of creating duplicate
 * destination accounts. Every other WordPress user — the 68-and-counting
 * non-author member accounts a WXR export cannot see — gets a brand new
 * id-map row on that same run.
 *
 * @api
 *
 * @spec G-018 — database-backed user source for full member migration
 */
final class WordPressDbUserSource implements SourcePluginInterface
{
    public const string SOURCE_TYPE = WordPressUserSource::SOURCE_TYPE;
    public const string PLUGIN_ID = 'wordpress_db_user';

    /**
     * @param DatabaseInterface $database Connection to the source WordPress
     *   database (or a restored copy of it). A read-only DB user is
     *   recommended — see docs/migrating-from-wordpress.md.
     * @param string $migrationId Id of the migration this source is wired into; only used to attribute {@see SourceReadException}.
     * @param string $tablePrefix WordPress table prefix (default `wp_`). Applied to `users`/`usermeta` and to the `capabilities` meta key.
     * @param array<string, string> $metaFields Map of `record field name => usermeta meta_key`. Each entry is fetched from `{prefix}usermeta` and passed through raw (no decoding). `'role'` is reserved — this source always derives `role` itself; a `metaFields` entry named `role` is ignored.
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly string $migrationId = self::PLUGIN_ID,
        private readonly string $tablePrefix = 'wp_',
        private readonly array $metaFields = [],
    ) {
    }

    public function id(): string
    {
        return self::PLUGIN_ID;
    }

    public function stability(): string
    {
        return 'stable';
    }

    public function count(): ?int
    {
        try {
            $result = $this->database
                ->select($this->usersTable(), 'u')
                ->countQuery()
                ->execute();

            foreach ($result as $row) {
                return (int) ($row['count'] ?? 0);
            }

            return 0;
        } catch (\Throwable) {
            // Non-fatal: progress reporters treat null as "unknown".
            return null;
        }
    }

    /**
     * @return iterable<SourceRecord>
     *
     * @throws SourceReadException When the database cannot be queried (missing table, connection failure, etc.).
     */
    public function records(): iterable
    {
        try {
            $metaByUserId = $this->loadMeta();

            $result = $this->database
                ->select($this->usersTable(), 'u')
                ->fields('u', ['ID', 'user_login', 'user_email', 'user_registered', 'display_name', 'user_status'])
                ->orderBy('u.ID', 'ASC')
                ->execute();

            foreach ($result as $row) {
                $id = (int) $row['ID'];
                yield new SourceRecord(
                    sourceType: self::SOURCE_TYPE,
                    fields: $this->normalize($row, $metaByUserId[$id] ?? []),
                );
            }
        } catch (SourceReadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SourceReadException(
                sourceId: self::PLUGIN_ID,
                migrationId: $this->migrationId,
                reason: $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function sourceIdFor(SourceRecord $record): SourceId
    {
        $id = $record->field('id');
        if (!is_int($id) && !is_string($id)) {
            throw new \InvalidArgumentException(\sprintf(
                'WordPressDbUserSource::sourceIdFor expected scalar id, got %s.',
                \get_debug_type($id),
            ));
        }

        return new SourceId(
            sourceType: self::SOURCE_TYPE,
            keys: ['id' => (string) $id],
        );
    }

    /**
     * @param array<string, mixed> $row One `{prefix}users` row.
     * @param array<string, mixed> $meta This user's `meta_key => meta_value` map, pre-filtered to the keys this source needs.
     *
     * @return array<string, mixed>
     */
    private function normalize(array $row, array $meta): array
    {
        $registered = $row['user_registered'] ?? null;
        if (is_string($registered) && $registered !== '') {
            $registered = $this->toIso8601($registered);
        } else {
            $registered = null;
        }

        $login = $row['user_login'] ?? '';
        $displayName = $row['display_name'] ?? '';
        if (!is_string($displayName) || $displayName === '') {
            $displayName = $login;
        }

        $capabilitiesKey = $this->tablePrefix . 'capabilities';

        $fields = [
            'id' => (int) $row['ID'],
            'login' => $login,
            'email' => $row['user_email'] ?? '',
            'display_name' => $displayName,
            'first_name' => \array_key_exists('first_name', $meta) ? $meta['first_name'] : null,
            'last_name' => \array_key_exists('last_name', $meta) ? $meta['last_name'] : null,
            'registered' => $registered,
            'role' => $this->deriveRole($meta[$capabilitiesKey] ?? null),
            '_extra' => [],
            'status' => isset($row['user_status']) ? (int) $row['user_status'] : 0,
        ];

        foreach ($this->metaFields as $field => $metaKey) {
            if ($field === 'role') {
                // 'role' is always source-derived (see class docblock); a
                // metaFields entry named 'role' never overrides it.
                continue;
            }

            $fields[$field] = \array_key_exists($metaKey, $meta) ? $meta[$metaKey] : null;
        }

        return $fields;
    }

    /**
     * Derive a WordPress user's primary role from the unserialized
     * `{prefix}capabilities` meta value (`a:1:{s:10:"subscriber";b:1;}`
     * shape — a role-name-keyed map of booleans).
     *
     * Returns `null` — never throws — when the value is missing, empty, not
     * a string, fails to unserialize, or unserializes to something other
     * than a non-empty array with at least one truthy string key. A single
     * malformed capabilities row must not abort the whole source read.
     */
    private function deriveRole(mixed $rawCapabilities): ?string
    {
        if (!is_string($rawCapabilities) || $rawCapabilities === '') {
            return null;
        }

        // unserialize() emits an E_WARNING (not just a false return) on
        // malformed input; PHPUnit's error-to-exception bridge would turn
        // that into a test warning even with `@`, so swallow it with a
        // scoped handler instead of relying on error-suppression alone.
        \set_error_handler(static fn (): bool => true);
        try {
            $decoded = \unserialize($rawCapabilities, ['allowed_classes' => false]);
        } finally {
            \restore_error_handler();
        }

        if (!is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $role => $enabled) {
            if (is_string($role) && $role !== '' && $enabled) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Batch-load usermeta for every user, restricted to the keys this
     * source actually needs (`first_name`, `last_name`, the capabilities
     * key, and every `$metaFields` value) — a single query grouped in PHP
     * rather than a per-user round trip, since a full member roster fits
     * comfortably in memory (spec scale: dozens to low hundreds of rows).
     *
     * @return array<int, array<string, mixed>> `user_id => (meta_key => meta_value)`
     */
    private function loadMeta(): array
    {
        $neededKeys = \array_values(\array_unique(\array_merge(
            ['first_name', 'last_name', $this->tablePrefix . 'capabilities'],
            \array_values($this->metaFields),
        )));

        $result = $this->database
            ->select($this->usermetaTable(), 'm')
            ->fields('m', ['user_id', 'meta_key', 'meta_value'])
            ->condition('m.meta_key', $neededKeys, 'IN')
            ->execute();

        $meta = [];
        foreach ($result as $row) {
            $userId = (int) $row['user_id'];
            $meta[$userId][(string) $row['meta_key']] = $row['meta_value'];
        }

        return $meta;
    }

    private function usersTable(): string
    {
        return $this->tablePrefix . 'users';
    }

    private function usermetaTable(): string
    {
        return $this->tablePrefix . 'usermeta';
    }

    private function toIso8601(string $raw): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new \DateTimeZone('UTC'));
        if ($dt === false) {
            return $raw;
        }

        return $dt->format(\DateTimeInterface::ATOM);
    }
}
