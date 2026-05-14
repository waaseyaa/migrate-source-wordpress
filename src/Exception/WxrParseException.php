<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Exception;

/**
 * Thrown when the WXR parser cannot proceed.
 *
 * Per the M-002 substrate's exception contract (FR-036), substrate exceptions
 * are extended "where possible". `Waaseyaa\Migration\Exception\SourceReadException`
 * is declared `final` and requires `sourceId` + `migrationId` at construction
 * time — neither is knowable inside the WXR parser before a record has been
 * yielded — so this exception stands alone. The caller (a `SourcePluginInterface`
 * implementation) is responsible for catching this and re-throwing as
 * `SourceReadException` with the appropriate sourceId/migrationId context if
 * the runner needs that surface.
 *
 * Stable codes (charter §4.4):
 * - {@see self::CODE_UNSUPPORTED_VERSION}
 * - {@see self::CODE_RECORD_PARSE_FAILURE}
 * - {@see self::CODE_FILE_NOT_FOUND}
 * - {@see self::CODE_FILE_NOT_READABLE}
 *
 * @api
 */
final class WxrParseException extends \RuntimeException
{
    public const string CODE_UNSUPPORTED_VERSION = 'wxr.unsupported_version';
    public const string CODE_RECORD_PARSE_FAILURE = 'wxr.record_parse_failure';
    public const string CODE_FILE_NOT_FOUND = 'wxr.file_not_found';
    public const string CODE_FILE_NOT_READABLE = 'wxr.file_not_readable';

    /** @var list<string> */
    public readonly array $libxmlErrors;

    /**
     * @param list<string> $libxmlErrors
     */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        array $libxmlErrors = [],
    ) {
        parent::__construct($message);
        $this->libxmlErrors = $libxmlErrors;
    }

    public static function unsupportedVersion(string $raw): self
    {
        return new self(
            self::CODE_UNSUPPORTED_VERSION,
            sprintf('Unsupported WXR version "%s"; expected one of: 1.0, 1.1, 1.2', $raw),
        );
    }

    /**
     * @param list<\LibXMLError> $errors
     */
    public static function recordParseFailure(int $recordIndex, array $errors): self
    {
        $messages = array_map(
            static fn (\LibXMLError $e): string => sprintf(
                'line %d col %d: %s',
                $e->line,
                $e->column,
                trim($e->message),
            ),
            $errors,
        );

        return new self(
            self::CODE_RECORD_PARSE_FAILURE,
            sprintf(
                'WXR record #%d failed to parse: %s',
                $recordIndex,
                $messages === [] ? 'unknown libxml error' : implode('; ', $messages),
            ),
            $messages,
        );
    }

    public static function fileNotFound(string $path): self
    {
        return new self(
            self::CODE_FILE_NOT_FOUND,
            sprintf('WXR file not found: %s', $path),
        );
    }

    public static function fileNotReadable(string $path): self
    {
        return new self(
            self::CODE_FILE_NOT_READABLE,
            sprintf('WXR file is not readable: %s', $path),
        );
    }
}
