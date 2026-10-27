<?php

declare(strict_types=1);

namespace ACAT\MessageBundle\Testing;

use JsonException;
use PHPUnit\Framework\Assert;

/**
 * Assertions for the event format: fields and their order, timestamp, structure of the delta entries including
 * masking. They check the body as it is sent to RabbitMQ.
 */
trait EventFormatAssertions
{
    /** UTC per RFC 3339 with milliseconds and the suffix Z */
    private const string TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

    /**
     * Checks a point in time in the format YYYY-MM-DDTHH:mm:ss.SSSZ.
     *
     * @param mixed  $value value from the payload
     * @param string $field name of the field for the failure message
     *
     * @return void
     */
    public static function assertEventTimestamp(mixed $value, string $field = 'timestamp'): void
    {
        Assert::assertIsString($value, sprintf('%s is not a string.', $field));
        Assert::assertMatchesRegularExpression(
            self::TIMESTAMP_PATTERN,
            $value,
            sprintf('%s is not in the format YYYY-MM-DDTHH:mm:ss.SSSZ.', $field),
        );
    }

    /**
     * Checks <entity>.created: exactly id, actorId, timestamp in this order.
     *
     * @param string $body body of the message
     *
     * @return array<string, mixed> the payload
     */
    public static function assertCreatedEvent(string $body): array
    {
        $payload = self::decodeEventBody($body);

        Assert::assertSame(['id', 'actorId', 'timestamp'], array_keys($payload), 'Fields of created');
        self::assertNonEmptyString($payload['id'], 'id');
        self::assertNonEmptyString($payload['actorId'], 'actorId');
        self::assertEventTimestamp($payload['timestamp']);

        return $payload;
    }

    /**
     * Checks <entity>.updated: exactly id, actorId, timestamp, delta in this order, delta not empty and every entry
     * as described for assertDeltaEntry.
     *
     * @param string $body body of the message
     *
     * @return array<string, mixed> the payload
     */
    public static function assertUpdatedEvent(string $body): array
    {
        $payload = self::decodeEventBody($body);

        Assert::assertSame(['id', 'actorId', 'timestamp', 'delta'], array_keys($payload), 'Fields of updated');
        self::assertNonEmptyString($payload['id'], 'id');
        self::assertNonEmptyString($payload['actorId'], 'actorId');
        self::assertEventTimestamp($payload['timestamp']);
        Assert::assertIsList($payload['delta'], 'delta is not a list.');
        Assert::assertNotEmpty($payload['delta'], 'delta is empty.');

        foreach ($payload['delta'] as $entry) {
            self::assertDeltaEntry($entry);
        }

        return $payload;
    }

    /**
     * Checks an entry in delta: field and operation; without masking oldValue and newValue, oldValue null for added,
     * newValue null for removed; for a masked change of a field (changed) only field, operation and masked: true.
     *
     * @param mixed $entry entry from delta
     *
     * @return void
     */
    public static function assertDeltaEntry(mixed $entry): void
    {
        Assert::assertIsArray($entry, 'An entry in delta is not an object.');
        self::assertNonEmptyString($entry['field'] ?? null, 'field');
        Assert::assertContains($entry['operation'] ?? null, ['changed', 'added', 'removed'], 'operation');

        $masked = array_key_exists('masked', $entry);

        if ($masked) {
            Assert::assertTrue($entry['masked'], 'masked is not true.');
        }

        if ($masked && $entry['operation'] === 'changed') {
            Assert::assertSame(['field', 'operation', 'masked'], array_keys($entry), 'Fields of a masked entry');

            return;
        }

        $keys = ['field', 'operation', 'oldValue', 'newValue'];
        Assert::assertSame($masked ? [...$keys, 'masked'] : $keys, array_keys($entry), 'Fields of an entry in delta');

        if ($entry['operation'] === 'added') {
            Assert::assertNull($entry['oldValue'], 'oldValue is not null for added.');
        }

        if ($entry['operation'] === 'removed') {
            Assert::assertNull($entry['newValue'], 'newValue is not null for removed.');
        }
    }

    /**
     * Checks <entity>.deleted: exactly id, timestamp, ticketId in this order.
     *
     * @param string $body body of the message
     *
     * @return array<string, mixed> the payload
     */
    public static function assertDeletedEvent(string $body): array
    {
        $payload = self::decodeEventBody($body);

        Assert::assertSame(['id', 'timestamp', 'ticketId'], array_keys($payload), 'Fields of deleted');
        self::assertFeedbackFields($payload);

        return $payload;
    }

    /**
     * Checks <entity>.deletion.failed: exactly id, timestamp, ticketId, reason in this order.
     *
     * @param string $body body of the message
     *
     * @return array<string, mixed> the payload
     */
    public static function assertDeletionFailedEvent(string $body): array
    {
        $payload = self::decodeEventBody($body);

        Assert::assertSame(['id', 'timestamp', 'ticketId', 'reason'], array_keys($payload), 'Fields of deletion.failed');
        self::assertFeedbackFields($payload);
        self::assertNonEmptyString($payload['reason'], 'reason');

        return $payload;
    }

    /**
     * Checks <subject>.deletion.processed: first id, timestamp, ticketId; service-specific fields follow.
     *
     * @param string $body body of the message
     *
     * @return array<string, mixed> the payload
     */
    public static function assertDeletionProcessedEvent(string $body): array
    {
        $payload = self::decodeEventBody($body);

        Assert::assertSame(
            ['id', 'timestamp', 'ticketId'],
            array_slice(array_keys($payload), 0, 3),
            'First fields of deletion.processed',
        );
        self::assertFeedbackFields($payload);

        return $payload;
    }

    /**
     * Checks the common fields of a feedback on a deletion request; actorId is not one of them.
     *
     * @param array<string, mixed> $payload payload of the feedback
     *
     * @return void
     */
    private static function assertFeedbackFields(array $payload): void
    {
        self::assertNonEmptyString($payload['id'], 'id');
        self::assertEventTimestamp($payload['timestamp']);
        self::assertNonEmptyString($payload['ticketId'], 'ticketId');
        Assert::assertArrayNotHasKey('actorId', $payload, 'A feedback carries no actorId.');
    }

    /**
     * Checks that a value is a non-empty string.
     *
     * @param mixed  $value value
     * @param string $field name of the field for the failure message
     *
     * @return void
     */
    private static function assertNonEmptyString(mixed $value, string $field): void
    {
        Assert::assertIsString($value, sprintf('%s is not a string.', $field));
        Assert::assertNotSame('', $value, sprintf('%s is empty.', $field));
    }

    /**
     * Decodes the body as a JSON object; the order of the fields is kept.
     *
     * @param string $body body of the message
     *
     * @return array<string, mixed> the payload
     */
    private static function decodeEventBody(string $body): array
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Assert::fail('The body is not valid JSON: ' . $exception->getMessage());
        }

        Assert::assertIsArray($payload, 'The body is not a JSON object.');
        Assert::assertFalse(array_is_list($payload) && $payload !== [], 'The body is not a JSON object.');

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
