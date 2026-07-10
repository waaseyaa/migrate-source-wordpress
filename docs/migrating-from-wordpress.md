# Migrating your WordPress site to Waaseyaa

This is a step-by-step walkthrough for WordPress site owners. If you can log into your WP admin and copy a folder over SSH, you can follow this guide. Plan for about an hour for a small-to-medium site.

## What you'll need

- **WordPress admin access** to your existing site (you'll click "Export" once).
- **A Waaseyaa application** already installed somewhere — your own server, a cloud VM, or local dev box.
- **Basic command-line comfort** — running `composer require`, `bin/waaseyaa import:run-all`, and an `rsync` command.
- **About an hour** for a typical blog (~100 posts, a few hundred MB of media). Bigger sites scale linearly.

You do **not** need PHP knowledge. You do not need to understand the migration framework internals.

---

## Step 1 — Export your WordPress content (≈5 min)

In your WP admin:

1. Go to **Tools → Export**.
2. Choose **All content**.
3. Click **Download Export File**. WordPress writes a single `.xml` file (called a "WXR" file — *WordPress eXtended RSS*).
4. Save it somewhere your Waaseyaa server can reach. Common choices:
   - Upload to your Waaseyaa server via `scp`/`rsync`.
   - Drop it in `storage/imports/wp-export.xml` of your Waaseyaa app.

The WXR file contains every post, page, user, term, comment, and attachment **metadata** (URLs, captions, alt text, etc). It does **not** contain the actual image/video bytes — those still live in `wp-content/uploads/`. Step 2 handles those.

---

## Step 2 — Get the media files where Waaseyaa can read them (≈10 min)

You have two choices. Pick one:

### Option A — Copy media locally (recommended, faster)

`rsync` the `wp-content/uploads/` directory from your WP host to your Waaseyaa server:

```bash
rsync -avz user@wp-host:/var/www/wp-content/uploads/ /var/www/waaseyaa/storage/imports/uploads/
```

Note the trailing slashes. After this finishes, you'll point the migration at the local `uploads/` directory.

The connector's `file_path` for each attachment is **uploads-relative** (e.g. `2024/06/photo.jpg`, no `wp-content/uploads/` prefix) — join it directly to the `storage/imports/uploads/` root above (`storage/imports/uploads/<file_path>`). Do not prepend `wp-content/uploads/` yourself; the connector already strips it.

### Option B — Read media over HTTP (slower, no rsync needed)

If `rsync` is impractical (managed WP host, firewall, etc.), the migration can fetch each attachment over HTTPS during the import. This is slower (one HTTP request per file) and requires your WP site to stay online during import — but it works.

You'll need to wire an HTTP fetcher implementing `Waaseyaa\Migrate\Source\WordPress\Media\MediaFetcherInterface`. The customization guide shows the wiring pattern.

For small sites (< 50 attachments), Option B is fine. For larger sites, use Option A.

---

## Step 3 — Install the WordPress reader package (≈2 min)

From your Waaseyaa app directory:

```bash
composer require waaseyaa/migrate-source-wordpress
```

That's it. The package's `ServiceProvider` auto-registers via Waaseyaa's `extra.waaseyaa.providers` mechanism.

---

## Step 4 — Configure the migration (≈10 min)

Create a small bootstrap file at `app/Migration/wp.php` (or wherever your app keeps migration wiring). The file builds the five default migrations and hands them to the migration runner.

```php
<?php

use Waaseyaa\Migrate\Source\WordPress\Migration\WpUsersToAccounts;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpTermsToTaxonomy;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpMediaToEntities;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpCommentsToEngagement;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;

$wxrPath = __DIR__ . '/../../storage/imports/wp-export.xml';
$reader  = new WxrReader($wxrPath);

// Your Waaseyaa app supplies these destinations — one per entity type:
$accountDest    = /* your EntityDestination for the "account" entity */;
$taxonomyDest   = /* …for the "taxonomy_term" entity */;
$mediaDest      = /* …for the "media" entity */;
$articleDest    = /* …for the "article" entity (or whatever post-equivalent you use) */;
$engagementDest = /* …for the "engagement" entity, OR null to skip comments */;

return [
    (new WpUsersToAccounts($reader, $accountDest))->definition(),
    (new WpTermsToTaxonomy($reader, $taxonomyDest))->definition(),
    (new WpMediaToEntities($reader, $mediaDest))->definition(),
    (new WpPostsToArticles($reader, $articleDest))->definition(),
    (new WpCommentsToEngagement($reader, $engagementDest))->definition(),
];
```

The `WpPostsToArticles` name is an **example** — your destination might be called `BlogPost` or `Teaching` or `NewsItem`. Rename freely; see the [customization guide](customization.md).

### Trash, status, and post-type filtering

`WordPressPostSource` (the source `WpPostsToArticles` builds on) has three related behaviors worth knowing about:

- **Trashed items are skipped by default.** Anything sitting in the WordPress trash (`wp:status` = `trash`) is *not* imported unless you explicitly opt in:

  ```php
  new WordPressPostSource($reader, includeTrashed: true);
  ```

- **`status` is always the raw WordPress string** — `publish`, `draft`, `pending`, `private`, `future`, etc. — never flattened to a boolean. If your destination wants a simple published/unpublished flag, add a small mapping step to the `status` entry in your process map (e.g. a process plugin that maps `'publish' => true`, everything else `false`) rather than relying on the source to decide that for you.

- **`post_type` can be filtered at the source** via the `postTypes` constructor argument — a non-empty list of post-type strings. This is the recommended way to split one WXR export into several bundle-specific migrations.

#### Recipe: splitting one WXR file into per-bundle migrations

If your WP site mixes `page`, `post`, and a custom post type (say, `event`) but your Waaseyaa app models these as separate entity bundles, run three `WordPressPostSource` instances against the same reader, each filtered to one post type, feeding three separate `MigrationDefinition`s:

```php
$pagesSource = new WordPressPostSource($reader, migrationId: 'wp_pages', postTypes: ['page']);
$postsSource = new WordPressPostSource($reader, migrationId: 'wp_posts', postTypes: ['post']);
$eventsSource = new WordPressPostSource($reader, migrationId: 'wp_events', postTypes: ['event']);
```

Build a distinct `MigrationDefinition` per source (clone `WpPostsToArticles`'s `definition()` body per bundle, per the customization guide), each pointed at its own destination. Records whose `post_type` isn't in the list are skipped by that source entirely, so there is no overlap or double-import risk between the three migrations.

---

## Step 5 — Run the import (≈5–60 min)

```bash
bin/waaseyaa import:run-all
```

The migration runner walks the five migrations in their declared dependency order: **users → terms → media → posts → comments**. Each step writes to your destination and records a row in the `migration_id_map` table so re-runs are idempotent.

What you'll see:

- **Progress lines** per migration, with per-record counts.
- **Per-record warnings** for things like a missing attachment file (the run continues; the warning is recorded for cleanup).
- **A summary table** at the end with totals + the run ID.

If something halts the run mid-way (network error, bad XML record in strict mode), fix the cause and re-run. The id-map ensures already-imported records aren't re-imported.

---

## Step 6 — Verify (≈10 min)

Browse the imported content in your Waaseyaa admin:

- **Users** should appear with their original usernames + emails. Passwords are **not** carried over — every user has `must_reset_password = true` set, so on first login they're prompted to choose a new password.
- **Taxonomies** match your WP categories and tags. Hierarchical categories preserve their parent relationships.
- **Media** is reachable. If you used Option A (local copy), the destination URLs reference your new Waaseyaa media location. If Option B, the original WP URLs still point at the WP host (rewriting is operator-configurable per the customization guide).
- **Posts** are present with shortcodes stripped and oEmbed-capable URLs detected. Authors link back to the imported accounts.
- **Comments** thread correctly (replies still point at their parent comment).

---

## Menus (optional, G-022)

WordPress nav menu items (`nav_menu_item` posts) are **not** part of the five default migrations in Step 4 — wire them separately once your content migrations are working, since menu links reference the posts/pages/terms those migrations create.

Add `WpMenusToMenuLinks` to your bootstrap file, targeting `waaseyaa/menu`'s `menu_link` entity type:

```php
use Waaseyaa\Migrate\Source\WordPress\Migration\WpMenusToMenuLinks;

$menuLinkDest = /* your EntityDestination for the "menu_link" entity */;

return [
    // ...the five default migrations from Step 4...
    (new WpMenusToMenuLinks($reader, $menuLinkDest))->definition(),
];
```

### What comes across automatically

- `title`, `weight` (from WordPress's menu-order), and `enabled` (always `true` — WordPress doesn't export a disabled state for nav items).
- `url` — populated only for **custom link** items (`_menu_item_type = custom`); this is the operator-typed URL, copied verbatim.
- `menu_name` — the menu this item belongs to, taken from the item's `nav_menu` term slug (e.g. `menu`, `portal-menu` — a WXR export commonly contains more than one menu). `menu_name` is also `menu_link`'s bundle, so this one field both classifies and buckets each link into its menu.

### What needs app-side wiring

**Page/post/category links (`object_type` + `object_id`).** WordPress nav items that point at a page, post, or term (`_menu_item_type` of `post_type` or `taxonomy`) carry `null` for `url` and instead expose `object_type` (`page`, `post`, a custom post type, `category`, `post_tag`, …) and `object_id` (the WordPress post/term id). `WordPressMenuSource` cannot resolve these to a destination URL by itself — that needs either:

- A `Waaseyaa\Migration\Plugin\Process\LookupProcessor` against the sibling posts/terms migration's id-map (`migration: WpPostsToArticles::MIGRATION_ID` or `WpTermsToTaxonomy::MIGRATION_ID`, `sourceType: WordPressPostSource::SOURCE_TYPE` / `WordPressTaxonomySource::SOURCE_TYPE`, `keyField: 'object_id'` via a small wrapper that reads `object_id` as the lookup key) to get the destination uuid, followed by
- Your own path-alias resolution to turn that uuid into a URL — see "URL preservation" below for the package's stock `WpPostsToPathAliases` / `IdMapMediaUrlResolver` pieces (G-020); menu items pointing at posts/terms can reuse the same `path_alias` rows those produce.

**Parent links (`parent_wp_id`).** `WpMenusToMenuLinks` deliberately leaves `parent_id` out of its process map. WXR does not guarantee nav items appear parent-before-child in document order, so a single streaming pass through `WpMenusToMenuLinks` cannot promise a parent's id-map row exists yet when its child is processed — a naive `LookupProcessor` on `parent_wp_id` would intermittently miss. Two supported approaches:

1. **Two-pass import.** Run `WpMenusToMenuLinks` twice. The first pass populates the id-map for every nav item (parents included, since they're ordinary `write()` calls regardless of hierarchy). The second pass adds a `LookupProcessor` for `parent_id` (self-referential: `migration: WpMenusToMenuLinks::MIGRATION_ID`, `sourceType: WordPressMenuSource::SOURCE_TYPE`, `keyField: 'id'` reading `parent_wp_id`) — by the second pass every parent already has an id-map row, so the lookup always hits.
2. **Post-import patch step.** Run the default single-pass migration, then a small one-off script that reads each imported `menu_link`'s original `parent_wp_id` (round-tripped through your destination, e.g. stashed in a temporary field) and sets `parent_id` via `MigrationIdMap::lookupDestination()` directly.

Either way, verify the result in your admin — nav hierarchy is easy to eyeball once a handful of top-level items render correctly.

---

## URL preservation (path aliases + media URL rewriting) (optional, G-020)

Without this step, WordPress's hierarchical URLs collapse: a permalink like `/members/rht/` has no destination-side equivalent unless you build one, so links to it 404 (or fall back to a bare content-id URL) after the migration. Similarly, in-body `<img>`/`<a>` references to `/wp-content/uploads/...` stay pointed at the old WP host unless a resolver is wired for {@see `WordPressMediaRewriteUrl`} — a real field migration hit both gaps: 5 flat-slug collisions in the URL map (hierarchical paths flattened to their bare slug, colliding when two posts shared a slug under different parents) and 97 in-body upload references left unrewritten across 32 post bodies.

This package ships two stock pieces that close both gaps, both consulting the `migration_id_map` produced by your other migrations rather than requiring new bookkeeping.

### Path aliases: `WpPostsToPathAliases`

Targets `waaseyaa/path`'s `path_alias` entity type. **Must run after** your posts migration (`WpPostsToArticles` or your renamed clone of it) — its `path` field looks up that migration's id-map, so the posts have to exist first.

```php
use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToPathAliases;

$postsDest = /* your EntityDestination for the "article"/"node" entity */;
$pathAliasDest = /* your EntityDestination for the "path_alias" entity */;

// Bridges a destination entity's uuid (from the posts migration's id-map)
// to the serial/system id your `path` field needs (e.g. "/node/42"). How
// you implement this depends on your entity storage — typically a
// `$repository->find($uuid)->id()` call, or a small in-memory map built
// while writing posts.
$uuidToId = function (string $entityType, string $uuid): int|string|null {
    return $yourEntityRepository->findByUuid($entityType, $uuid)?->id();
};

return [
    // ...users, terms, media, posts as before...
    (new WpPostsToArticles($reader, $postsDest))->definition(),
    (new WpPostsToPathAliases($reader, $pathAliasDest, $uuidToId))->definition(),
];
```

What you get per post/page:

- `alias` — the normalized permalink path (`https://old-site.example/members/rht/` → `/members/rht`). Domain and query string are always stripped; a trailing slash is stripped too. Permalinks with no real path component — WordPress's "Plain" structure (`?p=100`) or an unrecognised custom-post-type rewrite (`?project=104`) — produce `alias: null`, since there is no hierarchical segment to preserve and aliasing every such post to the bare `/` root would collide. The bare homepage link also produces `null` — front-page aliasing is a site-level decision, not a per-post one.
- `path` — the destination system path (`/node/42`) built by chaining a `LookupProcessor` against the posts migration's id-map (which yields a destination *uuid*) into your `$uuidToId` closure. Change the id-map lookup target with the `postsMigrationId` constructor argument if you renamed/cloned `WpPostsToArticles`; change the prefix with `systemPathPrefix` (default `/node/`) if your destination entity type isn't `node`.
- `langcode` (default `'en'`, override via the constructor) and `status` (always `true`).

The plugin does not attempt to detect "this alias equals the destination's own auto-generated slug and should be skipped" — the migration platform's process-plugin chain has no per-record skip primitive (only the runner-level dry-run / idempotent-hash-match skips exist), and the plugin has no visibility into your destination's slugging algorithm anyway. A redundant alias that happens to match the auto-slug is harmless; re-running the migration does not duplicate rows (the id-map keeps writes idempotent).

### Media URL rewriting: `IdMapMediaUrlResolver`

`WordPressMediaRewriteUrl` (used in your posts migration's `content` process chain, see Step 4) needs a `\Closure(string $relativePath): ?string` resolver — the package ships one, `Media\IdMapMediaUrlResolver`, built from your media migration's id-map instead of requiring you to write that lookup yourself.

**Must run after your media migration** (`WpMediaToEntities`) has populated the id-map — a body referencing an attachment that hasn't been migrated yet resolves to `null` (left untouched, with a warning), not an error.

```php
use Waaseyaa\Migrate\Source\WordPress\Media\IdMapMediaUrlResolver;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressMediaRewriteUrl;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressMediaSource;
use Waaseyaa\Migration\MigrationIdMap;

$idMap = new MigrationIdMap($database); // the same DatabaseInterface your app already wires

// Uploads-relative path (e.g. "2025/05/logo.png") -> WordPress attachment id,
// built straight from the WXR export.
$pathIndex = IdMapMediaUrlResolver::indexFromSource(new WordPressMediaSource($reader));

$mediaUrlResolver = new IdMapMediaUrlResolver(
    idMap: $idMap,
    mediaMigrationId: WpMediaToEntities::MIGRATION_ID,
    pathToAttachmentId: $pathIndex,
    // App decides the final URL shape for a migrated media entity.
    uuidToUrl: fn (string $entityType, string $uuid): ?string =>
        $yourEntityRepository->findByUuid($entityType, $uuid)?->publicUrl(),
);

$mediaRewrite = new WordPressMediaRewriteUrl($mediaUrlResolver->resolver());

// Wire $mediaRewrite into WpPostsToArticles's constructor as before (Step 4).
```

Any miss along the chain (path not in the index, attachment not yet migrated, `uuidToUrl` itself returns `null`) is logged as a warning and the original WP-hosted URL is left in place — safe to re-run once the gap is closed (e.g. after the media migration catches up).

### Run order

Putting it all together, the full dependency chain with both add-ons is: **users → terms → media → posts → comments → path aliases**, with media URL rewriting wired into the posts migration's `content` chain (so it runs *during* the posts migration, consuming whatever the media migration already wrote) and `WpPostsToPathAliases` running strictly after posts.

---

## Troubleshooting

### "WXR file not found" or "WXR file is not readable"

The path in `WxrReader` must be readable by the PHP process running the migration. Check ownership and the absolute path.

### "WXR record skipped due to parse errors"

The WXR file has a malformed record. By default the reader skips it with a warning and continues. To make the parser strict, pass `strict: true` to `WxrReader`. The most common culprit is a `<` character in plugin metadata that wasn't properly escaped; look in the run report for the record index and inspect the WXR file.

### "Source plugin … failed for migration … : ..."

The source plugin couldn't read the WXR. The wrapped exception message tells you which underlying error happened — usually the file went missing mid-run or the WXR version isn't 1.0 / 1.1 / 1.2.

### Media files don't load

You either skipped Step 2, or the migration is pointing at the original WP host. Check your destination's media-URL handling. The `WordPressMediaRewriteUrl` process plugin handles canonical and CDN-prefixed URLs; configure it in your destination wiring.

### One specific user/post failed but the run kept going

Per-record errors are captured and surfaced in the run report. Fix the source data (or update your destination to handle the edge case), then re-run — the runner skips already-imported records and retries the failed one.

---

## What doesn't migrate

These are **framework-specific** and need to be re-created in Waaseyaa, not imported:

- **Themes** — the WP theme is HTML/PHP for the WP renderer. Waaseyaa uses its own templating; your designer or developer will rebuild the visual layer.
- **Plugins** — WP plugin functionality (forms, SEO, e-commerce, etc.) is replaced by Waaseyaa packages on the destination side. The content those plugins generated *does* migrate; the plugins themselves don't.
- **Settings** — site title, tagline, permalink structure, etc. are configured directly in Waaseyaa.
- **WordPress passwords** — discarded by design. Users reset on first login.

---

## Page-builder content (Elementor / Gutenberg)

If your site used **Elementor**, its real page content lives in the
`_elementor_data` postmeta entry as a JSON tree, not in `post_content` — WP
often leaves `post_content` empty or a stub for Elementor-built pages. The
default `Migration\WpPostsToArticles` migration runs
`Process\WordPressBuilderContentDecode` as the first stage of its `content`
process chain: when a post's `_elementor_data` is present, the plugin decodes
the JSON tree into clean semantic HTML (headings, paragraphs, images,
buttons, cards/grids) and that decoded HTML **replaces** the (often empty)
`content` field. Elementor markup, inline styles, and Font Awesome classes
are dropped in favor of framework-owned `pb-*` classes your theme can style.

If your site used the **block editor (Gutenberg)**, `post_content` already
holds real HTML, but it's interleaved with block-delimiter comments like
`<!-- wp:paragraph -->` / `<!-- /wp:paragraph -->`. The same plugin strips
those comments unconditionally (on both Elementor-decoded output and
ordinary Gutenberg/classic-editor content), preserving the inner HTML.

Nothing to configure for the default case — this runs automatically. If a
page's `_elementor_data` payload is present but can't be decoded (malformed
JSON, or a tree that yields no renderable content), the plugin logs a
warning and leaves `content` unchanged rather than failing the import; check
your logs for `Could not decode _elementor_data payload` after a run and
hand-fix those pages. See the customization guide for swapping in your own
`ElementorTreeDecoder`/logger, or removing the stage entirely.

---

## Post-migration polish (optional)

- **Re-resolve oEmbed embeds.** By default `WordPressOembedExpand` only *detects* oEmbed-capable URLs (YouTube, Vimeo, Twitter/X, Instagram) and leaves them as plain URLs. To inline the provider's embed HTML, set `resolveRemote: true` on the plugin and wire a `OembedFetcherInterface`. This makes one HTTP request per unique URL — cached per run. See the customization guide.

- **Custom shortcode handlers.** If your old site used a plugin that defined custom shortcodes (`[product id="..."]`, `[map ...]`, etc.), the default `WordPressShortcodeStrip` removes them silently. Register rewriters to convert them into your destination's HTML/markup. See the customization guide.

- **Author resolution.** Post and comment records carry `author_login` as a string. If your destination needs the canonical account UUID, resolve it in your destination plugin's `write()` path (a simple `accountRepository->findByUsername($login)` step).

---

## Recipe: Events (The Events Calendar)

If your WordPress site used **The Events Calendar** plugin, events, organizers, and venues are separate custom post types (`tribe_events`, `tribe_organizer`, `tribe_venue`) with their scheduling and contact/address data stored as WXR postmeta rather than typed fields. The package ships three additional migration factories for this shape:

```php
use Waaseyaa\Migrate\Source\WordPress\Migration\WpEventsToNodes;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpOrganizersToNodes;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpVenuesToNodes;

$eventDest     = /* your EntityDestination for the "event" entity */;
$organizerDest = /* …for the "organizer" entity */;
$venueDest     = /* …for the "venue" entity */;

return [
    // ...users, terms, media as before...
    (new WpOrganizersToNodes($reader, $organizerDest))->definition(),
    (new WpVenuesToNodes($reader, $venueDest))->definition(),
    (new WpEventsToNodes($reader, $eventDest))->definition(),
];
```

Each factory filters `WordPressPostSource` down to its own post type internally, so events, organizers, and venues never collide even though they all come from the same WXR file. `WpEventsToNodes` extracts `_EventStartDate`/`_EventEndDate` into `event_start`/`event_end`, plus the raw WordPress post ids for the linked organizer/venue as `event_organizer_source_id`/`event_venue_source_id` — resolving those into destination-side relationships (once `WpOrganizersToNodes`/`WpVenuesToNodes` have run and populated the id-map) is a destination-side concern, since it depends on how your destination models the event ↔ organizer/venue relationship.

`WpOrganizersToNodes` extracts `_OrganizerEmail`/`_OrganizerPhone`/`_OrganizerWebsite`; `WpVenuesToNodes` extracts `_VenueAddress`/`_VenueCity`/`_VenueProvince`/`_VenueCountry`. If your site used additional Events Calendar postmeta fields (currency, timezone, cost, ...), see the [customization guide](customization.md#mapping-arbitrary-postmeta) for how to map any postmeta key into a destination field.

---

## Where to next

- [Customization guide](customization.md) — overriding migrations, adding shortcode handlers, CDN allowlists, multisite, and more.
- [Public surface map](../public-surface-map.md) — every stable symbol the package ships.
- [GitHub issues](https://github.com/waaseyaa/migrate-source-wordpress/issues) — file bugs, request features, or ask questions.
