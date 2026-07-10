<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Tests\Unit\Migration;

use Waaseyaa\Migrate\Source\WordPress\Migration\ReferenceResolutionOptions;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpMediaToEntities;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpTermsToTaxonomy;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressAuthorIdResolve;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressEntityRefResolve;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressTermParentResolve;
use Waaseyaa\Migrate\Source\WordPress\Process\WordPressTermsResolve;
use Waaseyaa\Migrate\Source\WordPress\Testing\InMemoryDestination;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;
use Waaseyaa\Migration\Plugin\Process\LookupProcessor;
use Waaseyaa\Migration\Plugin\Process\TypeCoerceProcessor;

const REF_FIXTURE = __DIR__ . '/../../../testing/Fixtures/small-site.xml';

// -----------------------------------------------------------------------
// WpPostsToArticles
// -----------------------------------------------------------------------

it('WpPostsToArticles process map is unchanged when $references is omitted', function () {
    $def = (new WpPostsToArticles(new WxrReader(REF_FIXTURE), new InMemoryDestination()))->definition();

    expect($def->process)->toHaveKeys(['title', 'slug', 'content', 'author_login', 'parent_id', 'terms']);
    expect($def->process)->not->toHaveKey('uid');
    expect($def->process)->not->toHaveKey('parent_ref');
    expect($def->process)->not->toHaveKey('term_refs');
    expect($def->process['author_login'])->toBe('author_login');
    expect($def->process['parent_id'])->toBe('parent_id');
    expect($def->process['terms'])->toBe('terms');
});

it('WpPostsToArticles adds a uid chain when loginToId is supplied', function () {
    $references = new ReferenceResolutionOptions(loginToId: ['admin' => 1]);
    $def = (new WpPostsToArticles(new WxrReader(REF_FIXTURE), new InMemoryDestination(), references: $references))->definition();

    expect($def->process)->toHaveKey('uid');
    $chain = $def->process['uid'];
    expect($chain)->toBeArray();
    expect($chain[0])->toBe('author_login');
    expect($chain[1])->toBeInstanceOf(WordPressAuthorIdResolve::class);
    expect($chain[2])->toBeInstanceOf(LookupProcessor::class);
    // No entityRefResolve supplied — chain stays 3 steps (uuid, not storage id).
    expect($chain)->toHaveCount(3);
});

it('WpPostsToArticles appends a WordPressEntityRefResolve step to the uid chain when entityRefResolve is supplied', function () {
    $references = new ReferenceResolutionOptions(
        loginToId: ['admin' => 1],
        entityRefResolve: static fn (string $t, string $u) => 1,
    );
    $def = (new WpPostsToArticles(new WxrReader(REF_FIXTURE), new InMemoryDestination(), references: $references))->definition();

    $chain = $def->process['uid'];
    expect($chain)->toHaveCount(4);
    expect($chain[3])->toBeInstanceOf(WordPressEntityRefResolve::class);
});

it('WpPostsToArticles adds a parent_ref chain when resolveParent is true', function () {
    $references = new ReferenceResolutionOptions(resolveParent: true);
    $def = (new WpPostsToArticles(new WxrReader(REF_FIXTURE), new InMemoryDestination(), references: $references))->definition();

    expect($def->process)->toHaveKey('parent_ref');
    $chain = $def->process['parent_ref'];
    expect($chain[0])->toBe('parent_id');
    expect($chain[1])->toBeInstanceOf(TypeCoerceProcessor::class);
    expect($chain[2])->toBeInstanceOf(LookupProcessor::class);
    expect($chain[2]->migration)->toBe(WpPostsToArticles::MIGRATION_ID);
});

it('WpPostsToArticles adds a term_refs chain when slugToTermId is supplied', function () {
    $references = new ReferenceResolutionOptions(slugToTermId: ['category:news' => 10]);
    $def = (new WpPostsToArticles(new WxrReader(REF_FIXTURE), new InMemoryDestination(), references: $references))->definition();

    expect($def->process)->toHaveKey('term_refs');
    $chain = $def->process['term_refs'];
    expect($chain[0])->toBe('terms');
    expect($chain[1])->toBeInstanceOf(WordPressTermsResolve::class);
});

it('WpPostsToArticles dependencies are unchanged by $references', function () {
    $references = new ReferenceResolutionOptions(loginToId: ['admin' => 1], resolveParent: true, slugToTermId: []);
    $def = (new WpPostsToArticles(new WxrReader(REF_FIXTURE), new InMemoryDestination(), references: $references))->definition();

    expect($def->dependencies)->toBe([
        'wp_users_to_accounts',
        'wp_terms_to_taxonomy',
        'wp_media_to_entities',
    ]);
});

// -----------------------------------------------------------------------
// WpTermsToTaxonomy
// -----------------------------------------------------------------------

it('WpTermsToTaxonomy process map is unchanged when $references is omitted', function () {
    $def = (new WpTermsToTaxonomy(new WxrReader(REF_FIXTURE), new InMemoryDestination()))->definition();

    expect($def->process)->toHaveKeys(['name', 'slug', 'taxonomy', 'parent_slug']);
    expect($def->process)->not->toHaveKey('parent_ref');
    expect($def->process['parent_slug'])->toBe('parent_slug');
});

it('WpTermsToTaxonomy adds a parent_ref chain when slugToTermId is supplied', function () {
    $references = new ReferenceResolutionOptions(slugToTermId: ['category:news' => 10]);
    $def = (new WpTermsToTaxonomy(new WxrReader(REF_FIXTURE), new InMemoryDestination(), references: $references))->definition();

    expect($def->process)->toHaveKey('parent_ref');
    $chain = $def->process['parent_ref'];
    expect($chain[0])->toBe('parent_slug');
    expect($chain[1])->toBeInstanceOf(WordPressTermParentResolve::class);
});

// -----------------------------------------------------------------------
// WpMediaToEntities
// -----------------------------------------------------------------------

it('WpMediaToEntities process map is unchanged when $references is omitted', function () {
    $def = (new WpMediaToEntities(new WxrReader(REF_FIXTURE), new InMemoryDestination()))->definition();

    expect($def->process)->toHaveKeys(['file_path', 'parent_post_id']);
    expect($def->process)->not->toHaveKey('parent_ref');
    expect($def->process['parent_post_id'])->toBe('parent_post_id');
    expect($def->dependencies)->toBe(['wp_terms_to_taxonomy']);
});

it('WpMediaToEntities adds a parent_ref chain when resolveParent is true', function () {
    $references = new ReferenceResolutionOptions(resolveParent: true);
    $def = (new WpMediaToEntities(new WxrReader(REF_FIXTURE), new InMemoryDestination(), references: $references))->definition();

    expect($def->process)->toHaveKey('parent_ref');
    $chain = $def->process['parent_ref'];
    expect($chain[0])->toBe('parent_post_id');
    expect($chain[1])->toBeInstanceOf(TypeCoerceProcessor::class);
    expect($chain[2])->toBeInstanceOf(LookupProcessor::class);
    expect($chain[2]->migration)->toBe(WpPostsToArticles::MIGRATION_ID);
    // Dependencies unchanged — media still runs before posts (documented caveat).
    expect($def->dependencies)->toBe(['wp_terms_to_taxonomy']);
});
