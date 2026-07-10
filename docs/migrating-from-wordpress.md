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
