<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Logging;

use UnitEnum;
use Throwable;
use JsonSerializable;
use DateTimeInterface;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Keeps sensitive data out of the logs: replaces the values of the fields classified as sensitive in context and
 * extra of every log record, at any depth, with ***REDACTED*** before the record reaches a handler. Registered for
 * all channels as soon as message.logging.redact_fields lists any fields.
 */
final readonly class SensitiveFieldRedactionProcessor implements ProcessorInterface
{
    public const string REDACTED = '***REDACTED***';

    /** Depth up to which a record is searched; guards against objects that refer to each other */
    private const int MAX_DEPTH = 10;

    /** @var array<array-key, true> the sensitive fields as keys */
    private array $sensitiveFields;

    /**
     * Creates the processor.
     *
     * @param list<string> $sensitiveFields names of the fields classified as sensitive
     */
    public function __construct(array $sensitiveFields)
    {
        $this->sensitiveFields = array_fill_keys($sensitiveFields, true);
    }

    /**
     * Returns the record with the sensitive fields in context and extra replaced.
     *
     * @param LogRecord $record log record
     *
     * @return LogRecord the record without sensitive values
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context, 1),
            extra: $this->redact($record->extra, 1),
        );
    }

    /**
     * Replaces the value of every sensitive key and searches the other values one level deeper.
     *
     * @param array<array-key, mixed> $data  values of one level
     * @param int                     $depth depth in the log record; context and extra are at depth 1
     *
     * @return array<array-key, mixed> the values without sensitive values
     */
    private function redact(array $data, int $depth): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = isset($this->sensitiveFields[$key])
                ? self::REDACTED
                : $this->redactNested($value, $depth + 1);
        }

        return $data;
    }

    /**
     * Searches an array or an object for sensitive fields. Exceptions, points in time, enum cases and values that are
     * neither array nor object stay unchanged; whatever lies deeper than the maximum depth is replaced as a whole. An
     * object is searched through what a formatter would write of it (its JSON representation or its public
     * properties) and replaced only if a sensitive field was found in it.
     *
     * @param mixed $value value
     * @param int   $depth depth of the value in the log record
     *
     * @return mixed the value without sensitive values
     */
    private function redactNested(mixed $value, int $depth): mixed
    {
        if (
            !is_array($value)
            && (
                !is_object($value)
                || $value instanceof Throwable
                || $value instanceof DateTimeInterface
                || $value instanceof UnitEnum
            )
        ) {
            return $value;
        }

        if ($depth > self::MAX_DEPTH) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            return $this->redact($value, $depth);
        }

        try {
            $fields = $value instanceof JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
        } catch (Throwable) {
            return $value;
        }

        if (!is_array($fields)) {
            return $value;
        }

        $redacted = $this->redact($fields, $depth);

        return $redacted === $fields ? $value : [$value::class => $redacted];
    }
}
