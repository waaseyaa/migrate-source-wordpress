<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Wxr;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Waaseyaa\Migrate\Source\WordPress\Exception\WxrParseException;

/**
 * Streaming WXR (WordPress eXtended RSS) parser.
 *
 * Yields one record per WXR entity in document order. Records are yielded as
 * `array{type: string, data: array<string, mixed>}` where `type` is one of:
 * `'user'`, `'term'`, `'post'`, `'attachment'`, `'comment'`.
 *
 * Memory profile: the parser uses PHP's `XMLReader` (libxml-backed pull parser)
 * and never materializes the full document. Per-record memory is bounded by
 * the size of a single `<item>` (typically a single post + its comments).
 *
 * @api
 */
final class WxrReader
{
    private const string WP_NS_PREFIX = 'http://wordpress.org/export/';
    private const string DC_NS = 'http://purl.org/dc/elements/1.1/';
    private const string CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';
    private const string EXCERPT_NS = 'http://wordpress.org/export/1.2/excerpt/';

    private const int GC_INTERVAL = 100;

    private ?WxrVersion $version = null;
    private int $recordIndex = 0;

    public function __construct(
        private readonly string $filePath,
        private readonly bool $strict = false,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Iterate every record in the WXR file, yielding `{type, data}` arrays.
     *
     * @return iterable<array{type: string, data: array<string, mixed>}>
     *
     * @throws WxrParseException when the file is missing/unreadable, the WXR
     *     version is unsupported, or — in strict mode — a record fails to parse
     */
    public function records(): iterable
    {
        if (!is_file($this->filePath)) {
            throw WxrParseException::fileNotFound($this->filePath);
        }
        if (!is_readable($this->filePath)) {
            throw WxrParseException::fileNotReadable($this->filePath);
        }

        $reader = new \XMLReader();
        $opened = $reader->open($this->filePath, 'UTF-8', \LIBXML_PARSEHUGE | \LIBXML_NONET);
        if ($opened === false) {
            throw WxrParseException::fileNotReadable($this->filePath);
        }

        $previousErrorState = libxml_use_internal_errors(true);

        try {
            yield from $this->iterate($reader);
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorState);
        }
    }

    /**
     * Resolve the WXR version once detected (after at least one read).
     *
     * @throws WxrParseException if called before any records have been yielded
     */
    public function version(): WxrVersion
    {
        if ($this->version === null) {
            throw WxrParseException::unsupportedVersion('(not yet detected)');
        }

        return $this->version;
    }

    /**
     * @return iterable<array{type: string, data: array<string, mixed>}>
     */
    private function iterate(\XMLReader $reader): iterable
    {
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }

            // Detect version on the wp:wxr_version element (defensive — also accept
            // it bare in case the wp prefix isn't yet bound at the read offset).
            if ($this->version === null && $reader->localName === 'wxr_version') {
                $this->version = WxrVersion::fromString(trim((string) $reader->readString()));
                continue;
            }

            // Only match wp:-prefixed top-level entity elements. The bare
            // `<category domain="..." nicename="...">` element is an inline
            // term reference inside `<item>` and is NOT a term definition.
            $isWpPrefix = $reader->prefix === 'wp';
            $records = match (true) {
                $isWpPrefix && $reader->localName === 'author' => $this->expandRecord($reader, 'user'),
                $isWpPrefix && in_array($reader->localName, ['category', 'tag', 'term'], true) => $this->expandRecord($reader, 'term'),
                $reader->prefix === '' && $reader->localName === 'item' => $this->expandItem($reader),
                default => null,
            };

            if ($records === null) {
                continue;
            }

            foreach ($records as $record) {
                yield $record;

                $this->recordIndex++;
                if ($this->recordIndex % self::GC_INTERVAL === 0) {
                    gc_collect_cycles();
                }
            }
        }

        if ($this->version === null) {
            // Tolerant default: assume v1.2 with a warning. Some pre-WP-3.0 exports
            // omit the version element entirely.
            $this->logger->warning('WXR file has no <wp:wxr_version> element; assuming 1.2', [
                'file' => $this->filePath,
            ]);
            $this->version = WxrVersion::V_1_2;
        }
    }

    /**
     * Expand a non-item element (`<wp:author>`, `<wp:term>`, etc.) into a single
     * record. Returns null when expansion fails and the parser is non-strict.
     *
     * @return list<array{type: string, data: array<string, mixed>}>|null
     */
    private function expandRecord(\XMLReader $reader, string $type): ?array
    {
        $node = $this->safeExpand($reader);
        if ($node === null) {
            return null;
        }

        $data = match ($type) {
            'user' => $this->extractUser($node),
            'term' => $this->extractTerm($node, $reader->localName),
            default => [],
        };

        return [['type' => $type, 'data' => $data]];
    }

    /**
     * Expand an `<item>` and yield either a single post/attachment record plus
     * any nested comment records.
     *
     * @return list<array{type: string, data: array<string, mixed>}>|null
     */
    private function expandItem(\XMLReader $reader): ?array
    {
        $node = $this->safeExpand($reader);
        if ($node === null) {
            return null;
        }

        $node->registerXPathNamespace('wp', $this->resolveWpNamespace($node));
        $node->registerXPathNamespace('dc', self::DC_NS);
        $node->registerXPathNamespace('content', self::CONTENT_NS);
        $node->registerXPathNamespace('excerpt', self::EXCERPT_NS);

        $postType = trim((string) ($node->xpath('wp:post_type')[0] ?? ''));
        $type = $postType === 'attachment' ? 'attachment' : 'post';

        $records = [['type' => $type, 'data' => $this->extractPostlike($node, $type)]];

        foreach ($node->xpath('wp:comment') ?: [] as $commentNode) {
            $records[] = [
                'type' => 'comment',
                'data' => $this->extractComment($commentNode, (int) ($node->xpath('wp:post_id')[0] ?? 0)),
            ];
        }

        return $records;
    }

    /**
     * Read the current element's outer XML and parse it as a SimpleXMLElement.
     * Captures libxml errors and either skips with a warning (non-strict) or
     * throws a {@see WxrParseException::recordParseFailure}.
     *
     * Implementation note: `XMLReader::expand()` returns a node detached from
     * a document, which `simplexml_import_dom()` rejects. Using `readOuterXml()`
     * + `simplexml_load_string()` produces a properly-rooted element while
     * preserving all parent-document namespace declarations (libxml emits the
     * needed xmlns attributes in the outer XML).
     */
    private function safeExpand(\XMLReader $reader): ?\SimpleXMLElement
    {
        libxml_clear_errors();
        $xml = @$reader->readOuterXml();
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if ($xml === '' || $errors !== []) {
            return $this->handleRecordFailure($errors);
        }

        // libxml's readOuterXml() emits the relevant xmlns declarations on the
        // serialized root element (verified across libxml 2.9.x — declarations
        // for any prefix used by descendants are propagated). Parse directly.
        $simple = @simplexml_load_string($xml, \SimpleXMLElement::class, \LIBXML_NOCDATA | \LIBXML_NONET);
        $loadErrors = libxml_get_errors();
        libxml_clear_errors();

        if ($simple === false || $loadErrors !== []) {
            return $this->handleRecordFailure($loadErrors);
        }

        return $simple;
    }

    /**
     * @param list<\LibXMLError> $errors
     */
    private function handleRecordFailure(array $errors, ?\Throwable $previous = null): null
    {
        if ($this->strict) {
            $exception = WxrParseException::recordParseFailure($this->recordIndex, $errors);
            if ($previous !== null) {
                throw new \RuntimeException($exception->getMessage(), 0, $previous);
            }
            throw $exception;
        }

        $this->logger->warning('WXR record skipped due to parse errors', [
            'record_index' => $this->recordIndex,
            'errors' => array_map(static fn (\LibXMLError $e): string => trim($e->message), $errors),
            'previous' => $previous?->getMessage(),
        ]);

        return null;
    }

    /**
     * Resolve the document's `wp:` namespace URI from the SimpleXMLElement's
     * registered namespaces. WXR 1.0/1.1/1.2 use slightly different URIs.
     */
    private function resolveWpNamespace(\SimpleXMLElement $node): string
    {
        $namespaces = $node->getDocNamespaces(true, false);
        foreach ($namespaces as $prefix => $uri) {
            if ($prefix === 'wp' && str_starts_with($uri, self::WP_NS_PREFIX)) {
                return $uri;
            }
        }

        // Fallback to the v1.2 URI if the doc didn't declare wp explicitly.
        return self::WP_NS_PREFIX . '1.2/';
    }

    /**
     * @return array<string, mixed>
     */
    private function extractUser(\SimpleXMLElement $node): array
    {
        $node->registerXPathNamespace('wp', $this->resolveWpNamespace($node));
        $get = static fn (string $field): string => trim((string) ($node->xpath('wp:' . $field)[0] ?? ''));

        return [
            'id' => (int) $get('author_id'),
            'login' => $get('author_login'),
            'email' => $get('author_email'),
            'display_name' => $get('author_display_name') !== '' ? $get('author_display_name') : $get('author_login'),
            'first_name' => $get('author_first_name') !== '' ? $get('author_first_name') : null,
            'last_name' => $get('author_last_name') !== '' ? $get('author_last_name') : null,
            'registered' => $get('author_registered_date') !== '' ? $get('author_registered_date') : null,
            'role' => $get('author_role'),
            '_extra' => $this->extractExtras($node, ['author_id', 'author_login', 'author_email', 'author_display_name', 'author_first_name', 'author_last_name', 'author_registered_date', 'author_role']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTerm(\SimpleXMLElement $node, string $elementName): array
    {
        $node->registerXPathNamespace('wp', $this->resolveWpNamespace($node));
        $get = static fn (string $field): string => trim((string) ($node->xpath('wp:' . $field)[0] ?? ''));

        // Three element variants. Field names differ; normalize.
        [$idField, $taxonomyDefault, $nameField, $slugField, $descField, $parentField] = match ($elementName) {
            'category' => ['term_id', 'category', 'cat_name', 'category_nicename', 'category_description', 'category_parent'],
            'tag' => ['term_id', 'post_tag', 'tag_name', 'tag_slug', 'tag_description', null],
            'term' => ['term_id', null, 'term_name', 'term_slug', 'term_description', 'term_parent'],
            default => ['term_id', null, 'term_name', 'term_slug', 'term_description', 'term_parent'],
        };

        $taxonomy = $get('category_taxonomy') !== '' ? $get('category_taxonomy') : ($taxonomyDefault ?? $get('term_taxonomy'));
        $name = $get($nameField);
        $slug = $get($slugField);

        // Legacy WXR 1.0/1.1 may omit term_id; synthesize a stable id.
        $id = (int) $get($idField);
        if ($id === 0) {
            $id = (int) sprintf('%u', crc32($taxonomy . ':' . $slug));
        }

        $excludeFields = array_filter([$idField, 'category_taxonomy', 'term_taxonomy', $nameField, $slugField, $descField, $parentField]);

        return [
            'id' => $id,
            'taxonomy_name' => $taxonomy,
            'name' => $name,
            'slug' => $slug,
            'description' => $get($descField) !== '' ? $get($descField) : null,
            'parent_slug' => $parentField !== null && $get($parentField) !== '' ? $get($parentField) : null,
            '_extra' => $this->extractExtras($node, $excludeFields),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPostlike(\SimpleXMLElement $node, string $type): array
    {
        $titleNodes = $node->xpath('title');
        $title = trim((string) ($titleNodes[0] ?? ''));

        $contentNodes = $node->xpath('content:encoded');
        $excerptNodes = $node->xpath('excerpt:encoded');
        $creatorNodes = $node->xpath('dc:creator');

        $get = static fn (string $field): string => trim((string) ($node->xpath('wp:' . $field)[0] ?? ''));

        $publishedGmt = $get('post_date_gmt');
        if ($publishedGmt === '' || $publishedGmt === '0000-00-00 00:00:00') {
            $publishedGmt = $get('post_date');
        }

        $modifiedGmt = $get('post_modified_gmt');
        if ($modifiedGmt === '' || $modifiedGmt === '0000-00-00 00:00:00') {
            $modifiedGmt = $get('post_modified') !== '' ? $get('post_modified') : null;
        }

        $terms = [];
        foreach ($node->xpath('category') ?: [] as $cat) {
            $domain = (string) ($cat['domain'] ?? '');
            $nicename = (string) ($cat['nicename'] ?? '');
            if ($domain !== '' && $nicename !== '') {
                $terms[] = ['taxonomy' => $domain, 'slug' => $nicename];
            }
        }

        return [
            'id' => (int) $get('post_id'),
            'post_type' => $get('post_type'),
            'title' => $title,
            'slug' => $get('post_name'),
            'content' => trim((string) ($contentNodes[0] ?? '')),
            'excerpt' => trim((string) ($excerptNodes[0] ?? '')) !== '' ? trim((string) ($excerptNodes[0] ?? '')) : null,
            'status' => $get('status'),
            'published_at' => $publishedGmt,
            'modified_at' => $modifiedGmt,
            'author_login' => trim((string) ($creatorNodes[0] ?? '')),
            'parent_id' => (int) $get('post_parent') !== 0 ? (int) $get('post_parent') : null,
            'terms' => $terms,
            'comment_status' => $get('comment_status'),
            'password' => $get('post_password') !== '' ? $get('post_password') : null,
            '_extra' => $this->extractItemExtras($node, $type),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractComment(\SimpleXMLElement $commentNode, int $postId): array
    {
        $commentNode->registerXPathNamespace('wp', $this->resolveWpNamespace($commentNode));
        $get = static fn (string $field): string => trim((string) ($commentNode->xpath('wp:' . $field)[0] ?? ''));

        $approvedRaw = $get('comment_approved');
        $approved = $approvedRaw === '1';

        $parent = (int) $get('comment_parent');
        $userId = (int) $get('comment_user_id');

        $extra = [];
        if (in_array($approvedRaw, ['0', 'spam', 'trash'], true)) {
            $extra['approved_raw'] = $approvedRaw;
        }

        return [
            'id' => (int) $get('comment_id'),
            'post_id' => $postId,
            'parent_id' => $parent !== 0 ? $parent : null,
            'author' => $get('comment_author'),
            'author_email' => $get('comment_author_email') !== '' ? $get('comment_author_email') : null,
            'author_url' => $get('comment_author_url') !== '' ? $get('comment_author_url') : null,
            'author_ip' => $get('comment_author_IP') !== '' ? $get('comment_author_IP') : null,
            'content' => $get('comment_content'),
            'published_at' => $get('comment_date_gmt') !== '' ? $get('comment_date_gmt') : $get('comment_date'),
            'approved' => $approved,
            'comment_type' => $get('comment_type'),
            'user_login' => $userId !== 0 ? (string) $userId : null,
            '_extra' => $extra,
        ];
    }

    /**
     * Capture unmapped namespaced child elements as opaque XML strings under
     * `_extra` so plugin-injected data (WooCommerce, Yoast, etc.) round-trips
     * without failing the parser.
     *
     * @param list<string> $excludeWpFields wp-namespaced fields that have already
     *     been extracted into typed slots
     * @return array<string, mixed>
     */
    private function extractExtras(\SimpleXMLElement $node, array $excludeWpFields): array
    {
        $extra = [];

        $namespaces = $node->getDocNamespaces(true, false);
        foreach ($namespaces as $prefix => $uri) {
            if ($prefix === '' || $prefix === 'wp') {
                continue;
            }

            foreach ($node->children($uri) as $child) {
                $localName = $child->getName();
                $extra[$prefix . ':' . $localName] = trim((string) $child);
            }
        }

        // Capture wp-namespaced fields that aren't in the typed schema.
        $wpNs = $this->resolveWpNamespace($node);
        foreach ($node->children($wpNs) as $child) {
            $localName = $child->getName();
            if (in_array($localName, $excludeWpFields, true)) {
                continue;
            }
            $extra['wp:' . $localName] = trim((string) $child);
        }

        return $extra;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractItemExtras(\SimpleXMLElement $node, string $type): array
    {
        // Item extras include all wp:postmeta entries plus any plugin-namespaced elements.
        $extra = [];

        $wpNs = $this->resolveWpNamespace($node);

        $excludeWpFields = ['post_id', 'post_type', 'post_name', 'status', 'post_date_gmt', 'post_date', 'post_modified_gmt', 'post_modified', 'post_parent', 'comment_status', 'post_password', 'comment'];

        $postMeta = [];
        foreach ($node->children($wpNs) as $child) {
            $localName = $child->getName();
            if ($localName === 'postmeta') {
                $key = trim((string) ($child->children($wpNs)->meta_key ?? ''));
                $value = trim((string) ($child->children($wpNs)->meta_value ?? ''));
                if ($key !== '') {
                    $postMeta[$key] = $value;
                }
                continue;
            }
            if (in_array($localName, $excludeWpFields, true)) {
                continue;
            }
            $extra['wp:' . $localName] = trim((string) $child);
        }

        if ($postMeta !== []) {
            $extra['postmeta'] = $postMeta;
        }

        // Plugin namespaces (anything not wp:, dc:, content:, excerpt:, default).
        $namespaces = $node->getDocNamespaces(true, false);
        $skipPrefixes = ['', 'wp', 'dc', 'content', 'excerpt'];
        foreach ($namespaces as $prefix => $uri) {
            if (in_array($prefix, $skipPrefixes, true)) {
                continue;
            }
            foreach ($node->children($uri) as $child) {
                $extra[$prefix . ':' . $child->getName()] = trim((string) $child);
            }
        }

        unset($type);
        return $extra;
    }
}
