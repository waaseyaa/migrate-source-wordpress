<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Migration;

use Waaseyaa\Migrate\Source\WordPress\Source\WordPressMenuSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;

/**
 * Default WordPress nav menu items → `menu_link` migration factory (G-022).
 *
 * Targets the `waaseyaa/menu` package's `menu_link` entity type. `menu_name`
 * is that entity's bundle key ({@see \Waaseyaa\Menu\MenuLink}), but it varies
 * per record (a WXR export commonly carries more than one menu — the
 * Sheguiandah First Nation reference export has two: `menu`, `portal-menu`).
 * `EntityDestination` only applies `DestinationRecord::$bundle` for a
 * *fixed*, definition-level bundle (`MigrationDefinition` PHPDoc: "bundle
 * metadata ... travels through DestinationPluginInterface's constructor —
 * NOT through the process map"), and the runner never populates
 * `DestinationRecord::$bundle` from the process map at all
 * ({@see \Waaseyaa\Migration\Runner\MigrationRunner::processOne()} always
 * constructs it with `bundle: null`). So this factory instead puts
 * `menu_name` in the process *values* map — `EntityDestination::write()`
 * applies every values-map entry via `$entity->set($key, $value)`, and
 * because `menu_name` is also `MenuLink`'s configured bundle key, setting it
 * as a plain field sets the bundle too.
 *
 * `parent_id` is intentionally NOT in the process map. WXR nav items are not
 * guaranteed parent-before-child in document order, so a single streaming
 * pass cannot promise the parent's id-map row exists when a child is
 * processed. Wire parent resolution yourself with a {@see
 * \Waaseyaa\Migration\Plugin\Process\LookupProcessor} against this
 * migration's own id-map (self-referential lookup, `sourceType:
 * 'wp_menu_item'`) once you've confirmed your source data — or your import
 * ordering — makes that safe; see `docs/migrating-from-wordpress.md`
 * "Menus" for the recipe and its caveats.
 *
 * `url` passes through from {@see WordPressMenuSource} as-is: populated for
 * `custom` link items, `null` for `post_type` / `taxonomy` items (those
 * carry `object_type` + `object_id` instead, for id-map / path-alias
 * resolution the app wires separately).
 *
 * @api
 *
 * @spec G-022 — WordPress menu migration
 */
final class WpMenusToMenuLinks
{
    public const string MIGRATION_ID = 'wp_menus_to_menu_links';

    public function __construct(
        private readonly WxrReader $reader,
        private readonly DestinationPluginInterface $destination,
    ) {
    }

    public function definition(): MigrationDefinition
    {
        return new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: new WordPressMenuSource($this->reader, self::MIGRATION_ID),
            process: [
                'title' => 'title',
                'url' => 'url',
                'menu_name' => 'menu_name',
                'weight' => 'weight',
                'enabled' => 'enabled',
            ],
            destination: $this->destination,
            description: 'Example WordPress nav menu items → menu_link migration (G-022). '
                . 'Parent-link and object-id (post/term) URL resolution are app-side wiring — see docs/migrating-from-wordpress.md.',
        );
    }
}
