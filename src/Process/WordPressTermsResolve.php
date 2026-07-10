<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

use Waaseyaa\Migrate\Source\WordPress\Migration\WpTermsToTaxonomy;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressTaxonomySource;
use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

/**
 * Resolve a post's `terms` field — a list of `{taxonomy, slug}` maps emitted
 * by {@see \Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource} —
 * into a list of destination term references.
 *
 * `LookupProcessor` resolves one scalar key per call; `terms` is a list, so
 * this plugin performs its own {@see ProcessContext::$lookup} calls, one per
 * term, against {@see WpTermsToTaxonomy}'s id-map. WordPress WXR posts carry
 * only `(taxonomy, slug)` per term — not the numeric `wp:term_id` the id-map
 * is keyed by — so a `$slugToTermId` index (keyed `"{taxonomy}:{slug}"`,
 * built via {@see WordPressTaxonomySource::slugIndex()}) bridges the gap,
 * the same way {@see WordPressAuthorIdResolve} bridges login → id for
 * authors.
 *
 * The emitted value is a `list<int|string>` of resolved term references —
 * destination UUIDs by default, or storage identifiers when `$refResolve`
 * is supplied. A term whose slug is missing from the index, or whose id-map
 * row does not exist yet, is silently skipped (or raises, per `$onMiss`) —
 * it does not fail the whole list.
 *
 * @api
 *
 * @spec G-019 — id-map reference resolution (term memberships)
 */
final class WordPressTermsResolve implements ProcessPluginInterface
{
    public const string PLUGIN_ID = 'wordpress_terms_resolve';

    public const string ON_MISS_NULL = 'null';
    public const string ON_MISS_FAIL = 'fail';

    /**
     * @param array<string, int|string> $slugToTermId `"{taxonomy}:{slug}"` → `wp:term_id`.
     * @param \Closure(string, string): (int|string|null)|null $refResolve Resolves `(destinationEntityType, destinationUuid)` to a storage identifier. `null` leaves entries as destination UUID strings.
     * @param string $migration Sibling migration id whose id-map is consulted. Non-empty.
     * @param string $sourceType `SourceId::$sourceType` for the term lookup. Non-empty.
     * @param string $destinationEntityType Destination entity type id passed to `$refResolve`. Non-empty.
     * @param string $onMiss One of {@see self::ON_MISS_NULL} (default — skip the unresolved term) or {@see self::ON_MISS_FAIL} (raise `ProcessException`).
     *
     * @throws \InvalidArgumentException If $migration, $sourceType, or $destinationEntityType is empty, or $onMiss is unrecognised.
     */
    public function __construct(
        private readonly array $slugToTermId,
        private readonly ?\Closure $refResolve = null,
        private readonly string $migration = WpTermsToTaxonomy::MIGRATION_ID,
        private readonly string $sourceType = 'wp_term',
        private readonly string $destinationEntityType = 'taxonomy_term',
        private readonly string $onMiss = self::ON_MISS_NULL,
    ) {
        if ($migration === '') {
            throw new \InvalidArgumentException('WordPressTermsResolve::$migration must be a non-empty string.');
        }
        if ($sourceType === '') {
            throw new \InvalidArgumentException('WordPressTermsResolve::$sourceType must be a non-empty string.');
        }
        if ($destinationEntityType === '') {
            throw new \InvalidArgumentException('WordPressTermsResolve::$destinationEntityType must be a non-empty string.');
        }
        if ($onMiss !== self::ON_MISS_NULL && $onMiss !== self::ON_MISS_FAIL) {
            throw new \InvalidArgumentException(\sprintf(
                'WordPressTermsResolve::$onMiss must be %s or %s, got %s.',
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
     * @return list<int|string>
     *
     * @throws ProcessException When a term is unresolvable and `$onMiss === 'fail'`.
     */
    public function transform(mixed $value, ProcessContext $context): mixed
    {
        $terms = $value ?? $context->sourceRecord->field('terms', []);
        if (!is_array($terms)) {
            return [];
        }

        $lookup = $context->lookup;
        $resolved = [];

        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }
            $taxonomy = $term['taxonomy'] ?? null;
            $slug = $term['slug'] ?? null;
            if (!is_string($taxonomy) || !is_string($slug) || $taxonomy === '' || $slug === '') {
                continue;
            }

            $wpTermId = $this->slugToTermId[$taxonomy . ':' . $slug] ?? null;
            if ($wpTermId === null) {
                $this->handleMiss($context, \sprintf(
                    'WordPressTermsResolve: no wp:term_id in the slug index for %s:%s.',
                    $taxonomy,
                    $slug,
                ));
                continue;
            }

            $writeResult = $lookup($this->migration, new SourceId(
                sourceType: $this->sourceType,
                keys: ['id' => (string) $wpTermId],
            ));

            if (!$writeResult instanceof WriteResult) {
                $this->handleMiss($context, \sprintf(
                    'WordPressTermsResolve: no id-map row in migration %s for wp:term_id %s.',
                    var_export($this->migration, true),
                    var_export($wpTermId, true),
                ));
                continue;
            }

            $uuid = $writeResult->destinationUuid;
            if ($this->refResolve === null) {
                $resolved[] = $uuid;
                continue;
            }

            $storageId = ($this->refResolve)($this->destinationEntityType, $uuid);
            if ($storageId === null) {
                $this->handleMiss($context, \sprintf(
                    'WordPressTermsResolve: refResolve returned no storage id for entity type %s, uuid %s.',
                    var_export($this->destinationEntityType, true),
                    var_export($uuid, true),
                ));
                continue;
            }

            $resolved[] = $storageId;
        }

        return $resolved;
    }

    private function handleMiss(ProcessContext $context, string $message): void
    {
        if ($this->onMiss === self::ON_MISS_FAIL) {
            throw new ProcessException(
                processCode: 'WORDPRESS_TERM_MISS',
                sourceField: $context->destinationField,
                migrationId: $context->migrationId,
                message: $message,
            );
        }
    }
}
