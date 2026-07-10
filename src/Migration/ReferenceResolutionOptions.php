<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Migration;

/**
 * Opt-in bundle of id-map reference-resolution wiring for the default
 * migration factories (G-019).
 *
 * Passing an instance to `WpPostsToArticles`, `WpTermsToTaxonomy`, or
 * `WpMediaToEntities` enables extra, additive destination fields carrying
 * resolved cross-migration references (author, page hierarchy, term
 * memberships, term parents, media→post attachment). Existing destination
 * fields are never mutated — an unresolved consumer (no `$references`
 * argument) sees byte-identical process maps to before this option existed.
 *
 * Two lookup indexes are supplied by the caller because WordPress source
 * data does not carry the numeric ids the migration id-map is keyed by in
 * every field:
 *
 * - `$loginToId`: `dc:creator` login string → `wp:author_id`. Build via
 *   {@see \Waaseyaa\Migrate\Source\WordPress\Source\WordPressUserSource::loginIndex()}.
 * - `$slugToTermId`: `"{taxonomy}:{slug}"` → `wp:term_id` (or the legacy
 *   `crc32()`-derived id for `<wp:category>` elements without one). Build via
 *   {@see \Waaseyaa\Migrate\Source\WordPress\Source\WordPressTaxonomySource::slugIndex()}.
 *
 * `$entityRefResolve`, when supplied, converts a resolved destination UUID
 * into the destination storage identifier (typically an `int` primary key —
 * e.g. `Node.uid`, `Term.parent_id`) via the application's own
 * `EntityRepository::findBy(['uuid' => $uuid])` lookup. When omitted,
 * resolved reference fields carry the destination UUID string instead — a
 * valid value for destinations whose reference fields are UUID-typed.
 *
 * @api
 *
 * @spec G-019 — id-map reference resolution
 */
final readonly class ReferenceResolutionOptions
{
    public const string ON_MISS_NULL = 'null';
    public const string ON_MISS_FAIL = 'fail';

    /**
     * @param array<string, int|string>|null $loginToId WordPress user login → `wp:author_id`. Enables `uid` resolution on `WpPostsToArticles`.
     * @param array<string, int|string>|null $slugToTermId `"{taxonomy}:{slug}"` → `wp:term_id`. Enables `term_refs` on `WpPostsToArticles` and `parent_ref` on `WpTermsToTaxonomy`.
     * @param \Closure(string, string): (int|string|null)|null $entityRefResolve Resolves `(destinationEntityType, destinationUuid)` to a storage identifier. `null` leaves resolved references as UUID strings.
     * @param bool $resolveParent Enable `parent_ref` (post/page hierarchy) on `WpPostsToArticles` and `parent_ref` (media → attached post) on `WpMediaToEntities`.
     * @param string $authorEntityType Destination entity type id passed to `$entityRefResolve` for author references.
     * @param string $termEntityType Destination entity type id passed to `$entityRefResolve` for term references.
     * @param string $postEntityType Destination entity type id passed to `$entityRefResolve` for post/page references.
     * @param string $onMiss One of {@see self::ON_MISS_NULL} (default — unresolved reference fields stay `null`) or {@see self::ON_MISS_FAIL} (raise `ProcessException`).
     *
     * @throws \InvalidArgumentException If $onMiss is unrecognised.
     */
    public function __construct(
        public ?array $loginToId = null,
        public ?array $slugToTermId = null,
        public ?\Closure $entityRefResolve = null,
        public bool $resolveParent = false,
        public string $authorEntityType = 'account',
        public string $termEntityType = 'taxonomy_term',
        public string $postEntityType = 'article',
        public string $onMiss = self::ON_MISS_NULL,
    ) {
        if ($onMiss !== self::ON_MISS_NULL && $onMiss !== self::ON_MISS_FAIL) {
            throw new \InvalidArgumentException(\sprintf(
                'ReferenceResolutionOptions::$onMiss must be %s or %s, got %s.',
                var_export(self::ON_MISS_NULL, true),
                var_export(self::ON_MISS_FAIL, true),
                var_export($onMiss, true),
            ));
        }
    }
}
