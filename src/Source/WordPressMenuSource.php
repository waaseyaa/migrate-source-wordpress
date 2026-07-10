<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Source;

use Waaseyaa\Migrate\Source\WordPress\Exception\WxrParseException;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * Source plugin yielding one {@see SourceRecord} per WordPress `nav_menu_item`.
 *
 * WordPress represents nav menu items as ordinary posts of type
 * `nav_menu_item`; {@see WxrReader} already surfaces them as generic `'post'`
 * records with their `_menu_item_*` postmeta under `_extra['postmeta']`. This
 * source filters to just those records and flattens the WordPress-specific
 * shape into a small, menu-oriented field set (G-022).
 *
 * ## Menu identity
 *
 * A nav item's owning menu is expressed in WXR as the item's `<category
 * domain="nav_menu" nicename="...">` term reference, which the reader already
 * captures into the generic `terms` array. `menu_name` is the `nav_menu`
 * term's slug (e.g. `menu`, `portal-menu`) — verified against the real
 * Sheguiandah First Nation WXR export, which carries two menus.
 *
 * ## Link target shape
 *
 * WordPress nav items come in three `_menu_item_type` flavours:
 *
 *  - `custom` — a free-standing URL. `url` carries `_menu_item_url` verbatim;
 *    `object_type` / `object_id` are `null`.
 *  - `post_type` — links to a post/page. `url` is `null` (the destination
 *    slug/path is not knowable at source-parse time); `object_type` is the
 *    referenced post type object (`page`, `post`, a CPT, …) and `object_id` is
 *    the WordPress post id, so an application-side {@see
 *    \Waaseyaa\Migration\Plugin\Process\LookupProcessor} can resolve it
 *    against the sibling posts migration's id-map.
 *  - `taxonomy` — links to a term (category/tag). Same `url = null` shape;
 *    `object_type` is the taxonomy object (`category`, `post_tag`, …) and
 *    `object_id` is the WordPress term id.
 *
 * ## Ordering
 *
 * `weight` is WordPress's `wp:menu_order`, which the reader already captures
 * into `_extra['wp:menu_order']` (it is not one of the `_menu_item_*`
 * postmeta keys — it is a direct sibling element of `wp:post_type`).
 *
 * ## Parent resolution
 *
 * `parent_wp_id` is the raw WordPress post id of the parent nav item
 * (`_menu_item_menu_item_parent`, with the WordPress `'0'` "no parent"
 * sentinel normalized to `null`). This source does NOT resolve it to a
 * destination id — nav items typically appear in WXR document order without
 * a parent-before-child guarantee, so a single streaming pass cannot
 * guarantee the parent's id-map row exists yet. Resolve `parent_wp_id` to a
 * destination uuid application-side (e.g. a two-pass import, or a
 * post-import patch step) — see `docs/migrating-from-wordpress.md` "Menus".
 *
 * @api
 *
 * @spec G-022 — WordPress menu migration
 */
final class WordPressMenuSource implements SourcePluginInterface
{
    public const string SOURCE_TYPE = 'wp_menu_item';
    public const string PLUGIN_ID = 'wordpress_menu_item';

    private const string WP_POST_TYPE = 'nav_menu_item';

    /**
     * @param array<int|string, string> $objectTitles WP object id (post id or term id) → title, used as
     *     a fallback when a `post_type`/`taxonomy` nav item's own `<title>` is empty (WordPress leaves
     *     it empty for those flavours — see {@see self::objectTitleIndex()}). An explicit non-empty
     *     title always wins; an unresolved `object_id` leaves the title as `''`.
     */
    public function __construct(
        private readonly WxrReader $reader,
        private readonly string $migrationId = self::PLUGIN_ID,
        private readonly array $objectTitles = [],
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
        return null;
    }

    /**
     * @return iterable<SourceRecord>
     *
     * @throws SourceReadException When the WXR file cannot be read or parsed.
     */
    public function records(): iterable
    {
        try {
            foreach ($this->reader->records() as $record) {
                if ($record['type'] !== 'post') {
                    continue;
                }
                if (($record['data']['post_type'] ?? null) !== self::WP_POST_TYPE) {
                    continue;
                }

                yield new SourceRecord(
                    sourceType: self::SOURCE_TYPE,
                    fields: $this->normalize($record['data']),
                );
            }
        } catch (WxrParseException $e) {
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
                'WordPressMenuSource::sourceIdFor expected scalar id, got %s.',
                \get_debug_type($id),
            ));
        }

        return new SourceId(
            sourceType: self::SOURCE_TYPE,
            keys: ['id' => (string) $id],
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        /** @var array<string, mixed> $extra */
        $extra = is_array($data['_extra'] ?? null) ? $data['_extra'] : [];
        /** @var array<string, string> $postMeta */
        $postMeta = is_array($extra['postmeta'] ?? null) ? $extra['postmeta'] : [];

        $menuItemType = $postMeta['_menu_item_type'] ?? null;
        $isCustom = $menuItemType === 'custom';

        $objectId = $this->nullableInt($postMeta['_menu_item_object_id'] ?? null);
        $parentWpId = $this->nullableInt($postMeta['_menu_item_menu_item_parent'] ?? null);

        $resolvedObjectId = $isCustom ? null : $objectId;

        return [
            'id' => $data['id'] ?? 0,
            'title' => $this->resolveTitle($data['title'] ?? '', $resolvedObjectId),
            'url' => $isCustom ? ($postMeta['_menu_item_url'] ?? null) : null,
            'object_type' => $isCustom ? null : ($postMeta['_menu_item_object'] ?? null),
            'object_id' => $resolvedObjectId,
            'menu_name' => $this->resolveMenuName(is_array($data['terms'] ?? null) ? $data['terms'] : []),
            'parent_wp_id' => $parentWpId,
            'weight' => $this->intOrZero($extra['wp:menu_order'] ?? null),
            'enabled' => true,
            '_extra' => $extra,
        ];
    }

    /**
     * Resolves the raw item title, falling back to `$this->objectTitles[$objectId]`
     * when the raw title is empty (WordPress leaves `<title>` empty for
     * `post_type`/`taxonomy` nav items — the human-readable label lives on the
     * referenced post/term instead).
     */
    private function resolveTitle(mixed $rawTitle, ?int $objectId): string
    {
        $title = is_string($rawTitle) ? $rawTitle : '';
        if ($title !== '') {
            return $title;
        }
        if ($objectId === null) {
            return '';
        }

        return $this->objectTitles[$objectId] ?? '';
    }

    /**
     * Build a `WP object id → title` index from every `<wp:post_id>` post/page
     * and taxonomy term in the WXR document, for use as {@see self::$objectTitles}.
     *
     * WordPress leaves `<title>` empty for `post_type`/`taxonomy`-flavour nav
     * items (verified against the real Sheguiandah First Nation WXR export:
     * 15 of its 17 `nav_menu_item` records ship with an empty `<title>`) — the
     * human-readable label lives on the referenced post/page or term instead.
     * This index bridges an item's `object_id` to that title, mirroring the
     * `loginIndex()`/`slugIndex()` pattern on {@see WordPressUserSource} and
     * {@see WordPressTaxonomySource} (G-019).
     *
     * Post ids and term ids share one flat key space here (per the
     * `$objectTitles` contract) because a nav item's `object_id` is already
     * disambiguated by its own `object_type`/`_menu_item_type` — the caller
     * never needs to know which namespace a given key came from. A duplicate
     * id across posts and terms keeps whichever was encountered first in
     * document order.
     *
     * Reads the reader once; callers building both a menu source and a title
     * index should construct a fresh `WxrReader` for each pass (streaming
     * readers are not re-entrant / rewindable mid-stream).
     *
     * @return array<int, string> Keyed by WordPress post id or term id.
     *
     * @throws SourceReadException When the WXR file cannot be read or parsed.
     */
    public static function objectTitleIndex(WxrReader $reader): array
    {
        $index = [];
        try {
            foreach ($reader->records() as $record) {
                if ($record['type'] === 'post') {
                    $id = $record['data']['id'] ?? null;
                    $title = $record['data']['title'] ?? null;
                } elseif ($record['type'] === 'term') {
                    $id = $record['data']['id'] ?? null;
                    $title = $record['data']['name'] ?? null;
                } else {
                    continue;
                }

                if (!is_int($id) && !is_string($id)) {
                    continue;
                }
                $intId = (int) $id;
                if (isset($index[$intId]) || !is_string($title) || $title === '') {
                    continue;
                }
                $index[$intId] = $title;
            }
        } catch (WxrParseException $e) {
            throw new SourceReadException(
                sourceId: self::PLUGIN_ID,
                migrationId: self::PLUGIN_ID,
                reason: $e->getMessage(),
                previous: $e,
            );
        }

        return $index;
    }

    /**
     * @param list<array{taxonomy: string, slug: string}> $terms
     */
    private function resolveMenuName(array $terms): ?string
    {
        foreach ($terms as $term) {
            if ($term['taxonomy'] === 'nav_menu') {
                return $term['slug'];
            }
        }

        return null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;

        return $int === 0 ? null : $int;
    }

    private function intOrZero(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) $value;
    }
}
