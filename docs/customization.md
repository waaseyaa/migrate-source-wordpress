# Customizing the WordPress reader

This guide is for **developers** integrating `waaseyaa/migrate-source-wordpress` into a Waaseyaa application. Operator-facing instructions live in [`docs/migrating-from-wordpress.md`](migrating-from-wordpress.md).

The package ships building blocks; the canonical wiring lives in your application. This document walks through the common override patterns.

---

## Renaming the example posts migration

`WpPostsToArticles` is intentionally named as an example (FR-022). Most consumers want their destination called something more specific: `BlogPost`, `Teaching`, `NewsItem`, `Article`. You have two options:

### Option A — Wrap with a new id

Construct a fresh `MigrationDefinition` reusing the package's source + process plugins:

```php
use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressShortcodeStrip;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\MigrationDefinition;

$reader = new WxrReader($wxrPath);

$definition = new MigrationDefinition(
    id: 'wp_posts_to_teachings',
    source: new WordPressPostSource($reader, 'wp_posts_to_teachings'),
    process: [
        'title' => 'title',
        'slug' => 'slug',
        'content' => ['content', new WordPressShortcodeStrip()],
        // ... your destination field map
    ],
    destination: $teachingsDestination,
    dependencies: ['wp_users_to_accounts', 'wp_terms_to_taxonomy', 'wp_media_to_entities'],
);
```

### Option B — Subclass the factory

Extend the factory if you only need to tweak the id and keep the rest of the process map:

```php
final class WpPostsToTeachings extends WpPostsToArticles
{
    public const string MIGRATION_ID = 'wp_posts_to_teachings';
}
```

Update `dependencies` in any downstream migrations (e.g. `WpCommentsToEngagement`) to reference the new id.

---

## Adding custom shortcode handlers

The default `WordPressShortcodeStrip` removes unknown shortcodes silently. Register handlers to rewrite them into your destination markup:

```php
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressShortcodeStrip;

$shortcodeStrip = new WordPressShortcodeStrip([
    'gallery' => static fn (string $tag, array $attrs, string $inner): string =>
        sprintf('<x-gallery ids="%s" />', htmlspecialchars($attrs['ids'] ?? '', ENT_QUOTES)),

    'youtube' => static fn (string $tag, array $attrs, string $inner): string =>
        sprintf('<x-embed src="https://www.youtube.com/embed/%s" />', $attrs['id'] ?? ''),
]);
```

Handler signature: `(string $tagName, array $parsedAttrs, string $inner): string`.

Handlers receive parsed attributes (both `"double"` and `'single'` quoted forms supported) plus the inner content for paired shortcodes (`[caption]inner[/caption]`). Tag matching is case-insensitive at runtime.

Nested shortcodes recurse to depth 4. For deeper nests, pre-process content in your CMS before the migration.

---

## Customizing page-builder content decoding (Elementor / Gutenberg)

`Process\WordPressBuilderContentDecode` (G-013/G-029) runs first in
`Migration\WpPostsToArticles`'s default `content` chain, hardcoded (it has no
operator-tunable state in the default factory — see below to override it).
Its contract:

- If the source record's `_extra.postmeta._elementor_data` postmeta is
  present and decodes to at least one renderable block, the decoded HTML
  **replaces** the incoming `content` value.
- If `_elementor_data` is absent, empty, or `'[]'`, `content` passes through
  unchanged.
- If `_elementor_data` is present but malformed JSON, or decodes to a tree
  with no renderable blocks, `content` passes through unchanged and a
  `warning`-level log entry is emitted with `migration_id` and
  `destination_field` context — this is the "graceful degradation" path;
  the import never fails because of a single bad payload.
- Gutenberg block-delimiter comments (`<!-- wp:name ... -->` /
  `<!-- /wp:name -->`, including the void `/-->` form) are always stripped
  from the resulting HTML, whether or not Elementor decoding ran, preserving
  the inner markup.

The Elementor decoding strategy itself lives in a separate class,
`PageBuilder\ElementorTreeDecoder`, which the plugin constructs with a
default instance. To supply your own logger (e.g. to route warnings into
your application's structured logging) or a customized decoder, subclass
`WpPostsToArticles` and override `definition()`'s content chain (see "Renaming
the example posts migration" → Option B above) rather than passing
constructor arguments — the plugin is intentionally not one of
`WpPostsToArticles`'s constructor parameters, since it has no per-operator
configuration in the shipped example:

```php
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressBuilderContentDecode;
use Waaseyaa\Migrate\Source\WordPress\PageBuilder\ElementorTreeDecoder;

$builderDecode = new WordPressBuilderContentDecode(
    elementorDecoder: new ElementorTreeDecoder(),
    logger: $myPsrLogger,
);
```

then splice `$builderDecode` into your subclass's `content` chain in place of
`new WordPressBuilderContentDecode()`.

`PageBuilder\ElementorTreeDecoder`, `PageBuilder\BlockRenderer`, and
`PageBuilder\HtmlCleaner` are a deliberately narrow port of the framework's
former `waaseyaa/page-builder` package's `ElementorDecoder` decoding
strategy: no decoder registry, no `DecodeRequest`/`DecodedPage` envelope, and
no scoped-component (`<style>`-bearing pasted HTML) handling — a
style-bearing widget is cleaned like any other rich text instead of being
promoted to an owned component. If you need that narrower feature, decode
`_elementor_data` yourself with a custom `ProcessPluginInterface`
implementation in its place.

---

## Configuring CDN host allowlist for media URL rewrites

`WordPressMediaRewriteUrl` rewrites `wp-content/uploads/...` references in post content. By default it rewrites references on **any** host. To restrict to a known set (the canonical WP hostname plus any CDN), pass `cdnHosts`:

```php
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressMediaRewriteUrl;

$mediaRewrite = new WordPressMediaRewriteUrl(
    urlResolver: static fn (string $relativePath): ?string =>
        $myMediaRegistry->lookupByPath($relativePath),
    cdnHosts: ['cdn.example.com', 'media.example.com', 'images.example.com'],
);
```

Host matching is case-insensitive. URLs on non-allowlisted hosts pass through unchanged. The `$urlResolver` closure is your application's path → destination URL function: typically a lookup against the media migration's id-map, or a pre-built path cache.

Returning `null` from the resolver leaves the URL untouched and logs a warning at the `warning` level (FR-046).

---

## Enabling oEmbed remote resolution

`WordPressOembedExpand` defaults to **detection only** — embed-capable URLs are recognised but not resolved (research §1.6: opt-in network I/O). To inline the provider's embed HTML:

```php
use Waaseyaa\Migrate\Source\WordPress\Process\OembedFetcherInterface;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressOembedExpand;

final class GuzzleOembedFetcher implements OembedFetcherInterface
{
    public function __construct(private readonly \GuzzleHttp\ClientInterface $client) {}

    public function fetch(string $oembedEndpointUrl): string
    {
        return (string) $this->client->request('GET', $oembedEndpointUrl, [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 5.0,
        ])->getBody();
    }
}

$oembedExpand = new WordPressOembedExpand(
    resolveRemote: true,
    fetcher: new GuzzleOembedFetcher($yourClient),
);
```

The package ships **no default fetcher** to avoid taking on `psr/http-client` as a hard dependency. Use your application's preferred HTTP client behind `OembedFetcherInterface`.

Resolutions are cached per plugin instance — within one migration run, the same URL is never fetched twice. Across runs, no cross-run persistence is provided; rely on your destination's content cache to avoid re-fetching on incremental runs.

---

## Skipping a migration

If your destination has no comment-equivalent entity, drop `WpCommentsToEngagement` from the array returned to the runner — Waaseyaa's `MigrationRegistry` only walks what you register. The other four migrations work in isolation.

Same pattern for skipping media (if your destination handles media out-of-band) or skipping users (if you're carrying over content only):

```php
return array_filter([
    (new WpUsersToAccounts($reader, $accountDest))->definition(),
    (new WpTermsToTaxonomy($reader, $taxonomyDest))->definition(),
    $mediaDest !== null ? (new WpMediaToEntities($reader, $mediaDest))->definition() : null,
    (new WpPostsToArticles($reader, $articleDest))->definition(),
    $engagementDest !== null ? (new WpCommentsToEngagement($reader, $engagementDest))->definition() : null,
]);
```

Make sure to also remove the skipped migration's id from downstream `dependencies()` lists if you skip a middle-of-chain step (otherwise `MigrationRegistry::boot()` raises `MigrationDependencyMissingException`).

---

## Mapping arbitrary postmeta

The `_extra` field on every post/attachment record carries unmapped namespaced attributes plus the full `postmeta` map (`$record['_extra']['postmeta']`, a flat `meta_key => meta_value` map). Any plugin that stores data as postmeta — The Events Calendar, WooCommerce, Yoast, a custom plugin — can be mapped into a typed destination field with `Process\WordPressPostmetaExtract`:

```php
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressPostmetaExtract;

'event_start' => ['_extra', new WordPressPostmetaExtract('_EventStartDate')],
'custom_field' => ['_extra', new WordPressPostmetaExtract('my_plugin_field', default: null)],
```

The chain's first element (`'_extra'`) resolves to `PassThroughProcessor('_extra')`, which reads the record's `_extra` field — that's what `WordPressPostmetaExtract::transform()` then receives as `$value`. It tolerates missing keys, a missing `postmeta` slot, or `_extra` not being an array at all, falling back to the constructor's `$default` (or `null`) in every case, so a malformed or plugin-absent record never throws.

For a one-off case where a plugin-specific process step is overkill, the raw escape hatch still works:

```php
'custom_field' => static function (mixed $value, ProcessContext $context): mixed {
    $postmeta = $context->sourceRecord->fields['_extra']['postmeta'] ?? [];
    return $postmeta['my_plugin_field'] ?? null;
},
```

See [`WpEventsToNodes`](../src/Migration/WpEventsToNodes.php) for a full worked example (event start/end dates, organizer/venue linkage) and the [Events (The Events Calendar) recipe](migrating-from-wordpress.md#recipe-events-the-events-calendar) for the operator-facing walkthrough.

---

## Resolving references through the id-map

By default the WordPress `terms`, `author_login`, and `parent_id` fields are
emitted **verbatim** — raw WXR values, not destination references. G-019
adds an *opt-in* wiring layer, `Migration\ReferenceResolutionOptions`, that
resolves those references through the real migration id-map and turns them
into destination entity references. Nothing about the default (unresolved)
process maps changes when you don't pass it — `$references` is the last,
optional constructor argument on `WpUsersToAccounts`'s siblings
(`WpTermsToTaxonomy`, `WpMediaToEntities`, `WpPostsToArticles`).

### The two problems this solves

1. **UUID vs storage id.** `Plugin\Process\LookupProcessor` (M-002) resolves
   a source key to a `WriteResult::$destinationUuid` — a string. Many
   destination reference fields (`Node.uid`, `Term.parent_id`, ...) are
   integer foreign keys. `Process\WordPressEntityRefResolve`, chained after
   a `LookupProcessor`, converts the uuid to a storage id via an
   application-supplied closure:

   ```php
   use Waaseyaa\EntityStorage\EntityRepository;

   /** @var array<string, EntityRepository> $repositories keyed by destination entity type */
   $entityRefResolve = function (string $entityType, string $uuid) use ($repositories): int|string|null {
       $matches = $repositories[$entityType]->findBy(['uuid' => $uuid]);
       return $matches[0]?->get('id');
   };
   ```

   `EntityRepository::findBy(['uuid' => $uuid])` is the canonical lookup —
   the same one `Waaseyaa\Migration\Tests\Integration\EndToEndCsvImportTest`
   uses to resolve a migrated entity by its stable handle. Omit
   `$entityRefResolve` and resolved reference fields stay destination UUID
   strings instead — valid if your destination field is UUID-typed.

2. **WordPress source data isn't id-map-shaped.** Posts carry authorship as
   a login string (`dc:creator`) and term membership as `(taxonomy, slug)`
   pairs, but the WordPress source plugins key their `SourceId`s by numeric
   ids (`wp:author_id`, `wp:term_id`). Two small index builders bridge the
   gap — build them once from a *fresh* `WxrReader` (streaming readers are
   not rewindable mid-stream):

   ```php
   use Waaseyaa\Migrate\Source\WordPress\Source\WordPressUserSource;
   use Waaseyaa\Migrate\Source\WordPress\Source\WordPressTaxonomySource;

   $loginToId = WordPressUserSource::loginIndex(new WxrReader($wxrPath));       // login => wp:author_id
   $slugToTermId = WordPressTaxonomySource::slugIndex(new WxrReader($wxrPath)); // "taxonomy:slug" => wp:term_id
   ```

### Wiring it up

```php
use Waaseyaa\Migrate\Source\WordPress\Migration\ReferenceResolutionOptions;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpTermsToTaxonomy;

$references = new ReferenceResolutionOptions(
    loginToId: $loginToId,
    slugToTermId: $slugToTermId,
    entityRefResolve: $entityRefResolve,
    resolveParent: true,          // page hierarchy + media->post attachment
    authorEntityType: 'account',  // entity type id passed to $entityRefResolve
    termEntityType: 'taxonomy_term',
    postEntityType: 'article',
    onMiss: ReferenceResolutionOptions::ON_MISS_NULL, // or ::ON_MISS_FAIL
);

$termsDefinition = (new WpTermsToTaxonomy($reader, $taxonomyDest, references: $references))->definition();
$postsDefinition = (new WpPostsToArticles($reader, $articleDest, references: $references))->definition();
```

Every resolved field is **additive** — it never replaces the raw field:

| Migration | Raw field (unchanged) | New resolved field | Requires |
|---|---|---|---|
| `WpPostsToArticles` | `author_login` | `uid` | `$loginToId` |
| `WpPostsToArticles` | `parent_id` | `parent_ref` | `$resolveParent` |
| `WpPostsToArticles` | `terms` | `term_refs` (`list<int\|string>`) | `$slugToTermId` |
| `WpTermsToTaxonomy` | `parent_slug` | `parent_ref` | `$slugToTermId` |
| `WpMediaToEntities` | `parent_post_id` | `parent_ref` | `$resolveParent` |

`onMiss` (default `'null'`) governs every resolver in the bundle: an
unresolvable reference leaves the new field `null` (or, for `term_refs`,
skips that one entry) rather than failing the whole record.
`ReferenceResolutionOptions::ON_MISS_FAIL` raises `ProcessException` instead
— useful once you trust your indexes are complete and want a hard failure
on drift.

### Ordering caveats worth knowing

- **Page hierarchy (`WpPostsToArticles`'s `parent_ref`) is a same-migration
  self-lookup.** It only resolves correctly if a page's parent appears
  *earlier* in WXR document order than the page itself — the runner does not
  topologically sort records within one migration. This mirrors the existing
  `WpCommentsToEngagement`'s `parent_id` self-lookup and is a WordPress
  export convention in practice (parents are typically created, and thus
  exported, before their children), not a guarantee the connector enforces.
- **`WpMediaToEntities`'s `parent_ref` (media → attached post) resolves on a
  SECOND run.** The shipped dependency order runs media *before* posts
  (posts reference media, not the other way around), so on the first run the
  referenced post does not exist in the id-map yet and `parent_ref` stays
  `null`. Re-running the media migration after posts have imported succeeds
  — the resolved value now differs from the stored `null`, so
  `EntityDestination`'s change-detection (FR-031) updates the row instead of
  skipping it. Operators who need first-run resolution must run posts before
  media in a custom pipeline that also reorders `WpPostsToArticles`'s
  dependency on `WpMediaToEntities`.

### `Term.slug`

`WpTermsToTaxonomy` has always emitted `slug => slug` unconditionally — no
`$references` needed. On framework versions carrying a first-class
`Term.slug` field (`waaseyaa/field` post-alpha.258) it lands there directly;
on older versions the value rides the entity's `_data` blob like any other
unmapped field, because `EntityDestination` applies every process-map value
via `$entity->set()` regardless of whether the destination schema has a real
column for it.

---

## Multisite (WordPress Multisite)

WordPress Multisite exports one WXR per site (research §1.5). The package handles one WXR file per `WxrReader` instance. To migrate a multisite network:

1. Export each site individually from its WP admin (or `wp-cli`).
2. Run the migration **once per site**, with a `WxrReader` pointed at that site's WXR file and destinations partitioned per tenant.

Cross-site shared users are deduplicated by destination — if two sites export the same `author_login`, the second run sees an idempotent skip on the user migration (the source-id hash matches).

---

## Stability commitment

The package follows semantic versioning. From v1.0 onward:

- **Stable symbols** (those listed in [`public-surface-map.md`](../public-surface-map.md)) only change in a major release, with a corresponding entry in `docs/upgrades/`.
- **Stable error codes** (string constants on each exception class) are append-only — removing or renaming a code is a major break.
- **Behavioural changes** to default migrations (e.g. changing how `must_reset_password` is set) follow the same major-version cadence.

Pre-1.0 minor bumps may include breaking changes; consult the CHANGELOG when upgrading.
